# Friendliness Audit (Package Extensibility)

## Extension Seams
- Put stable extension seams (contracts, hooks, resolvers, support classes) in `commerce-support` when multiple packages benefit.
- When a capability may grow variants, prefer contracts over hard-coded branching.
- Use tagged registrars or contributor interfaces for optional integrations instead of service-provider branching.

## Contracts
- Every resolvable concern gets a contract (`Contracts/`). Default implementations live in `Services/` or `Resolvers/`.
- Null-object resolvers for optional integrations (`Null*Resolver`) — ship the no-op with the contract so downstream code never conditionally checks "is this installed?".
- Workflow contracts separate policy from implementation (e.g. `EventLifecycleWorkflow` interface + `DefaultEventLifecycleWorkflow`).

## Actions
- Extract reusable Actions for orchestration that spans transactions, side effects, normalization, or multiple entrypoints.
- Keep trivial single-step handlers inline when extraction adds no clarity.
- Reuse existing Actions before creating new ones.
- Watch for Action/Service duplicates — a Backfill action and a Sync service doing the same work should be one class.

## Services
- Every service should have a clear role. Services without contracts are catch-all candidates.
- Prefer splitting a catch-all service into Actions with a thin service facade.
- Service count should be low; high service counts with no contracts is a smell.

## Support Folder
- `Support/` should not mix policy, integration wiring, and normalization.
- Split into sub-namespaces when categories emerge (`Support/Policy/`, `Support/Integration/`, `Support/Normalization/`).

## Verification
- Check for duplicate orchestration: `rg -n "function (handle|execute|process)" packages/*/src/Actions packages/*/src/Services`
- Check for services without contracts: `rg -l "class.*Service" packages/*/src/Services | xargs rg -L "implements"`
- Grep for null-object pattern adoption: `rg "Null.*Resolver|Null.*Dispatcher" packages/`
