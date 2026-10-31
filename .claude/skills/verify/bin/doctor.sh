#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$root"

pass() { printf 'ok    %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1" >&2; exit 1; }

php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' \
  || fail "PHP 8.5 or newer is required (found $(php -r 'echo PHP_VERSION;'))."
pass "PHP $(php -r 'echo PHP_VERSION;')"

[[ -f public/build/manifest.json ]] \
  || fail "public/build/manifest.json is missing. Run: npm run build"
pass "built Vite manifest present"

playwright_version="$(node -e 'console.log(require("playwright/package.json").version)' 2>/dev/null || true)"
[[ -n "$playwright_version" ]] \
  || fail "playwright is not installed. Run: npm install"
node -e 'const [major, minor] = require("playwright/package.json").version.split(".").map(Number); process.exit(major > 1 || (major === 1 && minor >= 63) ? 0 : 1)' \
  || fail "playwright ${playwright_version} is older than 1.63.0. Run: npm install playwright@latest && npx playwright install chromium"
pass "playwright ${playwright_version}"

log="$(mktemp "${TMPDIR:-/tmp}/ilmu360-verify-doctor.XXXXXX")"
trap 'rm -f "$log"' EXIT

printf 'ok    loading / in Chromium (first boot takes a few seconds)...\n'
if ! vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); visit("/", ["timeout" => 30000])->assertSee("ilmu360")->assertScript("() => Array.from(document.querySelectorAll(\"link[rel=stylesheet]\")).some(l => l.href.includes(\"/build/assets/\"))");' >"$log" 2>&1; then
  tail -n 25 "$log" >&2
  fail "browser probe failed; the harness is not safe to drive."
fi

printf 'READY  isolated app and Chromium are healthy; built CSS is linked\n'
