# Event Legacy Removal Plan

## Objective
Remove the legacy accessor/mutator layer from `App\Models\Event`, making all code use package-native paths for event data.

## Strategy
Remove the magic layer first, then fix all resulting errors. This ensures no hidden callers are missed.

## Phase 1: Remove Event.php legacy machinery

### Delete from Event.php:
1. `setAttribute()` / `getAttribute()` overrides (lines 357-429)
2. `pendingPrimaryOccurrence` + `syncPrimaryOccurrenceFromPendingState()` (lines 168, 1056-1096)
3. `pendingLinkWrites` + URL accessors + `syncUrlLinks()` (lines 197, 582-656)
4. `pendingTimeExpressionWrites` + prayer accessors + `syncTimeExpressions()` (lines 200, 660-781)
5. `pendingAudienceWrites` + `pendingAudienceProfileWrites` + audience accessors + `syncAudiences()` (lines 209, 785-898)
6. `pendingAttributeWrites` + attribute accessors + `syncAttributes()` (lines 219, 902-978)
7. `pendingLocationWrites` + space_id accessors + `syncLocation()` (lines 224, 982-1018)
8. `MetadataBackedAttributes` + helper methods (setMetadataValue, legacyMetadataValue, dateFromMetadata, metadataSerializableValue, enumValue, firstEnumValue, primaryOccurrenceDate, occurrenceStatusValue)
9. `saved` event callback in `booted()` (lines 231-238)
10. Legacy `@property` entries from docblock
11. Legacy `$fillable` entries not on package model

### Delete from EventBuilder.php:
12. All column mapping logic (whole file is 473 lines of remapping)

## Phase 2: Add proper relationships
13. `primaryOccurrence(): HasOne` — first occurrence ordered by starts_at
14. `primaryLocation(): HasOne` — first location

## Phase 3: Fix all call sites field by field

### Simple renames:
| Old | New | Sites |
|-----|-----|-------|
| `venue_id` | `default_venue_id` | ~50 |
| `event_format` | `delivery_mode` | ~60 |
| `event_type` | `type` | ~80 |

### Sub-model reads:
| Old | New | Sites |
|-----|-----|-------|
| `starts_at` | `primaryOccurrence?->starts_at` | ~100 |
| `ends_at` | `primaryOccurrence?->ends_at` | ~40 |
| `space_id` | `primaryLocation?->venue_space_id` | ~25 |

### Query remapping (EventBuilder replacement):
| Old | New |
|-----|-----|
| `->where('starts_at', ...)` | `->whereRelation('occurrences', 'starts_at', ...)` or subquery in orderBy |
| `->whereBetween('starts_at', ...)` | subquery |
| `->orderBy('starts_at')` | orderBy subquery |
| `->where('venue_id', ...)` | `->where('default_venue_id', ...)` |
| `->where('event_format', ...)` | `->where('delivery_mode', ...)` |
| `->where('event_type', ...)` | `->whereJsonContains('metadata->event_type', ...)` |
