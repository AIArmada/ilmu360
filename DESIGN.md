# ilmu360 — Design System & Direction (v2, reviewed)

**Product:** ilmu360, a Malay-language platform for discovering Islamic talks, classes, speakers and institutions in Malaysia.
**Status:** v2. Reviews the three original directions, measures them, and consolidates them into one buildable system. The originals are preserved in Appendix A.
**Contrast figures** in this file were computed (WCAG 2.x relative luminance), not estimated.

---

## 0. Decision Summary

| Question | Decision |
|---|---|
| Which direction? | **None of the three as-is. Build a unified system** using A's job-to-be-done and warmth, B's prayer-anchored time rail and reading discipline, and C's serif voice and trust modules. |
| Front door of the product | **Events (majlis), anchored to prayer time.** People and institutions are the trust layer beneath, not the hero. |
| Accent | Emerald `#047857` only. One filled emerald action per screen. |
| Nav CTA "Hantar Majlis" | Near-black pill, so it never competes with the hero search button. |
| Radius | 24px cards, 12px inputs and media (concentric), pill controls. 4px only in Reading mode. |
| Type | Soft serif for headlines, friendly geometric sans for UI, Naskh for Arabic. |
| Scope | Desktop **and mobile**. Most usage will be a phone in someone's hand between Maghrib and Isyak. |

---

## 1. Review

### 1.1 What is already right (keep)

- **One accent color.** Emerald `#047857` (Tailwind emerald-700) passes AA on every surface used: 5.03 on cream, 5.48 on white, and white text on it is 5.48.
- **Warm neutrals** and generous whitespace suit the audience and topic.
- **No faces or photorealistic people.** A sound reverence policy (see §11).
- **The Avoid lists.** Precise and useful. Preserved in Appendix A.
- **Prayer-anchored schedule strip (Direction B).** The single most domain-native idea across all three prompts.
- **Malay copy quality.** "Majlis Ilmu Berhampiran Anda", "Ketenangan Melalui Ilmu" and "Dengar. Faham. Amal." are all strong.

### 1.2 Findings and fixes

| # | Priority | Finding | Evidence | Fix |
|---|---|---|---|---|
| 1 | P0 | The directions differ in **information architecture**, not just skin (A: Majlis/Institusi/Penceramah; B: Majlis/Kitab/Penceramah; A leads with events, B with time, C with people). They cannot be compared fairly. | Prompts | One canonical IA and page; directions become skins plus modules to borrow (§3). |
| 2 | P0 | **White cards on cream are nearly invisible** in A. | White vs `#f9f4f2` = **1.09:1** | 1px divider border plus soft shadow (§4, §7). |
| 3 | P0 | **Control borders too faint** in B. Hairline `#dedbd6` is fine for dividers but fails for inputs. | `#dedbd6` vs `#faf9f6` = **1.31:1**; WCAG 1.4.11 needs 3:1 | `--border-strong: #8c867e` for controls (3.30 on cream, 3.60 on white). |
| 4 | P0 | **Prayer-relative time exists only in B.** Malaysian majlis are announced as "Selepas Maghrib", not "8:15 PM". | Domain knowledge | Promote to a global pattern: rail plus prayer-relative time on every card (§7). |
| 5 | P0 | **A's event cards omit the speaker.** People decide by who is speaking. | A card spec | Speaker line added to the card. |
| 6 | P0 | **Arabic and Quranic text handling is unspecified.** Image models garble it, B's italic voice can be applied to it, and pattern fills can become disrespectful. | Prompts | Explicit rules in §10 and §11. |
| 7 | P1 | Three "primary" actions compete: A's dark-green "Hantar Majlis" pill sits above the search. "Dark-green" is also not a token. | A prompt | One filled emerald action per view ("Cari"). Nav CTA becomes an ink pill. |
| 8 | P1 | **"Warm stone gray" (C) is unspecified**, and the common stone `#78716c` fails on cream. | `#78716c` on `#f9f4f2` = **4.40:1** | `--ink-muted: #6b645e` (5.33 cream, 5.82 white). |
| 9 | P1 | No mobile, no states (hover, focus, disabled, empty, error, cancelled, full). | All prompts | §7 and §8. |
| 10 | P1 | 24px radius with dense content wastes space and looks unresolved when nested. | A and C | Concentric radii (24 outer, 12 inner) and a spacing rule. |
| 11 | P1 | C's stats (1,200+ / 300 / 150) are placeholders. On a religious platform, an inflated number is a trust failure. | C prompt | Real numbers only, placed low on the page as proof. |
| 12 | P1 | Mockup-only constraints ("no dark mode", "no people") read as product rules. | All prompts | Clarified in §11 and §12. |
| 13 | P2 | Three separate type systems and three near-identical inks (`#111111`, `#17191c`, `#2d2c2b`). | Prompts | Converged to one stack and two inks (§5). |
| 14 | P2 | B's "whisper-light" headline risks thin rendering on low-DPI phones. | B prompt | Regular weight serif at 56px+ for display; never below 400. |
| 15 | P2 | Prompts ask for too much legible text, which is the main cause of garbled output. | Prompts | Text budget rule (§13). |

