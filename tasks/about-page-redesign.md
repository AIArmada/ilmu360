# About page — full redesign

Status: done
Route: `/tentang-kami` → `App\Livewire\Pages\About\Show` → `resources/views/livewire/pages/about/show.blade.php`
Content: `resources/lang/{ms,en,jv,ar,zh,ta}/about.php`

## Decisions

- **Direction:** "The filing wall" — a surau noticeboard filed with care. Strict grid, generous margin, cream paper sheets on warm sand, brass-pin slips, one gold rule. No paper curl or tape.
- **Palette:** site tokens only. Sand `#F2E9D5`, cream `#FBF6EA`, deep green `#006044`, gold `#E3B537`, ink `#17191C` / `#2D2C2B`.
- **Type:** Fraunces (voice) + Figtree (scanning). Display weight 400–500.
- **Imagery:** generated. `surau-window.png` (hero), `surau-hall.png` (cost band), `surau-congregation.png` (closing).
- **Motif:** girih tile as inline SVG in code, 13% gold on the hadith panel. No raster, no Arabic script in generated images.

## Structure (10 sections → 7)

1. Hero — headline in three beats, two CTAs, tall window photograph, gold rule.
2. The gap — cream sheet: prose + three pinned slips showing the broken chain.
3. What it costs — empty hall band, overscored heading, four consequences as ruled rows.
4. What it does — five verified capabilities as ruled rows beside a sticky heading.
5. Who it's for — kariah, AJK, asatizah in three text columns.
6. Hadith — contained green panel with the girih motif.
7. Close — congregation photograph, the "kalau aku tahu awal" line, three actions.

Dropped: stat cards, four "causes", losses/definition split, magnet+proof, three "impact" cards, uppercase tracked eyebrows, 01–04 markers, gradient text, glass panels, 40–48px radii, `font-black`.

## Copy

Rewritten in Malay — warm *kita* voice, no shorthand, no exclamation clusters. Removed the inverted "ilmu360° membenarkan ini berlaku" sentence. Every capability claim checked against the codebase: saved events, event reminders (24h/2h), organiser verification, event status, Google Maps directions, radius search. Removed "2KM scan" and "Waze / Apple Calendar export" (not in the product).

All six locales kept in one key structure. `ms`, `en`, `jv` authored; `ar`, `zh`, `ta` translated. Serif italic is Latin-only — Chinese and Tamil would render as synthetic obliques.

## Verification

- `vendor/bin/pest tests/Feature/AboutPageTest.php` — 10 passed, 30 assertions.
- Pint and PHPStan (level 6) clean on every changed PHP file.
- Lighthouse mobile: Accessibility 100, Best Practices 100, SEO 100, Agentic Browsing 100.
- No horizontal overflow at 390 / 768 / 1440; no console errors.
- All six locales render with the correct `lang`/`dir`; Arabic mirrors correctly.

## Known, out of scope

- Tamil and Chinese rely on system fonts; `partials/font-links.blade.php` loads only Fraunces, Figtree and Noto Naskh Arabic. The headless test browser shows tofu for Tamil — pre-existing and site-wide, not introduced here.
- `scripts/deploy.sh` called `systemctl reload`, which the host's sudoers does not grant. It now falls back to `service <pool> reload`.

## Evidence

Screenshots, mocks and the Lighthouse report: `storage/app/design/about/`.
