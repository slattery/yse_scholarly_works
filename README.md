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

### Key Features
- **Typed Identifiers:** Using `typed_identifier` field for flexible identifier storage
- **Author Relationships:** Join profiles and works via typed_identifier matching
- **License Awareness:** Abstract display respects copyright restrictions
- **View Modes:** Optimized rendering for different contexts (full, card, list, search)

### Dependencies
- **Required:** typed_identifier module (web/modules/custom)
- **Migration:** migrate_plus, migrate_conditions