---

## 2. Strategic Frame and Principles

If ilmu360 is the operating system for Muslim civil society in Malaysia, the design is not a listings page. It is the visible face of a graph: **majlis ↔ penceramah ↔ institusi ↔ kitab ↔ siri (series)**.

### 2.1 Principles

1. **Waktu-first.** Time is prayer-anchored. The default view is the next prayer window, not a date picker.
2. **Kepercayaan (trust) is visible.** Verification, freshness and organizer identity are UI, not fine print.
3. **Tenang (calm).** Restraint is the brand. No pop-ups, no autoplay, no red notification badges, no scarcity pressure.
4. **Hormat (reverence).** Sacred text and imagery are handled with rules, never decoration (§11).
5. **Boleh dibaca semua (legible to all).** Wide age range, mid-range phones, variable networks.

### 2.2 Second-order design implications

- **The entity graph is the navigation.** Every majlis links to its penceramah, institusi, kitab and siri. Cross-links are first-class modules ("Lagi daripada penceramah ini", "Majlis di masjid ini"), not footnotes.
- **A wrong listing costs someone an evening and the platform its credibility.** Trust primitives are required: "Disahkan" badge, "Dikemas kini {masa}" stamp, cancelled and changed states.
- **Most majlis recur** (weekly kuliah). Model "Setiap Rabu" and series as a primary card attribute; it turns one visit into a habit.
- **Supply is part of the design.** Organizers already have a poster (WhatsApp and Instagram). The "Hantar Majlis" flow should start from a poster upload and confirm fields, not a blank form.
- **WhatsApp is the distribution layer.** Every majlis needs a generated share card (1200×630) built from these tokens, plus a clean "Kongsi ke WhatsApp" text template.
- **Seasonality.** Ramadan is a theme mode: the prayer rail extends with Sahur, Berbuka, Tarawih and Qiamullail.
- **Identity.** Generic "Islamic geometric" reads Middle Eastern. Add Malay-Islamic motifs (awan larat, pucuk rebung, songket-inspired borders) to the pattern set so the platform feels Malaysian (§11).

---

## 3. Recommended Direction: Unified System

### 3.1 Borrow map

| From | Take | Leave |
|---|---|---|
| **A · Headspace-calm** | Cream canvas, white 24px cards, pill chips, events-first discovery, friendly search | Speaker-less cards, low density, "dark-green" ambiguity |
| **B · Intercom-editorial** | Prayer-anchored time rail, hairline dividers, serif italic voice (Malay only), light editorial rigor, **Reading mode** for kitab and references | 4px corners and zero shadow as the global skin, light-weight display type, list-only browsing |
| **C · Steep-serif authority** | Regular-weight serif headlines, scholar cards, institution list, stat chips, ink pill CTA, "Dengar. Faham. Amal." | People-first hero, placeholder stats, card-in-card nesting |

### 3.2 Copy map (each direction's headline gets a job)

| Slot | Copy |
|---|---|
| Home H1 | **Majlis Ilmu Berhampiran Anda** |
| Brand tagline (footer, About, share cards) | **Dengar. Faham. Amal.** |
| Editorial and campaign hero (About, Kitab landing, Ramadan) | **Ketenangan Melalui Ilmu** |

### 3.3 Two surface modes

| Mode | Used for | Rules |
|---|---|---|
| **Card mode** (default) | Discovery, speakers, institutions | Cream canvas, white 24px cards, soft shadow plus divider border, pills |
| **Reading mode** | Kitab, references, articles, verse and hadith blocks | Canvas `#faf9f6`, 4px corners, hairline dividers, no shadows, serif body, 60–72 character measure |

---

## 4. Design Tokens

