# YSE Scholarly Works - Documentation Index

**Module:** yse_scholarly_works
**Version:** 0.5
**Status:** Pre-launch Phase

---

## Key Concepts

### Content Type: scholarly_work
- **Bundle machine name:** scholarly_work
- **Module:** yse_scholarly_works
- **Fields:** 51 total (27 storage, 5 display-only)
- **Display modes:** 5 (full, teaser, card, item, searchidx)

### Key Capabilities
- **Typed Identifiers:** openalex, doi, pmid, pmcid, url, urn, orcid, generic
- **Author Data:** First, middle, last names with unlimited text storage
- **Author Relationships:** Join profiles and works via typed_identifier matching
- **Bibliographic Info:** Volume, issue, page ranges
- **License-Aware:** Abstract display filtered by copyright permissions (cc-by, cc-by-nc, cc-by-nc-sa, cc-by-sa, public-domain)
- **Date Handling:** Publication dates, creation/update timestamps with proper timezone support
- **SDG Tracking:** Sustainable Development Goals extracted from OpenAlex data
- **Open Access:** Status tracking and URL capture

### Dependencies
```yaml
Required Modules:
  - typed_identifier     # Flexible identifier field type
  - migrate_plus         # Extended migration framework
  - migrate_conditions   # Conditional migration processing
  - node                 # Core node system
  - field                # Core field system
  - migrate              # Core migration system
  - datetime             # Date field support

### Dependencies
- **Required:** typed_identifier module (web/modules/custom)
- **Migration:** migrate_plus, migrate_conditions

