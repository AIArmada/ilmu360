# Rewards feature rules

- Rewards owns its member HTTP endpoints, authenticated dashboard page, page view, route registrations, and read-only member presentation helpers.
- Rewards package tables remain package-canonical. Any reward mutation from HTTP, Livewire, or human admin UI must call an `AIArmada\Rewards` Action; application code does not write package reward tables directly.
- Generic admin API/MCP access to reward resources is read-only. Do not add reward-specific MCP tools or generic admin write support.
- Cross-feature analytics use `ShareTracking\Api\ShareAnalytics` and scalar member identifiers. Do not import ShareTracking readers, models, or query builders into Rewards.
- Keep the public route URIs and names as the active contracts. Register them only from `Routes/`; do not leave root duplicates, aliases, wrappers, old paths, or abandoned response shapes.
- Do not add backward-compatibility code, legacy paths, data backfills, or migration leftovers. Change source directly and remove superseded code in the same cutover.