```css
:root {
  /* Surfaces */
  --canvas: #f9f4f2;          /* page background (Reading mode: #faf9f6) */
  --surface: #ffffff;         /* cards, inputs */
  --divider: #e5ded8;         /* decorative only (1.2–1.3:1) */
  --border-strong: #8c867e;   /* inputs, controls: 3.30 on canvas, 3.60 on surface */

  /* Ink */
  --ink-strong: #17191c;      /* headings, ink buttons (17.6:1) */
  --ink: #2d2c2b;             /* body (12.8:1 on canvas) */
  --ink-muted: #6b645e;       /* labels, meta (5.33 canvas, 5.82 surface) */

  /* Accent: the only brand color */
  --emerald-50:  #ecfdf5;
  --emerald-100: #d1fae5;
  --emerald-700: #047857;     /* primary */
  --emerald-800: #065f46;     /* hover (white text 7.68:1) */
  --emerald-900: #064e3b;     /* pressed */

  /* Status (functional only, never decorative) */
  --error:    #b42318;        /* on white 6.57:1 */
  --warn-ink: #92400e;
  --warn-bg:  #fef3c7;        /* 6.37:1 together */

  /* Imagery only, never used for text */
  --pattern-emerald:    #6f9c8a;
  --pattern-sand:       #e6d5b8;
  --pattern-terracotta: #c9825f;

  /* Radius */
  --r-card: 24px;   --r-media: 12px;  --r-input: 12px;
  --r-pill: 999px;  --r-read: 4px;    /* Reading mode only */

  /* Shadow (soft, never heavier) */
  --shadow-1: 0 1px 2px rgba(45,44,43,.05), 0 6px 20px rgba(45,44,43,.06);
  --shadow-2: 0 2px 4px rgba(45,44,43,.06), 0 12px 32px rgba(45,44,43,.10);

  /* Spacing: 4px base */
  --s-1: 4px;  --s-2: 8px;  --s-3: 12px; --s-4: 16px; --s-5: 24px;
  --s-6: 32px; --s-7: 48px; --s-8: 64px; --s-9: 96px;

  /* Motion */
  --ease: cubic-bezier(.2,.7,.2,1);  --t-fast: 150ms;  --t-base: 200ms;
}
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
```

**Rules**
- Emerald usage: primary button, active/selected state, focus ring, one highlight card per view, small tag punctuation. Never body text blocks, never backgrounds larger than a chip or one highlight card.
- Inks converged from `#111111` / `#17191c` / `#2d2c2b` into two: `--ink-strong` and `--ink`.
- Names are semantic so a dark theme can be added later. **Not in v1.** Reading mode is the first candidate (night reading).

### 4.1 Verified contrast

| Pair | Ratio | Result |
|---|---|---|
| Emerald `#047857` on cream `#f9f4f2` | 5.03 | AA ✓ |
| Emerald on white | 5.48 | AA ✓ |
| Emerald on canvas B `#faf9f6` | 5.21 | AA ✓ |
| White on emerald (button) | 5.48 | AA ✓ |
| White on emerald-800 (hover) | 7.68 | AAA ✓ |
| Emerald-800 on emerald-50 (chip) | 7.29 | AAA ✓ |
| Body `#2d2c2b` on cream | 12.78 | AAA ✓ |
| Ink-strong `#17191c` on white | 17.61 | AAA ✓ |
| Muted `#6b645e` on cream / white | 5.33 / 5.82 | AA ✓ |
| B's muted `#585858` on `#faf9f6` | 6.76 | AA ✓ |
| Error `#b42318` on white | 6.57 | AA ✓ |
| Border-strong `#8c867e` on cream / white | 3.30 / 3.60 | UI ≥ 3:1 ✓ |
| Common stone `#78716c` on cream | **4.40** | ✗ avoid |
| Divider `#e5ded8` on white | 1.33 | Decorative only |
| White card on cream | **1.09** | Needs border plus shadow |
| Disabled `#a8a29e` on white | 2.52 | Inactive only, never for meaning |

---

## 5. Typography

| Role | Font (recommended, swappable) | Fallback |
|---|---|---|
| Display and headings | **Fraunces**, regular 400, `SOFT` ~50, `WONK` off | `"Iowan Old Style", Georgia, serif` |
| UI and body | **Figtree** | `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` |
| Arabic | **Noto Naskh Arabic** (or Amiri) | `"Traditional Arabic", serif` |

Any equivalent works; what matters is the pairing logic: soft serif for voice, geometric sans for scanning, Naskh for scripture. All three are open-licensed on Google Fonts.

| Style | Desktop | Mobile |
|---|---|---|
| Display (H1) | Serif 400, 56/62, −0.01em | 36/42 |
| H2 | Serif 400, 36/44 | 28/36 |
| Card title | Sans 600, 20/28 (2-line clamp) | 19/26 |
| Body | Sans 400, **17/28** | 17/28 |
| Meta and labels | Sans 500, 14/20 (floor 13) | 14/20 |
| Arabic | 24/44 (line-height ≥ 1.8) | 22/40 |

**Rules**
- `font-variant-numeric: tabular-nums` for dates, times and counts.
- `text-wrap: balance` on headings; `overflow-wrap: anywhere` on titles (long Malay compounds and names).
- No all-caps and no letter-spacing on Malay or Arabic text.
- Serif italic (per Direction B) is allowed for **Malay sublines and quotes only. Never italicize Arabic.**
- Display weight never below 400.

