# Legal Heroes Design (short) — 2026-11-07

## Intent / why
Give `/privasi`, `/terma`, and `/hubungi-kami` the same full-bleed, high-key courtyard hero treatment as maklum balas / penceramah / institusi, with the hero art running behind the overlay header menu.

## Goals
- Three separate heroes (user chose distinct art per page) in one matched courtyard world: privasi = sheltered/private (carved screen, small lockbox), terma = open book with seal and stacked volumes, hubungi kami = welcoming doorway with lantern and letters.
- Desktop wide + portrait mobile recomposition per page (6 images), left kept empty for dark-green copy, no text/logos/watermarks baked in.
- Reuse the `hero-field` picture/wash structure in the shared `pages/legal.blade.php`; per-`pageKey` art selection and per-page `og_image`.

## Non-goals
- No copy changes, no tab/nav restructuring, no contact email-reveal or social-button behavior changes.

## Anti-goals
- No dark/moody art that breaks the directory look; no baked-in text; no reusing the feedback journal art; no layout change to header/footer chrome.

## Constraints
- Codex imagegen built-in tool via `codex exec`; one generation per image, then PNG verify + WebP encode.
- Keep the existing overlay header and `pt-48/sm:pt-52` rhythm so copy never collides with the menu pill; keep forced-colors fallback behavior.
- Watch total image weight (WebP served, PNG fallback/OG).

## Approaches
- A. Three bespoke shoots (picked): most distinctive, matches the request; costs 6 images.
- B. One base + two prop-swap edits: tighter/faster but pages read near-identical, edit drift risk — rejected.
- C. Reuse existing directory art: cheapest but not distinct, repeats the journal — rejected.

## Decided
- Approach A per user pick ("a").
- Art direction: warm ivory/cream courtyard, carved arch, white dome + minarets, palms, emerald + brass accents, bright morning light (my call, consistent with prior heroes).
- Subjects per page as above (my proposal, correct me if wrong).

## Left to builder
- Generate 6 images to `public/images/legal/`, encode WebP, wire per-`pageKey` `<picture>` + `og_image` in `legal.blade.php`, rebuild assets, verify desktop + 390×844 in Chromium with no JS errors, run AppFeedback-adjacent/legal tests + Pint/PHPStan.
