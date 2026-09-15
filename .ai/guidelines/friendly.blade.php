# Friendliness Audit (Package Extensibility)

## Extension Seams
- Put stable extension seams (contracts, hooks, resolvers, support classes) in `commerce-support` when multiple packages benefit.
- When a capability may grow variants, prefer contracts over hard-coded branching.
- Use tagged registrars or contributor interfaces for optional integrations instead of service-provider branching.

## Contracts
- Every resolvable concern gets a contract (`Contracts/`); default implementations live in `Services/` or `Resolvers/`.
- Ship null-object resolvers (`Null*Resolver`) with the contract so downstream code never checks "is this installed?".
- Separate workflow policy from implementation (e.g. `EventLifecycleWorkflow` interface + `DefaultEventLifecycleWorkflow`).

## Actions
- Extract reusable Actions for orchestration spanning transactions, side effects, normalization, or multiple entrypoints; keep trivial single-step handlers inline.
- Reuse existing Actions before creating new ones; merge Action/Service duplicates (one Backfill/Sync implementation, not two).

## Services
- Every service needs a clear role and (usually) a contract — contract-less or numerous services are catch-all smells; split them into Actions behind a thin service facade.

## Support Folder
- Don't mix policy, integration wiring, and normalization in `Support/`; split into `Support/Policy/`, `Support/Integration/`, `Support/Normalization/` as categories emerge.

## Verification
- Duplicate orchestration: `rg -n "function (handle|execute|process)" packages/*/src/Actions packages/*/src/Services`
- Services without contracts: `rg -l "class.*Service" packages/*/src/Services | xargs rg -L "implements"`
- Null-object adoption: `rg "Null.*Resolver|Null.*Dispatcher" packages/`