---

## 6. Layout and Responsive

| Token | Value |
|---|---|
| Breakpoints | 640 / 1024 / 1280 |
| Container | max 1200px, page padding 24 (desktop), 20 (tablet), 16 (mobile) |
| Grid | 12-col, gutter 24 (desktop) / 16 (mobile) |
| Event grid | 3 cols ≥ 1024, 2 cols 640–1023, 1 col < 640 |
| Section rhythm | 64–96px desktop, 40–56px mobile |
| Touch targets | **44×44px** (WCAG 2.2 AA minimum is 24px; 44 is the design target) |

---

## 7. Components

### 7.1 Buttons

| Variant | Spec | Use |
|---|---|---|
| **Primary** | 48px, pill, `--emerald-700` bg, white text. Hover `--emerald-800`, pressed `--emerald-900` | "Cari" only, plus confirm actions inside flows. **One per view.** |
| **Ink** | 48px (36 compact), pill, `--ink-strong` bg, white text | Nav "Hantar Majlis" |
| **Secondary** | 40px, pill, 1.5px `--border-strong` outline, `--ink-strong` text | "Ikuti", "Tapis", "Lihat lagi" |
| **Ghost** | Text only, `--ink`, underline on hover | Tertiary links |

**Focus (all interactive):** `outline: 2px solid var(--emerald-700); outline-offset: 2px`. **Disabled:** `--divider` bg with `--ink-muted` text, `aria-disabled`.

### 7.2 Top navigation

- Desktop: 64px, sticky, 1px `--divider` appears on scroll. Wordmark "ilmu360" left. Links **Majlis · Penceramah · Institusi · Kitab**. Right: language toggle `BM | EN` (EN optional in v1) and the ink pill **Hantar Majlis**.
- Mobile: 56px top bar (wordmark and compact "Hantar Majlis" pill), plus 64px bottom tab bar **Utama · Cari · Disimpan · Akaun** *(recommended; validate)*.
- Active link: `--ink-strong` weight 600 plus 2px underline. Never color alone.

### 7.3 Search

- 56px, pill, white, 1.5px `--border-strong`, `--shadow-1`. Segments: text input (placeholder **"Cari majlis, penceramah atau masjid"**), location segment (**"Lokasi anda"** with "Guna lokasi saya"), and the emerald **"Cari"** button inside the right end.
- Mobile: stacks to input plus a location chip row beneath; "Cari" becomes full width.
- Placeholder `--ink-muted` (5.82 on white).

### 7.4 Category chips and filters

- Chips: 40px, pill, white with 1px `--border-strong`. Types: **Kuliah, Ceramah, Kelas, Kursus**.
- Selected: `--emerald-700` bg, white text, **plus a check icon** (not color alone).
- "Tapis" (secondary button) opens a sheet with: Tarikh, Waktu solat, Bahasa, Sesuai untuk (Semua, Muslimah, Muslimin, Keluarga, Kanak-kanak), Percuma, Dalam talian.
- Mobile: chips scroll horizontally with edge fade; never wrap to more than one row.

### 7.5 Prayer-time rail (signature component)

- Single-select toggle group: **Subuh · Zohor · Asar · Maghrib · Isyak**. Each segment: label (sans 600 15/20), `{waktu}` in tabular numerals, and `{n} majlis`.
- **Default = the next upcoming prayer window** ("Seterusnya"), time-aware.
- Active: `--emerald-800` text plus 2px `--emerald-700` underline. Inactive: `--ink-muted`.
- Data: local prayer times by zone (e.g. JAKIM e-Solat zone data or equivalent). Do not hard-code times.
- Semantics: radio-group or `aria-pressed` toggles. Mobile: horizontal scroll-snap.
- Ramadan mode appends **Sahur, Berbuka, Tarawih, Qiamullail**.

### 7.6 Event card (Majlis)

```
┌────────────────────────────────────┐  surface, 24px radius, 1px divider, shadow-1
│ ┌────────────────────────────────┐ │  media 16:10, 12px radius (concentric),
│ │ [3 Okt]                  [save]│ │  pattern fallback; date badge TL, save TR
│ │        pattern / poster        │ │
│ └────────────────────────────────┘ │
│  [Percuma] [Berulang]               │  ≤ 2 tags
│  Kuliah Maghrib: Tafsir …          │  card title, 2-line clamp
│  Ustaz {Nama}                       │  speaker with honorific
│  Selepas Maghrib · 8.30 malam       │  prayer-relative first, clock second
│  Masjid {Nama} · {Kawasan} · 2.4 km │  venue · area · distance (if location on)
└────────────────────────────────────┘
```

