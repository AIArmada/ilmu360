# Google OAuth local-dev bridge (Cloudflare Tunnel)

Normal development stays on `https://ilmu360.test` (Herd). Google OAuth cannot
redirect to `*.test` domains, so the OAuth callback goes through
`https://dev.ilmu360.com`, which reaches this same machine over a Cloudflare
Tunnel. Nothing is deployed to a public server.

```
Browser (ilmu360.test) → Google → dev.ilmu360.com/oauth/google/callback
    → Cloudflare edge → Tunnel → Herd (127.0.0.1:443) → Laravel
    → one-time handoff → ilmu360.test/oauth/google/complete → logged in
```

## Cloudflare setup (already done)

| Item | Value |
|---|---|
| Tunnel name | `ilmu360-local-dev` |
| Tunnel ID | `be06b6f9-4540-48bb-97a3-889d55e7ef98` |
| Public hostname | `dev.ilmu360.com` |
| Ingress | `dev.ilmu360.com` + path `^/oauth/google/callback$` → `https://ilmu360.test:443` (`noTLSVerify`, `httpHostHeader: ilmu360.test`); every other path on the host → `http_status:404` at the edge, catch-all `http_status:404` |
| DNS | Proxied `CNAME dev → <tunnel-id>.cfargotunnel.com` |
| Ingress management | Remote (Cloudflare dashboard / API), `cloudflared` runs with a token |

The tunnel origin is HTTPS because Herd 301-redirects plain HTTP to HTTPS for
this secured site; the `httpHostHeader` rewrite lets Herd route the request to
the `ilmu360` site. Laravel still sees the real public host
(`dev.ilmu360.com`) via `X-Forwarded-Host`, which `cloudflared` passes through
and the app trusts on loopback only (`bootstrap/app.php`).

## Daily use

1. Start Herd as usual (`https://ilmu360.test`).
2. Start the tunnel (pick one):
   - Manual: `composer tunnel` (runs `scripts/dev-tunnel.sh`, Ctrl-C to stop).
   - Auto-start: install the LaunchAgent once (below), then it starts on login
     and restarts on failure.
3. Check status:
   - `curl -sk -o /dev/null -w "%{http_code}\n" "https://dev.ilmu360.com/oauth/google/callback?state=probe"` → `302`
     (reaches Laravel, which redirects the unknown state to the error page).
     Every other path on `dev.ilmu360.com` returns `404` at the Cloudflare
     edge by design — only the OAuth callback is forwarded to this machine.
   - Tunnel log: `~/.cloudflared/ilmu360-tunnel.log` (LaunchAgent) or the
     `composer tunnel` terminal.
   - Connections: `cloudflared tunnel list` (look for `ilmu360-local-dev`;
     uses the existing account certificate). Note: `tunnel info <name>` is
     unreliable here because the default `~/.cloudflared/config.yml` pins the
     kakkay-local tunnel, which shadows the name argument.

## LaunchAgent install (one-time, manual)

Sandbox restrictions prevented installing this automatically; run once from
the project root:

```bash
mkdir -p ~/.cloudflared ~/Library/LaunchAgents
cloudflared tunnel token ilmu360-local-dev > ~/.cloudflared/ilmu360-tunnel-token
chmod 600 ~/.cloudflared/ilmu360-tunnel-token
sed "s#/Users/Saiffil#$HOME#" scripts/launchd/com.ilmu360.cloudflared.plist \
    > ~/Library/LaunchAgents/com.ilmu360.cloudflared.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ilmu360.cloudflared.plist
```

Stop: `launchctl bootout gui/$(id -u)/com.ilmu360.cloudflared`
Start: `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ilmu360.cloudflared.plist`
Remove: bootout, then delete the plist.

A fallback token also exists at `.cloudflared/ilmu360-tunnel-token` in the
project (gitignored, `0600`); `scripts/dev-tunnel.sh` uses `~/.cloudflared`
first and falls back to it. Never commit either file.

## Environment variables

```bash
GOOGLE_CLIENT_ID=...            # already set
GOOGLE_CLIENT_SECRET=...        # already set
GOOGLE_REDIRECT_URI=https://dev.ilmu360.com/oauth/google/callback
```

`config/services.php` already reads `GOOGLE_REDIRECT_URI` (falling back to
`APP_URL/oauth/google/callback`). Production keeps working by setting its own
`GOOGLE_REDIRECT_URI` (or nothing, if `APP_URL` is the production URL).

## Google Cloud Console (manual step)

In the OAuth 2.0 Web client used for development:

- Authorized redirect URI: `https://dev.ilmu360.com/oauth/google/callback`
- Authorized JavaScript origins: not needed (server-side flow).

Do not add `https://ilmu360.test/...` — Google rejects `.test` redirect URIs.
For production later, additionally register
`https://ilmu360.com/oauth/google/callback` (same client or a separate one).

