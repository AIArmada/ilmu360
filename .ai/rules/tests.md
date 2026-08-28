---
paths:
  - 'tests/**'
---

# Tests

## Pest 5 toolchain

This project uses Pest 5 with these development tools already required in `composer.json`:

- `pestphp/pest-plugin-agent` for one-off agent verification probes.
- `pestphp/pest-plugin-phpstan` for Pest-aware PHPStan analysis.
- `pestphp/pest-plugin-rector` plus `rector/rector` for Pest refactoring.
- `pestphp/pest-plugin-browser` for real-browser checks used by the agent plugin.

If bootstrapping these dependencies in another checkout, use the Pest 5 packages explicitly:

```bash
composer require pestphp/pest-plugin-agent --dev
composer require pestphp/pest-plugin-phpstan --dev
composer require pestphp/pest-plugin-rector --dev
composer require rector/rector --dev
```

Do not add compatibility packages or downgrade to a pre-Pest-5 toolchain.

## Test Impact Analysis

TIA is configured in `tests/Pest.php` with `pest()->tia()->locally()`. It requires PCOV or Xdebug in coverage mode. Always use the repository `./pest` wrapper for TIA; it sets `XDEBUG_MODE=coverage` because Herd's normal PHP configuration keeps Xdebug disabled for site requests.

```bash
# Re-run affected tests and replay unaffected tests from the TIA cache
./pest --parallel --tia --compact

# Run only affected test files
./pest --parallel --tia --filtered --compact

# Rebuild the dependency graph when the baseline is intentionally stale
./pest --parallel --tia --fresh --compact
```

Bare `vendor/bin/pest --tia` can skip TIA when the coverage driver is disabled. All tests must be Pest-style; TIA aborts on PHPUnit-class tests, so convert them rather than deleting them.

## Agent plugin

Use the Agent plugin for a focused behavioral check that does not warrant a permanent test file. Load the `pest-plugin-agent` skill first, use fully qualified class names, and wrap the snippet in single outer quotes so the shell does not interpolate PHP variables:

```bash
vendor/bin/pest --agent='$user = \App\Models\User::factory()->create(); expect($user->exists)->toBeTrue();'
```

Use normal Pest tests for durable regression coverage. For browser behavior, the snippet may use `visit()` because the browser plugin and Playwright are installed; assert the visible result and call `assertNoJavaScriptErrors()` when appropriate.

## PHPStan and Rector plugins

The Pest PHPStan extension is included by `phpstan.neon`. Run the repository analysis after PHP changes:

```bash
vendor/bin/phpstan analyse --ansi
```

The Pest Rector set is registered in `rector.php` through `PestSetList::CODING_STYLE`. Inspect automated changes with a dry run before applying them:

```bash
vendor/bin/rector process --dry-run
vendor/bin/rector process
```

Do not run Rector blindly over unrelated code; review its diff and keep the change scoped to the requested refactor.