- Padding 12 around the media, 16 for the text block. **Inner radius = outer radius − padding (24 − 12 = 12).**
- The whole card is one link (title is the accessible name). Save is a separate 44px control. Nested interactive elements are not allowed inside the link.
- Hierarchy: **what → who → when → where.**

| State | Treatment |
|---|---|
| Hover | `translateY(-2px)`, `--shadow-2`, 160ms |
| Focus-visible | Standard focus ring around the card |
| **Penuh** | Neutral tag "Penuh"; card stays clickable |
| **Dibatalkan** | Banner on media in `--error` with icon; media desaturated; time line replaced by "Dibatalkan" |
| **Dikemas kini** | Warn tag "Dikemas kini" if time or venue changed in the last 48h |
| **Siaran Langsung** | Emerald dot plus label (no pulse under reduced motion) |
| **Berulang** | "Setiap Rabu" in place of a single date |
| Loading | Skeleton with identical geometry |
| Empty (list) | "Tiada majlis ditemui." Offer: widen radius, clear filters, "Hantar Majlis" |
| Error | "Tidak dapat memuatkan majlis. Cuba lagi." with a secondary retry button |

### 7.7 Scholar card (from C)

- Surface, 24px radius, padding 20. 56px circular monogram (initials in serif, tinted from sand or emerald-100, chosen deterministically from the name). Speaker-supplied photo optional, monogram fallback always.
- Name with title (sans 600 18), "{n} majlis", secondary pill **"Ikuti"**.
- **Highlight variant:** `--emerald-50` bg with 1px `--emerald-100` border. **Max one per view.**

### 7.8 Institution row (from C)

- Two-column list ≥ 1024, one column below. Row: 48px geometric glyph, name, area label (`--ink-muted`), type tag (Masjid, Surau, Madrasah, Universiti), **"Disahkan" badge** (emerald check plus text) when verified. `--divider` between rows. No card-in-card.

### 7.9 Stat chips (from C)

- Pill, white, serif number 24 plus sans label ("Majlis", "Penceramah", "Institusi"). **Real, live figures only.** Placed in the trust band, never in the hero.

### 7.10 Footer

- Wordmark, tagline **"Dengar. Faham. Amal."**, links, language toggle. Slim on mobile.

---

## 8. Page: Discovery Home

### 8.1 Desktop (1440 × 900)

```
┌──────────────────────────────────────────────────────────────────────┐
│ ilmu360   Majlis  Penceramah  Institusi  Kitab        BM|EN [Hantar Majlis] │ 64
├──────────────────────────────────────────────────────────────────────┤
│                    Majlis Ilmu Berhampiran Anda                      │ H1 serif 56
│     ( Cari majlis, penceramah atau masjid | Lokasi anda | [Cari] )   │ search 56
│         (Kuliah) (Ceramah) (Kelas) (Kursus)   (Tapis)               │ chips 40
├──────────────────────────────────────────────────────────────────────┤
│ Hari Ini ▾                                        Senarai | Peta     │
│ [ Subuh ][ Zohor ][ Asar ][ Maghrib ▔▔ ][ Isyak ]   prayer rail     │
│ ┌────────┐ ┌────────┐ ┌────────┐                                    │
│ │ card   │ │ card   │ │ card   │   3-col grid (first row peeks      │
│ └────────┘ └────────┘ └────────┘   above the fold)                  │
│                        (Lihat lagi)                                  │
├──────────────────────────────────────────────────────────────────────┤
│ Penceramah:  [scholar] [scholar*] [scholar]      (* highlight)       │
│ Institusi:   two-column list with Disahkan badges                    │
│ Stat chips:  {n} Majlis · {n} Penceramah · {n} Institusi             │
├──────────────────────────────────────────────────────────────────────┤
│ Footer — Dengar. Faham. Amal.                                        │
└──────────────────────────────────────────────────────────────────────┘
```

### 8.2 Mobile (390 × 844)

1. Top bar 56: wordmark and compact "Hantar Majlis" pill.
2. H1 at 36/42, left-aligned.
3. Search stacked, then a horizontal chip row.
4. Prayer rail: horizontal scroll-snap, next prayer pre-selected.
5. Single-column event cards; skeleton while loading.
6. Trust band: horizontal scroller for scholars, single-column institutions, stat chips wrap two per row.
7. Bottom tab bar 64: Utama · Cari · Disimpan · Akaun.

### 8.3 Page inventory

