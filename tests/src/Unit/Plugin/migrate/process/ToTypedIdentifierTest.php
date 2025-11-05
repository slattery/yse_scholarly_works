<?php

namespace Drupal\Tests\yse_scholarly_works\Unit\Plugin\migrate\process;

use Drupal\yse_scholarly_works\Plugin\migrate\process\ToTypedIdentifier;
use Drupal\Tests\migrate\Unit\MigrateTestCase;
use Drupal\migrate\Row;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests for the ToTypedIdentifier migrate process plugin.
 *
 * @group yse_scholarly_works
 * @coversDefaultClass \Drupal\yse_scholarly_works\Plugin\migrate\process\ToTypedIdentifier
 */
class ToTypedIdentifierTest extends MigrateTestCase {

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
  }

  /**
   * Creates a plugin instance for testing.
   *
   * @param array $configuration
   *   Plugin configuration.
   *
   * @return \Drupal\yse_scholarly_works\Plugin\migrate\process\ToTypedIdentifier
   *   Plugin instance.
   */
  protected function createPlugin(array $configuration = []): ToTypedIdentifier {
    return new ToTypedIdentifier(
      $configuration,
      'to_typed_identifier',
      [],
      $this->entityTypeManager
    );
  }

  /**
   * Test flat object with excluded keys.
   */
  public function testFlatObjectWithExcludedKeys(): void {
    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => ['mag'],
      'check_field_settings' => FALSE,
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'doi' => 'https://doi.org/10.1234/test',
      'mag' => '12345',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    $this->assertCount(2, $result);
    $this->assertEquals('openalex', $result[0]['itemtype']);
    $this->assertEquals('https://openalex.org/W123', $result[0]['itemvalue']);
    $this->assertEquals('doi', $result[1]['itemtype']);
    $this->assertEquals('https://doi.org/10.1234/test', $result[1]['itemvalue']);

    // Verify mag is excluded.
    foreach ($result as $item) {
      $this->assertNotEquals('mag', $item['itemtype']);
    }
  }

  /**
   * Test nested array with excluded fields.
   */
  public function testNestedArrayWithExcludedFields(): void {
    $configuration = [
      'is_nested' => TRUE,
      'exclude_keys' => ['display_name', 'author_position'],
      'check_field_settings' => FALSE,
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      [
        'author_position' => 'first',
        'openalex' => 'https://openalex.org/A123',
        'display_name' => 'John Doe',
        'orcid' => 'https://orcid.org/0000-0000-0000-0001',
      ],
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    $this->assertCount(2, $result);

    // Verify excluded fields are not present.
    foreach ($result as $item) {
      $this->assertNotEquals('display_name', $item['itemtype']);
      $this->assertNotEquals('author_position', $item['itemtype']);
    }

    // Verify included fields are present.
    $itemtypes = array_column($result, 'itemtype');
    $this->assertContains('openalex', $itemtypes);
    $this->assertContains('orcid', $itemtypes);
  }

  /**
   * Test empty input returns empty array.
   */
  public function testEmptyInputReturnsEmpty(): void {
    $configuration = ['is_nested' => FALSE];
    $plugin = $this->createPlugin($configuration);

    $result = $plugin->transform(NULL, NULL, new Row([], []), 'field_test');
    $this->assertEmpty($result);

    $result = $plugin->transform([], NULL, new Row([], []), 'field_test');
    $this->assertEmpty($result);
  }

  /**
   * Test empty values are skipped.
   */
  public function testEmptyValuesAreSkipped(): void {
    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => FALSE,
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'doi' => NULL,
      'pmid' => '',
      'pmcid' => 'https://www.ncbi.nlm.nih.gov/pmc/articles/123',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should have 2 items (openalex and pmcid).
    $this->assertCount(2, $result);

    $itemtypes = array_column($result, 'itemtype');
    $this->assertContains('openalex', $itemtypes);
    $this->assertContains('pmcid', $itemtypes);
    $this->assertNotContains('doi', $itemtypes);
    $this->assertNotContains('pmid', $itemtypes);
  }

  /**
   * Test multiple nested objects.
   */
  public function testMultipleNestedObjects(): void {
    $configuration = [
      'is_nested' => TRUE,
      'exclude_keys' => [],
      'check_field_settings' => FALSE,
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      [
        'openalex' => 'https://openalex.org/A123',
        'orcid' => 'https://orcid.org/0000-0000-0000-0001',
      ],
      [
        'openalex' => 'https://openalex.org/A456',
        'orcid' => NULL,
      ],
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should have 3 items (2 openalex + 1 orcid).
    $this->assertCount(3, $result);

    $openalex_items = array_filter($result, fn($item) => $item['itemtype'] === 'openalex');
    $this->assertCount(2, $openalex_items);
  }

  /**
   * Test field settings validation.
   */
  public function testFieldSettingsValidation(): void {
    // Mock field config storage.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $field_config = $this->createMock(FieldConfig::class);
    $field_config->method('getSettings')->willReturn([
      'allowed_identifier_types' => ['openalex', 'doi', 'generic'],
    ]);
    $storage->method('load')->willReturn($field_config);

    $this->entityTypeManager->method('getStorage')->willReturn($storage);

    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => TRUE,
      'use_generic_fallback' => FALSE,
      'destination_field' => 'field_work_typed_ids',
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'doi' => 'https://doi.org/10.1234/test',
      'pmid' => 'https://pubmed.ncbi.nlm.nih.gov/34253230',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should have 2 items (openalex and doi - pmid not in allowed types).
    $this->assertCount(2, $result);

    $itemtypes = array_column($result, 'itemtype');
    $this->assertContains('openalex', $itemtypes);
    $this->assertContains('doi', $itemtypes);
    $this->assertNotContains('pmid', $itemtypes);
  }

  /**
   * Test generic fallback transformation.
   */
  public function testGenericFallbackTransformation(): void {
    // Mock field config storage.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $field_config = $this->createMock(FieldConfig::class);
    $field_config->method('getSettings')->willReturn([
      'allowed_identifier_types' => ['openalex', 'doi', 'generic'],
    ]);
    $storage->method('load')->willReturn($field_config);

    $this->entityTypeManager->method('getStorage')->willReturn($storage);

    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => TRUE,
      'use_generic_fallback' => TRUE,
      'destination_field' => 'field_work_typed_ids',
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'doi' => 'https://doi.org/10.1234/test',
      'datacite' => '10.1234/example.5678',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should have 3 items.
    $this->assertCount(3, $result);

    $itemtypes = array_column($result, 'itemtype');
    $this->assertContains('openalex', $itemtypes);
    $this->assertContains('doi', $itemtypes);
    $this->assertContains('generic:datacite', $itemtypes);

    // Verify the generic:datacite value.
    $generic_item = array_filter($result, fn($item) => $item['itemtype'] === 'generic:datacite');
    $this->assertCount(1, $generic_item);
    $generic_item = array_values($generic_item)[0];
    $this->assertEquals('10.1234/example.5678', $generic_item['itemvalue']);
  }

  /**
   * Test generic fallback with multiple unknown types.
   */
  public function testGenericFallbackWithMultipleUnknownTypes(): void {
    // Mock field config storage.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $field_config = $this->createMock(FieldConfig::class);
    $field_config->method('getSettings')->willReturn([
      'allowed_identifier_types' => ['openalex', 'orcid', 'generic'],
    ]);
    $storage->method('load')->willReturn($field_config);

    $this->entityTypeManager->method('getStorage')->willReturn($storage);

    $configuration = [
      'is_nested' => TRUE,
      'exclude_keys' => [],
      'check_field_settings' => TRUE,
      'use_generic_fallback' => TRUE,
      'destination_field' => 'field_authors_typed_ids',
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      [
        'openalex' => 'https://openalex.org/A123',
        'orcid' => 'https://orcid.org/0000-0000-0000-0001',
        'custom_id' => 'univ-12345',
        'wosid' => 'A-1234-2020',
      ],
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    $this->assertCount(4, $result);

    $itemtypes = array_column($result, 'itemtype');
    $this->assertContains('openalex', $itemtypes);
    $this->assertContains('orcid', $itemtypes);
    $this->assertContains('generic:custom_id', $itemtypes);
    $this->assertContains('generic:wosid', $itemtypes);
  }

  /**
   * Test skipped unknown types when generic fallback is disabled.
   */
  public function testUnknownTypesSkippedWithoutFallback(): void {
    // Mock field config storage.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $field_config = $this->createMock(FieldConfig::class);
    $field_config->method('getSettings')->willReturn([
      'allowed_identifier_types' => ['openalex', 'doi'],
    ]);
    $storage->method('load')->willReturn($field_config);

    $this->entityTypeManager->method('getStorage')->willReturn($storage);

    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => TRUE,
      'use_generic_fallback' => FALSE,
      'destination_field' => 'field_work_typed_ids',
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'datacite' => '10.1234/example.5678',
      'pmid' => 'https://pubmed.ncbi.nlm.nih.gov/34253230',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should have 1 item (only openalex).
    $this->assertCount(1, $result);
    $this->assertEquals('openalex', $result[0]['itemtype']);
  }

  /**
   * Test field settings not found.
   */
  public function testFieldSettingsNotFound(): void {
    // Mock field config storage to return NULL.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $this->entityTypeManager->method('getStorage')->willReturn($storage);

    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => TRUE,
      'use_generic_fallback' => TRUE,
      'destination_field' => 'field_nonexistent',
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'doi' => 'https://doi.org/10.1234/test',
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    // Should return empty since allowed_types is empty and generic_allowed is false.
    $this->assertEmpty($result);
  }

  /**
   * Test value type conversion.
   */
  public function testValueTypeConversion(): void {
    $configuration = [
      'is_nested' => FALSE,
      'exclude_keys' => [],
      'check_field_settings' => FALSE,
    ];
    $plugin = $this->createPlugin($configuration);

    $input = [
      'openalex' => 'https://openalex.org/W123',
      'year' => 2021,
      'score' => 0.95,
    ];

    $result = $plugin->transform($input, NULL, new Row([], []), 'field_test');

    $this->assertCount(3, $result);

    // All values should be strings.
    foreach ($result as $item) {
      $this->assertIsString($item['itemvalue']);
    }

    // Check specific conversions.
    $year_item = array_filter($result, fn($item) => $item['itemtype'] === 'year');
    $year_item = array_values($year_item)[0];
    $this->assertEquals('2021', $year_item['itemvalue']);

    $score_item = array_filter($result, fn($item) => $item['itemtype'] === 'score');
    $score_item = array_values($score_item)[0];
    $this->assertEquals('0.95', $score_item['itemvalue']);
  }

}
