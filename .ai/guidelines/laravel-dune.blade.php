## Laravel Dune

This application follows **Laravel Dune**, a deterministic, feature-owned architecture built on Laravel's native container, Eloquent, transactions, queues, and Boost MCP tooling.

Core rule: **Make the locally obvious change the globally correct change. Unsafe shortcuts fail mechanically with errors naming the supported owner.**

### The Five Architectural Laws

1. **Conventional path requires fewer decisions:** Inject concrete feature `Actions` directly via Laravel's container. Add `Api/` interfaces only for real cross-feature or external boundaries.
2. **Forbidden dependencies fail mechanically:** `php tools/architecture-check.php` (AST) and Larastan `OwnedWriteRule` enforce boundaries and single-writer ownership in CI. Never bypass them with suppressions.
3. **Every durable value has one obvious owner:** Each `table:*` and `store:*` belongs to exactly one feature in `app/Features/<Name>/feature.json`. Only listed `Actions/` or `Persistence/` classes in that feature may mutate it.
4. **New product work adds isolated files, not branches in shared roots:** Place routes, views, migrations, and domain `Extensions/` inside the owning feature folder so `PlatformServiceProvider` discovers them automatically.
5. **Exceptions are narrow, explicit, and expiry-dated:** Architecture exceptions live exclusively in `tools/architecture.json` with exact file, exact target, reason, owner, and expiry date (never wildcards).

### Five Public Nouns & Directory Boundaries

- **Framework Core (`app/Platform/`):** Generic discovery, `FeatureModule`, `ExtensionRegistry`, strict runtime defaults. **Never imports `app/Features/` or `app/Host/`.**
- **Feature (`app/Features/<Name>/`):** Owned capability folder (`feature.json`, `FeatureServiceProvider.php`, `Actions/`, `Models/`, `EntryPoints/`, `Routes/`, optional `Api/`, `Queries/`, `Extensions/`, `Persistence/`, `Views/`). Sibling features may import only another feature's `Api/` namespace.
- **Entrypoints (`Routes/{web,api,console}.php` & `EntryPoints/`):** Thin HTTP controllers, FormRequests, jobs, console commands, Livewire/Filament adapters. Validate and authorize at the boundary, then delegate immediately to an Action.
- **Extensions (`Extensions/`):** Pluggable domain units (widgets, tools, renderers, handlers) discovered via `ExtensionRegistry` instead of central `match`/`switch` blocks.
- **Host (`app/Host/`):** External SDKs, payment/LLM/mail gateways, OS/process exec, and raw infrastructure behind pure contracts in `app/Shared/Contracts/`. **Never imports concrete features.**

### Shortest Supported Write Path

@verbatim
<code-snippet name="Thin Entrypoint calling a Concrete Owning Action" lang="php">
declare(strict_types=1);

namespace App\Features\Orders\EntryPoints\Http;

use App\Features\Orders\Actions\CancelOrder;
use Illuminate\Http\JsonResponse;

final readonly class CancelOrderController
{
    public function __invoke(CancelOrderRequest $request, int $orderId, CancelOrder $cancelOrder): JsonResponse
    {
        $receipt = $cancelOrder->handle(
            orderId: $orderId,
            actorId: $request->user()->id,
            reason: $request->validated('reason'),
        );

        return new JsonResponse(['order_id' => $receipt->orderId, 'status' => $receipt->status]);
    }
}
</code-snippet>
@endverbatim

### Banned Anti-Slop Patterns in Product Code

- **No hidden Observer/model-hook mutations:** Keep write side effects in named `Actions`; observers/listeners may only delegate to an Action.
- **No cross-feature mutable Eloquent leaks:** Never return an Eloquent `Model`, `Builder`, or `Illuminate\*` runtime type from `Api/` or `app/Shared/Contracts/`. Use `final readonly` DTOs, IDs, or backed enums.
- **No explicit `$table` omissions:** Every concrete feature Eloquent model MUST declare a literal `protected $table = '...';`.
- **No imperative timing hacks:** Never call `sleep()`, `usleep()`, `time_nanosleep()`, or `Sleep::sleep()` as synchronization workarounds.
- **No `env()` outside `config/`:** Always read via `config()`.
- **No `Model::unguard()` or mass-assignment bypasses** and **no inline `@phpstan-ignore` / `phpcs:ignore` suppressions**.
- **No narrative comments (~99% ban):** Keep only legal headers, verified external vendor quirks, pure formatter ignores, public API contract docs, and RFC/issue links. Encode `DO NOT REMOVE` invariants as types, lints, or tests.

### Boost MCP & Skill Workflow

- Consult `.ai/rules/index.md` before planning or editing any file.
- Use Boost `search-docs` for version-accurate Laravel/ecosystem APIs and `database-schema` / `last-error` / `browser-logs` for evidence-backed verification.
- Record durable judgment-only rules via Boost's `record-rule` MCP tool (never edit `.ai/rules/index.md` inconsistently by hand).
- Activate on-demand skills when relevant: `laravel-dune` (feature development & bootstrap), `laravel-dune-convert` (legacy migration), `laravel-dune-verify` (runtime verification & Feature Map), `laravel-dune-correct` (turning repeated corrections into mechanical checks).
- Before declaring done, run `composer architecture:test` plus affected Pest/PHPUnit tests—**"it compiles" is not evidence.**