| Page | Primary object | Mode | Key modules |
|---|---|---|---|
| Home | Majlis | Card | Search, rail, event grid, trust band |
| Majlis detail | One majlis | Card | Poster viewer, waktu, venue and map, "Kongsi ke WhatsApp", "Tambah ke kalendar", series and speaker cross-links, freshness stamp |
| Penceramah profile | Speaker | Card | Monogram or photo, upcoming majlis, kitab discussed, "Ikuti" |
| Institusi profile | Masjid or surau | Card | "Disahkan", weekly schedule (recurring), map, upcoming majlis |
| Kitab and references | Text | **Reading** | Serif list, references, verse blocks |
| Hantar Majlis | Submission | Card | Poster-first upload, confirm fields, preview card |

---

## 9. Motion

- 150–200ms `--ease` for hover, focus and state changes. Sheets slide 240ms.
- No autoplay, carousels that auto-advance, parallax, or looping animation.
- Respect `prefers-reduced-motion` (see tokens).

---

## 10. Content and Localization

**Language:** Malay first (`lang="ms"`), English toggle later. Arabic blocks carry `lang="ar" dir="rtl"`.

| Item | Rule |
|---|---|
| Time | Prayer-relative first, clock second, Malay style with period: "Selepas Maghrib · 8.30 malam" |
| Date | "Sabtu, 3 Okt 2026" (day, date, month, year). Hijri optional on detail pages, format `{hari} {bulan Hijrah} {tahun}H` |
| Days | Isnin, Selasa, Rabu, Khamis, **Jumaat**, Sabtu, Ahad |
| Months | Jan, Feb, **Mac**, Apr, Mei, Jun, Jul, **Ogos**, Sep, **Okt**, Nov, **Dis** |
| Numbers | "1,200+" comma thousands. Tabular numerals |
| Honorifics | Preserve exactly as submitted: Ustaz, Ustazah, Dato', Datuk, Dr., Prof., Tuan Guru, Habib, Syeikh. Never auto-abbreviate or reorder |
| Copy tone | Calm, respectful, plain. No urgency language, no exclamation clusters |

**Canonical copy**

| Context | Text |
|---|---|
| Wordmark | ilmu360 |
| Nav | Majlis, Penceramah, Institusi, Kitab |
| CTA | Hantar Majlis |
| Home H1 | Majlis Ilmu Berhampiran Anda |
| Search placeholder | Cari majlis, penceramah atau masjid |
| Search button | Cari |
| Categories | Kuliah, Ceramah, Kelas, Kursus |
| Prayer slots | Subuh, Zohor, Asar, Maghrib, Isyak |
| Tags | Percuma, Berulang, Penuh, Dibatalkan, Dikemas kini, Disahkan, Siaran Langsung, Dalam Talian |
| Actions | Tapis, Ikuti, Simpan, Lihat lagi, Kongsi ke WhatsApp |
| Tagline | Dengar. Faham. Amal. |
| Campaign | Ketenangan Melalui Ilmu |

**Arabic typography**
- Naskh font, 22–24px minimum, line-height ≥ 1.8, right-aligned.
- Never italic, never letter-spaced, never uppercase-transformed, never truncated with an ellipsis, never wrapped mid-word.
- Always show a source in Malay beside scripture or hadith, e.g. "Surah Al-Baqarah: 255".
- Use verified text only. Never type Arabic from memory.

---

## 11. Imagery and Reverence

**Mockups vs. product.** "No people" and "no dark mode" were mockup-generation constraints. In the product: speaker photos are allowed when supplied by the speaker (monogram fallback), and organizer posters are shown on detail pages. Cards never depend on imagery to make sense.

**Pattern library**
- Seamless SVG tiles, generated in code (not by image models), max 3 colors from the imagery palette, low contrast so badges and text stay legible.
- Two families: Islamic geometric (star and girih grids) and **Malay-Islamic motifs** (awan larat, pucuk rebung, songket-inspired borders). The second family is the differentiator.
- Assigned deterministically per majlis ID or category so a card looks the same every visit.

**Sacred-text rules**
- Never use Quranic verses, "Allah", or the Prophet's name in calligraphy as decoration, background, watermark, pattern fill, or in cropped or truncated containers.
- Never place scripture beside ads, promotions, or transactional UI.
- **Never let an image model generate Arabic script.** Garbled Arabic is inaccurate and disrespectful. Mockups contain no Arabic; real Arabic is typeset from verified text.
- No depictions of prophets or companions. No human figures in illustration.

---

## 12. Accessibility and Performance

**Accessibility (WCAG 2.2 AA)**
- Contrast per §4.1. Controls have 3:1 borders. Focus is always visible.
- 44px targets. Base text 17px. Layout survives 200% zoom and 320px width.
- Active and selected states use more than color (underline, check, weight).
- Skip link "Langkau ke kandungan". Landmarks on every page. Toggle groups have accessible names.
- One link per card; no nested interactive elements.
- Screen-reader strings in Malay; Arabic spans carry `lang="ar"`.

