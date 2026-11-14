# AI ownership map

## Owned persistence

| Resource | Owner | Writers | Readers |
| --- | --- | --- | --- |
| `ai_model_pricings` | AI | `CreateAiModelPricingAction`, `UpdateAiModelPricingAction`, `DeleteAiModelPricingAction`, `DeleteAiModelPricingsAction` | `AiCostResolver`, AI Filament resource |
| `ai_usage_logs` | AI | `RecordAiUsageAction`, `AiAccountLifecycleContributor` | `AiUsageLedger`, `AiCostResolver`, AI Filament resource |

The models declare literal table names. The existing migrations moved unchanged into `app/Features/Ai/Database/Migrations`; no schema migration or data rewrite was needed. Laravel AI emits usage events but does not own either app table.

## Boundaries and entrypoints

- The AI feature registers the pricing and usage Filament resources, pricing policy, account lifecycle contributor, and seven Laravel AI usage-event listeners.
- `AiBudget` is the public AI contract used by Events. Its `AiBudgetDecision` is an immutable value. Monthly cost totals, estimate handling, approval threshold, and denial reasons retain their prior behavior.
- Event poster/PDF extraction belongs to Events because its agent prompt, context, payload normalization, and result fields are event-specific. `EventMediaExtraction` accepts `EventMediaExtractionSource` and returns immutable `EventMediaExtractionResult`; AI contributes only the budget decision and Laravel AI provider execution.
- `LanguageCatalog` is a Shared read contract for language IDs and codes. Its root database adapter reads the existing language model; this map makes no claim on language tables.
- The AI policy and usage log display use `AccountActor` and `AccountDirectory` contracts instead of importing the mutable User model.
- No AI-specific API/web route, command, scheduled task, conversation store, or MCP tool exists. The Filament resources keep their routes: 3 pricing and 1 usage-log, with unchanged paths and names.

## Stored identity and hard cut

- `ai_model_pricing` remains the configured morph alias so existing polymorphic audit records continue resolving to the current model. The alias is an active stored identifier, not a second model path.
- Removed the root AI model, service, listener, policy, lifecycle contributor, Filament resources, and event agent paths after moving their callers. No forwarding class, alternate namespace, compatibility map, data backfill, or obsolete configuration keys remain.

## Verification

- `vendor/bin/pest --compact` on the seven AI, extraction, lifecycle, and admin test files: 35 passed, 361 assertions.
- `php tools/architecture-check.php`: 0 findings across 19 features.
- Pricing routes: 3; usage-log route: 1, both before and after `route:cache`.
- `config:cache` and `config:show` confirmed AI budget and Events upload settings; cache cleared afterwards.
- `event:list` shows exactly one `RecordAiUsage` listener for each of the seven Laravel AI usage events.
- `git diff --check` passed. Commit: `a7af55e3`.
