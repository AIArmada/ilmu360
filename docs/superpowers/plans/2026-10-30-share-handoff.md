# Handoff: share_member/share_guest home — affiliates-base plan

Date: 2026-10-30. Quota (Codex CLI) resets ~10:02 PM. Resume then.

## Where things stand

Question: are `share_member`/`share_guest` (Affiliate rows + metadata marker + 3 host sidecars) the right/optimum implementation?

Conclusion chain:
1. Initial: dual purpose+metadata+owner is correct but unoptimum (full-table scans, G5 extraction deferred).
2. User forced raw-best framing (commercial will come, no "when"). Picked referrals-base.
3. DeepSeek v4.1-flash dissented → affiliates (cardinality, tenancy, token semantics).
4. GPT-6.1-sol high (codex) overturned to **affiliates-base with package upgrade** (kill-shot: sharer-claims-history vs invitee-accepts; 1 link → N visitors native to affiliates).
5. User corrections (correct): share strings stay host-side — package gets only generic primitives (opaque purposes, credentials/claims, attribution-free outcomes). Applied.
6. Three GPT-6.1-sol medium interrogations: 7 → 5 → 4 findings, all folded into the plan. Fourth pass died on quota (no verdict file; one stray stream line discarded as contradicting tree evidence).

## The plan

`docs/superpowers/plans/2026-10-30-share-affiliates-base.md` (in ilmu360 repo; work executes in commerce repo — vendor symlink resolves there).

Shape: Task 1 commission boundary (cart recorder + outcome `commission_minor`); Task 2 dual capabilities + `RevalidateIdentitySeal` + harness-first; Task 3 extend `RecordAffiliateOutcome` (no migration); Task 4 verification/docs. Host migration is a separate follow-up plan.

## Verdict archive

- `/tmp/arena-share/candidate-deepseek/VERDICT.md` (affiliates dissent)
- `/tmp/arena-share/candidate-ind/verdict.md` (referrals runner)
- `/tmp/arena-share-gpt/candidate-gpt/verdict.md` (GPT high: affiliates-base)
- `/tmp/arena-share-gpt/candidate-gpt-interrogate/verdict.md` (round 1: 7 findings)
- `/tmp/arena-share-gpt/candidate-gpt-interrogate2/verdict.md` (round 2: 5 findings)
- `/tmp/arena-share-gpt/candidate-gpt-interrogate3/verdict.md` (round 3: 4 findings)
- Round 4: no file (quota). Prompt at `/tmp/arena-share-gpt/interrogate4-prompt.md` — auto-generated output path was `/tmp/arena-share-gpt/candidate-gpt-interrogate4/verdict.md`.

## Resume steps

1. Re-run 4th pass (Codex CLI, read-only, medium):
```sh
codex exec -C /Users/Saiffil/Herd/ilmu360 -s read-only -m gpt-6.1-sol -c model_reasoning_effort='"medium"' -o /tmp/arena-share-gpt/candidate-gpt-interrogate4/verdict.md "$(cat /tmp/arena-share-gpt/interrogate4-prompt.md)"
```
2. Fold verified findings into the plan file (report only — do not start implementation).
3. Ask user: execution scope (package-only vs +host) and method (subagent-driven vs native), plus worktree/session move to `/Users/Saiffil/Herd/commerce`.

## Open items (undecided)

- Explicit `commission` payload override on non-commercial rows: honor caller intent or force 0?
- `RecordNonCommercialOutcome` vs extended `RecordAffiliateOutcome`: Task 3 extends the latter; Task 1's Produces line still says `RecordNonCommercialOutcome` — reconcile names during implementation planning (round-2 Task 3 rewrite named the extension; Task 1 text predates it).
- Execution method + repo location still unconfirmed (user dismissed the question prompt once).
