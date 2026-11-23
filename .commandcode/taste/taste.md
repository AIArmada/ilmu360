# Taste

## Workflow

- Commits should be scoped to only the files changed in the current session (explicit pathspec), leaving concurrent-session work uncommitted; then push the branch. Confidence: 0.7
- Deploy/refresh routine should follow this order: `git fetch`/`git pull` (fast-forward), then build frontend assets, then run `./scripts/deploy.sh`, then reload Octane. Confidence: 0.6
