---
target: penceramah
total_score: 24
p0_count: 2
p1_count: 4
timestamp: 2026-07-20T12-03-04Z
slug: es-views-components-pages-speakers-index-blade-php
---
# Impeccable Critique — `/penceramah`

Method: dual-agent (A: design review · B: detector + browser evidence)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2/4 | Stats card flips to "0 Penceramah Disahkan" during search — contradictory signal |
| 2 | Match System / Real World | 4/4 | Malay copy precise: penceramah/ustaz/ustazah/pendakwah/majlis |
| 3 | User Control and Freedom | 4/4 | Esc clears search, X button, pagination, wire:navigate solid |
| 4 | Consistency and Standards | 3/4 | Verification stated 3× (hero/card/pill) — meaning eroded |
| 5 | Error Prevention | 3/4 | Direct→fuzzy fallback forgives typos |
| 6 | Recognition Rather Than Recall | 2/4 | Zero filters — must recall a name to search |
| 7 | Flexibility and Efficiency | 1/4 | No filters, no sort, no shortlist, no shortcuts |
| 8 | Aesthetic and Minimalist Design | 1/4 | Max slop density: 6 kickers, 3 dot-grids, 5 empty circles, hero-metric |
| 9 | Error Recovery | 3/4 | Empty state well-tiered; distinguishes no-match vs empty |
| 10 | Help and Documentation | 1/4 | Disahkan asserted 14× but criteria never stated |
| | **Total** | **24/40** | **Acceptable (20–27)** |

## Anti-Patterns Verdict

AI-generated: YES. The One Kicker Rule violated 6×, hero-metric card, ghost-card pattern (13 instances confirmed by detector), gray drop shadows, decorative dot-grids on 3 surfaces, empty circles, over-rounding at 32px, sketchy SVG underline.

Deterministic scan: CLI exit 2, 9 findings. Browser overlay: 36 anti-patterns at runtime — 13 gpt-thin-border-wide-shadow, 12 ai-color-palette, 12 image-hover-transform, 4 clipped-overflow-container, 1 low-contrast (1.0:1 white-on-cream — A missed, detector caught), 1 hero-eyebrow-chip, 1 single-font (false positive).

## Priority Issues

[P0] Placeholder silhouettes on every card — trust collapse. Fix: monogram placeholder, gate on photo coverage. $impeccable harden
[P0] One Kicker Rule violated 6× — AI slop tell. Fix: keep 1 kicker, delete/collapse rest. $impeccable distill
[P1] White-on-cream heading 1.0:1 contrast — detector-caught accessibility failure. Fix: text-emerald-950. $impeccable audit
[P1] Ghost-card pattern + gray shadow on every card (13 instances). Fix: drop rest shadow, keep border, hover glow only. $impeccable polish
[P1] Zero filters — name-only search. Fix: SelectFilter chips for Negeri/Specialty/Bahasa. $impeccable shape
[P1] Hero-metric template (stats card) — banned, duplicates results count. Fix: remove, replace with quiet line. $impeccable distill

## Cognitive Load
Failed 6/8 (high): single focus, chunking (12 cards), visual hierarchy, one-thing-at-a-time, working memory, progressive disclosure.

## Persona Red Flags
Aishah (Seeker of Knowledge): 12 faceless silhouettes, no filters by negeri/specialty, recall-heavy slog. Abandons.
Casey (Mobile): horizontal card row cramped at 160px, no thumb-reachable filters, must type one-handed on train.
Sam (A11y): name announced twice per card, decorative labels read aloud (48 noise-words), no prefers-reduced-motion fallback for scroll-reveal, 1.0:1 heading unreadable.