**Performance (mid-range phones, variable networks)**
- Card media ≤ 60 KB (AVIF or WebP), lazy-loaded below the fold, fixed aspect ratio (no layout shift).
- Patterns are inline SVG or CSS, not raster.
- Self-host fonts, subset Latin (Malay needs only basic Latin) and Arabic separately; `font-display: swap`.
- Skeletons match final geometry.

---

## 13. Prompt Kit (image-model mockups)

Use these for mood and composition only. **Build the real UI in HTML/CSS**; image models cannot be trusted with Malay text.

### 13.1 Rules

1. Start with a **FORMAT** line: flat, front-on, no device frame, no browser chrome, no perspective.
2. **Text budget:** at most ~15 legible strings. Single words are safe; keep phrases to ≤ 5 words, and allow at most 3 longer phrases. Everything else is "gray placeholder bars, no letters".
3. Quote every string that must be rendered, spelled exactly.
4. No Arabic script anywhere.
5. Keep the Avoid line. Generate 4 variants, pick the one with correct text, and re-run with fewer words if any headline is garbled.

### 13.2 Unified, desktop

```
Flat, front-on UI mockup of a desktop web page, 16:9 landscape (1440x900). No device frame, no browser chrome, no perspective, no scene.

Product: "ilmu360", a Malay-language platform for discovering Islamic talks and classes.

Style: warm cream page background (#f9f4f2), white rounded cards (24px radius) with a thin warm-gray border and a very soft shadow, pill buttons and chips. Warm dark text (#2d2c2b). One accent only: deep emerald #047857. Headline in a soft, regular-weight serif; UI text in a friendly geometric sans. Generous whitespace, calm.

Layout, top to bottom:
1. Slim top nav: wordmark "ilmu360" on the left; links "Majlis", "Penceramah", "Institusi", "Kitab"; on the right a near-black pill button "Hantar Majlis".
2. Centered serif headline "Majlis Ilmu Berhampiran Anda". Beneath it a wide white pill search bar with placeholder "Cari majlis, penceramah atau masjid" and an emerald pill button "Cari" at its right end.
3. Centered row of four pill chips: "Kuliah", "Ceramah", "Kelas", "Kursus".
4. A segmented row of five prayer-time slots: "Subuh", "Zohor", "Asar", "Maghrib", "Isyak". "Maghrib" is active: emerald text with a thin emerald underline.
5. A 3-column grid of event cards. Each card has a rounded image area with an abstract geometric pattern, a small white date badge (day number and month), then a title, a speaker line, a time line and a mosque line. Show these as gray placeholder bars, except the first card's title, which reads "Kuliah Maghrib".
6. Slim footer strip.

Text rule: render ONLY the quoted strings above as legible text. All other text is neutral gray placeholder bars with no letters.

Imagery: abstract Islamic geometric patterns in muted emerald, sand and terracotta. No people, no faces, no photographs, no Arabic script or calligraphy anywhere.

Avoid: purple or blue gradients, glassmorphism, dark mode, heavy shadows, garbled or misspelled text, photorealistic people, cluttered or dense layout, more than one accent color.
```

### 13.3 Unified, mobile

```
Flat, front-on UI mockup of a mobile web page, portrait 390x844. No phone frame, no status-bar notch scene, no perspective.

Product: "ilmu360", a Malay-language platform for discovering Islamic talks and classes.

Style: warm cream background (#f9f4f2), white rounded cards (24px radius, thin warm-gray border, very soft shadow), pill chips. Warm dark text (#2d2c2b). One accent: deep emerald #047857. Soft regular-weight serif headline, friendly geometric sans for UI.

Layout, top to bottom:
1. Top bar: wordmark "ilmu360" on the left, a small near-black pill "Hantar Majlis" on the right.
2. Left-aligned serif headline "Majlis Ilmu Berhampiran Anda".
3. White pill search field with placeholder "Cari majlis, penceramah atau masjid", then a full-width emerald pill button "Cari".
4. Horizontally scrolling pill chips: "Kuliah", "Ceramah", "Kelas", "Kursus".
5. Horizontally scrolling prayer-time slots: "Subuh", "Zohor", "Asar", "Maghrib", "Isyak", with "Maghrib" active in emerald with a thin underline.
6. Two stacked event cards: rounded pattern image area, date badge, then gray placeholder bars for title, speaker, time and mosque. First card's title reads "Kuliah Maghrib".
7. Bottom tab bar with four simple icons and gray placeholder labels.

Text rule: render ONLY the quoted strings as legible text; everything else is gray placeholder bars.

Imagery: abstract Islamic geometric patterns in muted emerald, sand and terracotta. No people, no faces, no Arabic script.

Avoid: gradients, glassmorphism, dark mode, heavy shadows, garbled or misspelled text, photorealistic people, clutter, more than one accent color.
```

