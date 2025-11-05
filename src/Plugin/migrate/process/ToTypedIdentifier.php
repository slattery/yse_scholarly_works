<?php

namespace Drupal\yse_scholarly_works\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\typed_identifier\Service\IdentifierTypeParser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unified plugin to transform objects/arrays to typed_identifier items.
 *
 * This plugin handles both flat objects (work IDs) and nested arrays
 * (authorships), with field-aware validation and generic fallback support.
 *
 * Usage for work IDs (flat object):
 * @code
 * field_work_typed_ids:
 *   plugin: to_typed_identifier
 *   source: ids
 *   is_nested: false
 *   exclude_keys:
 *     - mag
 *   check_field_settings: true
 *   use_generic_fallback: true
 *   destination_field: field_work_typed_ids
 * @endcode
 *
 * Usage for authorships (nested array):
 * @code
 * field_authors_typed_ids:
 *   plugin: to_typed_identifier
 *   source: authorships
 *   is_nested: true
 *   exclude_keys:
 *     - display_name
 *     - author_position
 *   check_field_settings: true
 *   use_generic_fallback: true
 *   destination_field: field_authors_typed_ids
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "to_typed_identifier",
 *   handle_multiples = TRUE
 * )
 */
class ToTypedIdentifier extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The identifier type parser service.
   *
   * @var \Drupal\typed_identifier\Service\IdentifierTypeParser
   */
  protected $parser;

  /**
   * Constructs a ToTypedIdentifier plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\typed_identifier\Service\IdentifierTypeParser $parser
   *   The identifier type parser service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, IdentifierTypeParser $parser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->parser = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('typed_identifier.parser')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_field_name) {
    if (empty($value)) {
      return [];
    }

    // Get configuration.
    $is_nested = $this->configuration['is_nested'] ?? FALSE;
    $exclude_keys = $this->configuration['exclude_keys'] ?? [];
    $check_field_settings = $this->configuration['check_field_settings'] ?? FALSE;
    $use_generic_fallback = $this->configuration['use_generic_fallback'] ?? FALSE;
    $destination_field = $this->configuration['destination_field'] ?? '';

    // Get allowed identifier types from field settings.
    $allowed_types = [];
    $generic_allowed = FALSE;
    if ($check_field_settings && !empty($destination_field)) {
      $allowed_types = $this->getAllowedIdentifierTypes($destination_field);
      $generic_allowed = in_array('generic', $allowed_types, TRUE);
    }

    // Normalize input to array of objects.
    $objects = $this->normalizeInput($value, $is_nested);

    // Process each object.
    $items = [];
    foreach ($objects as $object) {
      if (!is_array($object) && !is_object($object)) {
        continue;
      }

      foreach ($object as $key => $val) {
        // Skip excluded keys.
        if (in_array($key, $exclude_keys, TRUE)) {
          continue;
        }

        // Skip empty values.
        if (empty($val)) {
          continue;
        }

        // Special case: If key is 'id', use parser to auto-detect identifier type.
        if ($key === 'id' && $this->parser) {
          $parsed = $this->parser->parse((string) $val);

          if ($parsed) {
            $itemtype = $parsed['itemtype'];
            $itemvalue = $parsed['itemvalue'];

            // Validate parsed itemtype against field settings if needed.
            if ($check_field_settings) {
              if (!in_array($itemtype, $allowed_types, TRUE)) {
                // Parsed type not allowed - try generic fallback.
                if ($generic_allowed && $use_generic_fallback) {
                  $itemtype = 'generic:' . $itemtype;
                }
                else {
                  // Type not allowed and no fallback - skip this item.
                  continue;
                }
              }
            }

            $items[] = [
              'itemtype' => $itemtype,
              'itemvalue' => $itemvalue,
            ];
            continue;
          }
          // If parser failed, fall through to normal key-based logic.
        }

        // Determine itemtype from key (normal behavior).
        $itemtype = $this->determineItemtype(
          (string) $key,
          $check_field_settings,
          $allowed_types,
          $generic_allowed,
          $use_generic_fallback
        );

        // Skip if no valid itemtype determined.
        if ($itemtype === NULL) {
          continue;
        }

        $items[] = [
          'itemtype' => $itemtype,
          'itemvalue' => (string) $val,
        ];
      }
    }

    return $items;
  }

  /**
   * Normalize input to array of objects.
   *
   * @param mixed $value
   *   The input value.
   * @param bool $is_nested
   *   Whether input is already an array of objects.
   *
   * @return array
   *   Array of objects/arrays.
   */
  protected function normalizeInput($value, $is_nested) {
    if ($is_nested) {
      // Already array of objects.
      return is_array($value) ? $value : [];
    }
    else {
      // Flat object - wrap in array.
      return [$value];
    }
  }

  /**
   * Determine the itemtype for a given key.
   *
   * @param string $key
   *   The identifier key.
   * @param bool $check_field_settings
   *   Whether to validate against field settings.
   * @param array $allowed_types
   *   Allowed identifier types from field settings.
   * @param bool $generic_allowed
   *   Whether 'generic' is in allowed types.
   * @param bool $use_generic_fallback
   *   Whether to use generic:key fallback.
   *
   * @return string|null
   *   The itemtype to use, or NULL to skip.
   */
  protected function determineItemtype($key, $check_field_settings, $allowed_types, $generic_allowed, $use_generic_fallback) {
    if (!$check_field_settings) {
      // No validation - use key as-is.
      return $key;
    }

    // Check if key is in allowed types.
    if (in_array($key, $allowed_types, TRUE)) {
      return $key;
    }

    // Key not allowed - try generic fallback.
    if ($generic_allowed && $use_generic_fallback) {
      return 'generic:' . $key;
    }

    // Skip this key.
    return NULL;
  }

  /**
   * Get allowed identifier types from field settings.
   *
   * @param string $field_name
   *   The field machine name.
   *
   * @return array
   *   Array of allowed identifier types.
   */
  protected function getAllowedIdentifierTypes($field_name) {
    try {
      // Load field config for node.scholarly_work bundle.
      // TODO: Make this more dynamic if needed for other bundles.
      $field_config = $this->entityTypeManager
        ->getStorage('field_config')
        ->load('node.scholarly_work.' . $field_name);

      if ($field_config) {
        $settings = $field_config->getSettings();
        return isset($settings['allowed_identifier_types']) ? (array) $settings['allowed_identifier_types'] : [];
      }
    }
    catch (\Exception $e) {
      // Field not found or error - return empty array.
    }

    return [];
  }

}
