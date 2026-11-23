# Taste

## Tooling

- For image editing/generation work, use the imagegen skill invoked through the codex CLI rather than ad-hoc local scripts. Confidence: 0.7

## Conventions

- Build public URLs against the production base `https://ilmu360.masyuk.com`. Confidence: 0.7

## Workflow

- Commits should be scoped to only the files changed in the current session (explicit pathspec), leaving concurrent-session work uncommitted; then push the branch. Confidence: 0.7
- Deploy/refresh routine should follow this order: `git fetch`/`git pull` (fast-forward), then build frontend assets, then run `./scripts/deploy.sh`, then reload Octane. Confidence: 0.6
