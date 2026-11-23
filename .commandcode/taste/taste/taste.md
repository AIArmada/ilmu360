# Taste
- For image editing/generation work, use the imagegen skill invoked through the codex CLI rather than ad-hoc local scripts. Confidence: 0.7
- Build public URLs against the production base `https://ilmu360.masyuk.com`. Confidence: 0.7
- Expects risk/quality claims to be justified with repo-specific verified evidence rather than generalized from a similar repo; unqualified "X is bad" framing draws pushback. Confidence: 0.5
- Judges low-likelihood/low-severity security exposure (e.g. an unauthenticated log-write endpoint) as not worth trading away working developer tooling for; prefers keeping the full dev capability, and reaffirms the call even when the risk is re-flagged. Confidence: 0.7
- Commits should be scoped to only the files changed in the current session (explicit pathspec), leaving concurrent-session work uncommitted; then push the branch. Confidence: 0.7
- Prefers tooling config as one universal, machine-wide entry that covers all sibling repos, with per-project files only as lighter overrides — asks for the "universal fix" instead of editing each repo. Confidence: 0.75
- Deploy/refresh routine should follow this order: `git fetch`/`git pull` (fast-forward), then build frontend assets, then run `./scripts/deploy.sh`, then reload Octane. Confidence: 0.6
- A `.env` change is not enough by itself: always follow it by clearing config/route caches (`optimize:clear`) and reloading the serving layer (PHP-FPM or Octane), since cached config and long-lived workers keep stale values. Confidence: 0.85