## How the flow works

`SocialiteProviderConfiguration::usesCrossDomainCallback('google')` compares the
configured redirect host with `APP_URL`. When they differ (local dev), the
callback takes the bridge path; when they match (production), the original
same-host login path runs unchanged.

1. `GET /oauth/google/redirect` (on `ilmu360.test`) issues a cryptographically
   random opaque `state`, stores `{provider, intended, verifier_hash}`
   server-side in cache (10 min TTL), sets a `__Host-oauth_v` verifier cookie
   (host-only, Secure, HttpOnly, Lax, 10 min; reuses the in-flight value when
   the browser already holds one, so concurrent two-tab attempts stay valid),
   and redirects to Google with a stateless Socialite driver.
2. Google calls `GET /oauth/google/callback` on `dev.ilmu360.com`. The
   controller consumes the state exactly once under a short cache lock
   (fail-closed on missing, expired, replayed, contended, or
   provider-mismatched state), exchanges the code, and resolves the user via
   the existing `ResolveSocialiteUserAction` (`sub` as identity,
   verified-email-only linking, `socialite` table). Same-host callbacks also
   require the verifier cookie, so a forwarded callback URL cannot log the
   victim into the attacker's account (login CSRF).
3. Instead of logging in on the callback host (whose cookies the browser would
   reject), it issues a one-time handoff token (64 random chars, SHA-256 hashed
   at rest, 2 min TTL, lock-guarded single-consume) bound to the verifier hash
   and redirects to `https://ilmu360.test/oauth/google/complete?token=…`.
4. `GET /oauth/google/complete` consumes the token once, requires the verifier
   cookie, establishes the normal Laravel session on `ilmu360.test`, records
   login/signup signals, and redirects to the sanitized intended path
   (flow value, else the session's pre-login destination, else home; relative
   paths only — absolute URLs to other hosts fall back to home, so no open
   redirects).
5. Off-host bridge errors carry a non-sensitive error code to canonical
   `GET /oauth/error/{code}`, which flashes the toast into the working
   canonical session and continues to login (flashing on the bridge host
   would be lost with its rejected cookies). Same-host errors flash inline.

PKCE is intentionally not used: this is a confidential client (client secret on
the server), and the server-side state + single-use handoff already bind the
flow. No Google tokens, user data, or session ids ever appear in URLs.

## Verification

```bash
# Focused suites (array cache store, Socialite fakes)
./pest --parallel tests/Feature/GoogleOAuthHandoffTest.php --compact
./pest --parallel tests/Feature/SocialiteAuthTest.php --compact
./pest --parallel tests/Feature/AuthActionsTest.php --compact
./pest --parallel tests/Feature/Api/AuthApiTest.php --compact

# Static analysis + style
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
```

Live checks (tunnel running): `https://ilmu360.test/login` returns `200`
with the Google button; `/oauth/google/redirect` 302s to Google with
`redirect_uri=https://dev.ilmu360.com/oauth/google/callback`; the exact
callback path through `dev.ilmu360.com` reaches Laravel (bad state 302s to
the canonical error page); `/`, `/up`, `/login`, and
`/oauth/google/callback/anything-else` on `dev.ilmu360.com` all return `404`
served by the Cloudflare edge (empty body, `server: cloudflare`) — the
origin is never touched. OAuth must always be started from `ilmu360.test`;
starting it from `dev.ilmu360.com` 404s by design.

## Troubleshooting

- `dev.ilmu360.com` shows "Herd - Site not found": the tunnel ingress lost its
  `httpHostHeader: ilmu360.test` — check the tunnel configuration version in
  the Cloudflare dashboard.
- No Google button locally: `GOOGLE_REDIRECT_URI` missing or still `*.test`;
  the app hides the button rather than show a dead login.
- "Your sign-in session expired": state (10 min) or handoff (2 min) expired or
  was already consumed — just retry the login.
- Tunnel connects but pages hang: Herd/nginx must be running; the origin is
  `https://ilmu360.test:443` on this machine.

## Files

- `app/Support/Auth/OAuthTransactionStore.php` — server-side state + handoff.
- `app/Http/Controllers/Auth/SocialiteController.php` — redirect/callback/complete.
- `app/Support/Auth/SocialiteProviderConfiguration.php` — bridge detection.
- `routes/web.php` — `socialite.complete` route.
- `bootstrap/app.php` — loopback-only trusted proxies.
- `tests/Feature/GoogleOAuthHandoffTest.php`, `tests/Feature/SocialiteAuthTest.php`, `tests/Pest.php` helpers.
- `scripts/dev-tunnel.sh`, `scripts/launchd/com.ilmu360.cloudflared.plist`, `composer tunnel`.
- `.env` (`GOOGLE_REDIRECT_URI`), `.env.example` (placeholder), `.gitignore` (`/.cloudflared/`).