### 13.4 Edits for the original A / B / C prompts

If you keep generating the originals, apply these:

1. Prepend the FORMAT line from §13.2 (no device frame, browser chrome or perspective).
2. Add the text rule (only quoted strings legible; all else gray bars).
3. **A:** replace "dark-green pill button" with "pill button in deep emerald #047857 with white text"; add a speaker line to each event card; add prayer-relative time.
4. **B:** state that ink text is `#111111` on `#faf9f6`, and that control outlines use a darker gray (`#8c867e`), not the hairline; keep italics off any Arabic.
5. **C:** replace "warm stone gray" with `#6b645e`; label the stat chips as placeholder if the numbers are not yet real.
6. All: add "No Arabic script or calligraphy anywhere" and use the canonical nav from §10.

---

## 14. Open Decisions

| # | Decision | Recommendation |
|---|---|---|
| 1 | Confirm events-first as the front door | Yes. Trust modules sit beneath. |
| 2 | Nav CTA styling and label | Ink pill. Test **"Hantar Majlis"** against **"Hebahkan Majlis"** (benefit-led). |
| 3 | Font selection and licensing | Fraunces + Figtree + Noto Naskh Arabic. All are open-licensed. |
| 4 | Speaker photos | Allow when speaker-supplied, monogram fallback. |
| 5 | Stat chips | Ship only with live numbers. Otherwise omit. |
| 6 | Mobile bottom tab bar | Adopt; validate with a five-user tap test. |
| 7 | English toggle in v1 | Defer; design for it now (no hard-coded widths). |
| 8 | Ramadan mode | Plan tokens and rail extension now; ship seasonally. |

---

## Appendix A: Original Directions (preserved)

The source specs, unchanged, for reference and for continued image generation.

### A · Headspace-calm — warmth and serenity
- **Canvas** cream `#f9f4f2`; cards white, 24px radius; text warm dark `#2d2c2b`; accent emerald `#047857` for the primary button and small accents only; image palette muted emerald, sand, terracotta; rounded geometric sans; soft shadows only.
- **Layout** slim nav ("ilmu360"; Majlis, Institusi, Penceramah; dark-green pill "Hantar Majlis") → headline **"Majlis Ilmu Berhampiran Anda"** with rounded search → pill chips (Kuliah, Ceramah, Kelas, Kursus) → 3-column event cards (image, date badge, title, mosque, time) → footer strip.
- **Avoid** purple or blue gradients, glassmorphism, dark mode, heavier-than-soft shadows, garbled text, photorealistic people, cluttered layout.

### B · Intercom-editorial — reverent reading
- **Canvas** off-white `#faf9f6`; ink `#111111`; muted `#585858`; hairline borders `#dedbd6`; 4px corners; no shadows; one accent emerald `#047857` (active schedule item, tag punctuation); light sans for UI plus one serif italic voice for quotes and verse references.
- **Layout** minimal bar ("ilmu360"; Majlis, Kitab, Penceramah; solid black "Hantar Majlis") → oversized light headline **"Ketenangan Melalui Ilmu"** with italic serif subline → prayer-anchored schedule strip (Subuh, Zohor, Asar, Maghrib, Isyak) → stacked talk list (date, serif title, speaker with honorific, venue) → kitab references list → minimal footer.
- **Avoid** rounded bubbly cards, gradients, drop shadows, colorful illustration, more than one accent, garbled text, dark mode.

### C · Steep-serif authority — scholars and institutions
- **Canvas** paper white `#ffffff`; ink `#17191c`; muted warm stone gray; regular-weight serif display; large soft cards 24px radius; pill controls; barely-there shadows; single accent emerald `#047857` (chips, active states, one highlight card); clean sans for body and UI.
- **Layout** centered nav ("ilmu360"; black pill "Hantar Majlis") → serif headline **"Dengar. Faham. Amal."** → three scholar cards (circular monogram avatars, names with titles, talk counts, follow buttons) → two-column institution list (mosque and surau names with area labels) → stat chips (1,200+ Majlis, 300 Penceramah, 150 Institusi) → footer. Monograms and geometric fills in warm neutrals; no faces.
- **Avoid** bold heavy headlines, gradients, saturated colors beyond the emerald, photorealistic people, garbled text, dark mode, card-in-card nesting.

### Generation tip (original)
Image models mangle Malay and Arabic script. Zoom into text after generating; if a headline is garbled, re-run with fewer words on screen.
