# Feature ownership: Directory is navigation, not a domain

The Filament **Directory** sidebar group is retained without a corresponding runtime `Directory` feature. The group spans root Filament resources; its label, order, permissions, URLs and membership do not control table ownership.

| Feature | Owned data and responsibilities |
| --- | --- |
| Institutions | Institutions, names, import exclusions and institution–venue role/primary links; submission access, search/cache |
| Persons | Persons, names, affiliations, person search terms; submission access and cache |
| References | Reference editions/families and contributors; reference search and homepage count |
| Places | Venues, venue-owned rooms, catalog spaces, facilities, and institution–space capacity links |
| DonationChannels | Recipient donation channels and QR media (including institution form synchronisation) |

Cross-feature writers use the owners' `Api/` contracts. Place deletion calls the Institutions venue-link API; Institution deletion calls the Places institution-space API. Reports and membership use dedicated owner lookups through root-level adapters rather than a shared domain router. Identity preserves the existing `directory` lifecycle snapshot key and its five historical verifier-ID fields. Cache keys and runtime morph aliases remain unchanged. Canonical models stay in `App\Models`.

The refactor introduces no migrations, model alias changes, schema changes or permission changes.
