#!/usr/bin/env bash
set -euo pipefail

# Reusable Layer 3 Verification CLI template for Laravel apps.
# Adapt launch/seed/drive commands to your stack (Pest Browser, Playwright, Herd, Sail, or curl).

APP_URL="${VERIFY_APP_URL:-http://127.0.0.1:8099}"
ARTIFACT_DIR="${VERIFY_ARTIFACT_DIR:-storage/verify-artifacts}"
PID_FILE="${VERIFY_PID_FILE:-/tmp/arch-verify-server.pid}"
DB_FILE="${VERIFY_DB_FILE:-/tmp/arch-verify.sqlite}"

usage() {
  cat <<EOF
Usage: verify-app <command> [args]

Commands:
  launch            Migrate & seed disposable DB, boot app, wait until usable
  doctor            Read-only health check (process, HTTP 200, git rev, DB)
  http <M> <path>   Issue an HTTP request and emit JSON status + body snippet
  cache-check       Verify platform:cache, route:cache, config:cache cleanly, then clear
  cleanup           Stop only the server started by this CLI (preserves artifacts)
  --help            Show this message
EOF
}

cmd_launch() {
  mkdir -p "$ARTIFACT_DIR"
  rm -f "$DB_FILE"
  touch "$DB_FILE"
  DB_CONNECTION=sqlite DB_DATABASE="$DB_FILE" php artisan migrate:fresh --seed --force >/dev/null
  DB_CONNECTION=sqlite DB_DATABASE="$DB_FILE" php artisan serve --host=127.0.0.1 --port="${APP_URL##*:}" >/tmp/arch-verify-serve.log 2>&1 &
  echo $! > "$PID_FILE"
  for _ in $(seq 1 30); do
    if curl -fsS "$APP_URL/up" >/dev/null 2>&1 || curl -fsS "$APP_URL" >/dev/null 2>&1; then
      printf '{"status":"ready","url":"%s","pid":%s,"db":"%s"}\n' "$APP_URL" "$(cat "$PID_FILE")" "$DB_FILE"
      return 0
    fi
    sleep 0.25
  done
  echo '{"status":"failed","error":"App did not become ready. Inspect /tmp/arch-verify-serve.log"}' >&2
  cmd_cleanup || true
  exit 1
}

cmd_doctor() {
  local rev pid_ok="false" http_code="000"
  rev="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
  if [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    pid_ok="true"
  fi
  http_code="$(curl -sS -o /dev/null -w '%{http_code}' "$APP_URL/up" 2>/dev/null || echo 000)"
  printf '{"url":"%s","git_rev":"%s","managed_pid_alive":%s,"http_up_code":"%s","db_exists":%s}\n' \
    "$APP_URL" "$rev" "$pid_ok" "$http_code" "$([[ -f "$DB_FILE" ]] && echo true || echo false)"
  [[ "$pid_ok" == "true" && "$http_code" =~ ^[23] ]]
}

cmd_http() {
  local method="${1:-GET}" path="${2:-/}"
  local out code
  out="$(mktemp)"
  code="$(curl -sS -X "$method" -H 'Accept: application/json' -o "$out" -w '%{http_code}' "${APP_URL}${path}")"
  printf '{"method":"%s","path":"%s","status":%s,"body":%s}\n' \
    "$method" "$path" "$code" "$(python3 -c 'import json,sys; print(json.dumps(open(sys.argv[1]).read()[:2000]))' "$out")"
  rm -f "$out"
}

cmd_cache_check() {
  php artisan optimize:clear >/dev/null
  php artisan platform:cache
  php artisan route:cache
  php artisan config:cache
  php artisan optimize:clear >/dev/null
  echo '{"cache_check":"passed"}'
}

cmd_cleanup() {
  if [[ -f "$PID_FILE" ]]; then
    local pid
    pid="$(cat "$PID_FILE")"
    if kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
  fi
  rm -f "$DB_FILE"
  printf '{"cleaned":true,"artifacts_preserved":"%s"}\n' "$ARTIFACT_DIR"
}

case "${1:---help}" in
  launch) shift; cmd_launch "$@" ;;
  doctor) shift; cmd_doctor "$@" ;;
  http) shift; cmd_http "$@" ;;
  cache-check) shift; cmd_cache_check "$@" ;;
  cleanup) shift; cmd_cleanup "$@" ;;
  -h|--help|help) usage ;;
  *) echo "Unknown command: $1. Run 'verify-app --help'." >&2; exit 2 ;;
esac
