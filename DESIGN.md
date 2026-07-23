---
name: ilmu360°
description: The trusted directory of verified Islamic speakers and events in Malaysia.
colors:
  emerald-700: "#007a42"
  emerald-800: "#00572e"
  emerald-900: "#00371c"
  emerald-950: "#00190b"
  emerald-500: "#00bb7a"
  emerald-100: "#e0f9ed"
  emerald-50: "#f3fdf8"
  gold-400: "#d9a514"
  gold-500: "#c18200"
  gold-600: "#9c6600"
  gold-100: "#feedc9"
  gold-50: "#fbf4e6"
  slate-900: "#07121e"
  slate-800: "#142332"
  slate-700: "#2a3c4f"
  slate-600: "#42576e"
  slate-500: "#667d94"
  slate-400: "#95a7ba"
  slate-300: "#c7d2de"
  slate-200: "#e0e5eb"
  slate-100: "#f3f5f8"
  slate-50: "#fbfcfd"
  paper: "#fafaf7"
  surface-warm: "#f4f1e8"
typography:
  display:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.5rem, 6vw, 4rem)"
    fontWeight: 700
    lineHeight: 1.06
    letterSpacing: "-0.035em"
  headline:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(1.875rem, 4vw, 3rem)"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.03em"
  title:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "Outfit, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    letterSpacing: "0.20em"
rounded:
  sm: "0.5rem"
  md: "1rem"
  lg: "1.5rem"
  pill: "1.5rem"
  cta: "1.25rem"
spacing:
  xs: "0.5rem"
  sm: "1rem"
  md: "1.25rem"
  lg: "2rem"
  xl: "3rem"
  section: "5rem"
components:
  button-primary:
    backgroundColor: "{colors.emerald-800}"
    textColor: "#FFFFFF"
    rounded: "{rounded.md}"
    padding: "0.875rem 1.25rem"
  button-primary-hover:
    backgroundColor: "{colors.emerald-700}"
    textColor: "#FFFFFF"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.emerald-700}"
    rounded: "{rounded.md}"
    padding: "0.875rem 1.25rem"
  button-ghost-hover:
    backgroundColor: "{colors.emerald-50}"
    textColor: "{colors.emerald-700}"
  input-search:
    backgroundColor: "rgba(255,255,255,0.9)"
    textColor: "{colors.slate-900}"
    rounded: "{rounded.pill}"
    padding: "0.375rem"
  card-verified:
    backgroundColor: "#FFFFFF"
    textColor: "{colors.emerald-950}"
    rounded: "{rounded.lg}"
    padding: "1.25rem"
  card-verified-hover:
    backgroundColor: "#FFFFFF"
    textColor: "{colors.emerald-700}"
  chip-verified:
    backgroundColor: "rgba(224,249,237,0.8)"
    textColor: "{colors.emerald-800}"
    rounded: "9999px"
    padding: "0.5rem 0.875rem"
---

# Design System: ilmu360°

## 1. Overview

**Creative North Star: "The Golden Directory"**

ilmu360° is the trusted, curated directory of verified Islamic speakers and events in Malaysia. The visual system is premium, luminous, and warm — a reference platform that feels authoritative without being cold, scholarly without being austere. Every surface should feel like it was hand-curated: the gold accents signal value, the emerald core signals trust, and the warm paper background carries the Islamic scholarly tradition into a modern interface.

The system rejects generic SaaS aesthetics. No blue-gray dashboards, no sterile whites, no flat utilitarian density. Depth comes from warm emerald-tinted glows, not gray drop shadows. Interaction states feel crafted, not mechanical — gold accents appear on hover, cards lift with warm halos, and focus rings carry the brand color. The interface glows with verified knowledge.

This is a product register (functional directory, not marketing), so clarity comes before decoration. But clarity here does not mean austerity — the warm palette, rounded shapes, and luminous depth make the directory feel like a place worth returning to, not a form to fill out.

**Key Characteristics:**
- Warm paper background (#fafaf7) carries the Islamic scholarly tradition without being cream/sand cliché
- Emerald 700–950 as the trust core (verified, authoritative, deep)
- Gold 400–500 as the luminous accent (premium signal, interaction reward)
- Rounded 1.25–1.5rem shapes on primary surfaces (crafted, not sharp)
- Warm emerald-tinted glows replace gray drop shadows on interactive depth
- Outfit font across the system (geometric sans, single family for cohesion)

## 2. Colors: The Golden Directory Palette

The palette pairs a deep emerald core (trust, verification, authority) with a luminous gold accent (premium, curation, reward). Warm neutrals carry the body; cool slate carries text. The emerald is used generously for structure; the gold is used sparingly for emphasis — its rarity is the point.

### Primary

- **Deep Emerald** (#00572e / oklch(0.38 0.14 165)): The trust anchor. Used on verified badges, primary buttons, and the darkest surface in the CTA section. Reads as authoritative Islamic green without being garish.
- **Trust Emerald** (#007a42 / oklch(0.48 0.18 165)): The primary action color. Buttons, links, active nav, and the verified status chip text. The brand's main interactive voice.
- **Emerald Ink** (#00371c / oklch(0.28 0.10 165)): Headline text on light surfaces. Deeper than black, carries the green lineage into typography.

### Secondary

- **Luminous Gold** (#d9a514 / oklch(0.75 0.15 85)): The accent that signals premium. Used on hover underlines, arrow icons on interaction, badge accents, and the "Cadangkan" CTA hover state. Never used for body text or large fills.
- **Royal Gold** (#c18200 / oklch(0.65 0.18 85)): The deeper gold for CTA labels and eyebrow text on dark surfaces. Pairs with emerald-950 backgrounds.

### Neutral

- **Warm Paper** (#fafaf7): The body background. Warm off-white at near-zero chroma — not cream, not sand, not parchment. The canvas the directory sits on.
- **Warm Sand** (#f4f1e8): Secondary surface tone used in hero gradient tails and subtle section transitions.
- **Pure Surface** (#FFFFFF): Cards, inputs, modals. The contrast layer against the warm paper body.
- **Slate Ink** (#07121e / oklch(0.18 0.03 250)): Body text. Cool slate, not pure black, for reduced harshness on warm backgrounds.
- **Slate Muted** (#667d94 / oklch(0.58 0.045 250)): Secondary text, captions, metadata. Maintains 4.5:1 contrast on warm paper.

### Named Rules

**The Luminous Restraint Rule.** Gold covers ≤10% of any screen. It appears on accents, hover states, and verified signals — never as a background fill or body text color. Its rarity is what makes it read as premium.

**The Warm-Not-Cream Rule.** The body background stays at chroma ≈ 0.005–0.012 toward emerald's hue (165), never toward the warm-neutral band (hue 40–100, chroma < 0.06) that reads as cream/sand/parchment. Warmth comes from the hue family of the brand, not from a default warm-neutral tint.

**The Emerald Gradient Floor.** The deepest surface in any composition is emerald-950 (#00190b), used for the community CTA section. It is the visual anchor that grounds the lighter sections above. Never replace with pure black or slate-950.

## 3. Typography

**Display Font:** Outfit (with ui-sans-serif, system-ui fallback)
**Body Font:** Outfit (same family, weight differentiation)

**Character:** A single geometric sans-serif family across the entire system. Outfit carries both display and body — cohesion over contrast. Weight (400 → 700) and tight tracking (-0.02 to -0.035em) provide hierarchy without switching faces. The geometric structure feels modern; the warm emerald ink color ties it to the scholarly tradition.

### Hierarchy

- **Display** (700, clamp(2.5rem, 6vw, 4rem), line-height 1.06, letter-spacing -0.035em): Hero page headlines only. Two lines maximum. Always emerald-950 or emerald-700, never slate.
- **Headline** (700, clamp(1.875rem, 4vw, 3rem), line-height 1.1, letter-spacing -0.03em): Section headers within pages. "Direktori Penceramah", "Hasil carian".
- **Title** (700, 1.125rem, line-height 1.2, letter-spacing -0.02em): Card titles, list item names. Speaker names, event titles.
- **Body** (400, 1rem, line-height 1.6): Paragraphs, descriptions, form labels. Capped at 65–75ch on long-form. Slate-600 on warm paper.
- **Label** (700, 0.75rem, letter-spacing 0.20em, uppercase): Eyebrows and status chips. "DIREKTORI DISAHKAN", "SUMBANGAN KOMUNITI". Used sparingly — one named kicker per page, never on every section.

### Named Rules

**The Single Family Rule.** Outfit is the only typeface. Hierarchy comes from weight, size, and tracking — never from introducing a second family. This is cohesion, not limitation.

**The Display Tracking Floor.** Display letter-spacing never goes below -0.04em. Tighter tracking makes letters touch and reads as cramped, not designed. -0.035em is the floor; -0.02 to -0.03em is the comfort zone.

**The One Kicker Rule.** Tiny uppercase tracked eyebrows (the "01 · ABOUT" pattern) appear on at most one section per page as a deliberate brand signal. Putting them above every section is the saturated AI scaffold and is prohibited.

## 4. Elevation

**Philosophy: Luminous Glow.** Depth in this system is not conveyed by gray drop shadows. Interactive surfaces lift with warm emerald-tinted glows that make the directory feel like it is lit from within — the "luminous" in The Golden Directory. Rest states are flat or carry only a hairline border; depth appears as a response to state (hover, focus, elevation), never as default decoration.

Glow shadows use emerald-tinted rgba (e.g. `rgba(6,78,59,0.40)`) at high blur values (30–80px) and negative spread, creating a halo rather than a hard cast. The glow intensifies on hover. Gold-tinted glows (rgba(217,165,20,...)) appear only on the most premium CTA surfaces.

### Shadow Vocabulary

- **Card Rest** (`box-shadow: 0 8px 30px -20px rgba(15,23,42,0.35)`): Default card state. Barely visible — establishes the card as a surface without decoration.
- **Card Hover Glow** (`box-shadow: 0 22px 50px -28px rgba(6,78,59,0.40)`): The signature hover state. Emerald-tinted halo, card lifts -translate-y-1.5. This is the luminous depth in action.
- **Hero Search Glow** (`box-shadow: 0 20px 60px -28px rgba(6,78,59,0.40), 0 4px 12px -2px rgba(0,0,0,0.04)`): The primary search container. Layered: warm emerald halo + crisp base shadow for definition.
- **Stats Card Glow** (`box-shadow: 0 20px 70px -35px rgba(6,78,59,0.40)`): Trust card in hero. Wider blur, lower opacity — ambient rather than focused.
- **CTA Deep Glow** (`box-shadow: 0 28px 80px -38px rgba(6,78,59,0.85)`): The emerald-950 CTA section. Maximum glow intensity — the section feels anchored and luminous against the warm paper body.

### Named Rules

**The No-Gray-Shadow Rule.** Drop shadows never use pure black or pure gray rgba. Every shadow is tinted toward emerald (interactive surfaces) or warm neutral (structural surfaces). Gray shadows read as SaaS default; tinted shadows read as brand.

**The Glow-On-State Rule.** Shadows appear only on hover, focus, or elevated sections — never as default card decoration. A card at rest carries a hairline border (border-slate-200/80) or nothing. The glow is earned through interaction.

**The One Border Pairing Rule.** An element has either a border OR a shadow, never both as decoration. The "ghost card" pattern (1px border + soft wide drop shadow) is prohibited. Pick one.

## 5. Components

### Buttons

- **Shape:** Rounded medium (1rem / 16px) for standard buttons; full pill (1.5rem) for search container and chips. Never 24px+ on buttons — that reads as over-rounded.
- **Primary:** Emerald-800 background (#00572e), white text, 0.875rem 1.25rem padding, 0.875rem (14px) font-weight 700. Hover: emerald-700 + -translate-y-0.5 + intensified glow shadow. Active: returns to baseline. Focus-visible: 4px emerald-600/10 ring.
- **Ghost / Secondary:** Transparent background, emerald-700 text, 2px emerald-200 border. Hover: emerald-50 background + emerald-300 border + -translate-y-0.5. Used for secondary actions ("Cadangkan penceramah" alongside primary "Lihat semua").
- **CTA Button** (on emerald-950 surface): White background, emerald-900 text, 1.25rem radius. Hover: amber-50 background + arrow translate-x-1.5. This is the luminous inversion — the white button glows against the dark emerald surface.

### Cards (Verified Speaker Card)

- **Corner Style:** Large radius (1.5rem / 24px). Crafted, not sharp.
- **Background:** Pure white (#FFFFFF) against warm paper body. The contrast layer.
- **Border:** Hairline border-slate-200/80 at rest. Never 1px solid + shadow together.
- **Shadow Strategy:** Card Rest at default → Card Hover Glow on hover. Card lifts -translate-y-1.5. Border shifts to emerald-300/80. Title color shifts emerald-950 → emerald-700.
- **Internal Padding:** 1.25rem (20px) on content area. Image fills its container edge-to-edge.
- **Image Treatment:** Object-cover, object-top (portraits). Dot pattern overlay at 35% opacity behind image for fallback. Gradient fade (emerald-950/80 → transparent) at bottom over image. Verified badge top-left, "Penceramah" label bottom-left, arrow bottom-right.
- **Hover Micro-interactions:** Image scales 1.04 over 500ms ease-out. Arrow icons translate-x-1 over 300ms. Title color transitions over 200ms. Staggered across the grid via scroll-reveal with --reveal-d delays.

### Inputs / Search

- **Style:** Pill container (1.5rem radius), white/90 background, hairline white/80 border, backdrop-blur-xl. Icon container: emerald-50 background, emerald-700 icon, 2xl radius (1rem).
- **Focus:** Scale 1.01 + border emerald-300 + 4px emerald-600/10 ring + intensified glow shadow. The entire container breathes on focus.
- **Clear Button:** 10×10 (2.5rem) circular, slate-200 border, slate-400 icon. Hover: rose-50 background + rose-600 icon. Appears only when search is filled.

### Navigation

- **Style:** Top nav with emerald-950 text on transparent/warm-paper background. Active state: emerald-700 text + underline.
- **Mobile:** Hamburger menu, full-screen overlay, emerald-950 text on warm paper.
- **wire:navigate:** All internal links use wire:navigate for SPA-style transitions. Hover states must not shift layout bounds.

### Chips / Status Badges

- **Verified Chip:** emerald-50/80 background, emerald-800 text, full pill, 0.5rem 0.875rem padding, 0.75rem (12px) font-weight 700 uppercase 0.20em tracking. Lead with checkmark icon. Appears top-left on speaker card images.
- **Live Pulse Indicator:** Small emerald dot (1.5–2px) with animate-ping ring. Used in "Direktori Disahkan" eyebrow and "Semua profil disahkan" badge. Signals active curation.

### Signature Component: The Trust Stats Card

The hero's right-column stats card is the signature surface. It carries: emerald icon container with ring, large count number (font-heading 3xl bold emerald-950), two feature items with emerald/amber icon dots. Decorative amber and emerald blur blobs bleed off the corners. The card lifts on hover (-translate-y-1) with intensified glow — the luminous depth in concentrated form. This pattern repeats wherever trust signals need emphasis.

## 6. Do's and Don'ts

### Do:

- **Do** use emerald-tinted glow shadows (`rgba(6,78,59,...)`) for all interactive depth. The glow is the brand.
- **Do** cap gold accents at ≤10% of any screen. Gold is the luminous signal, not a fill color.
- **Do** use warm paper (#fafaf7) as the body background. It carries the scholarly tradition without falling into the cream/sand cliché.
- **Do** pair hairline borders (slate-200/80) OR glow shadows on cards — never both as decoration.
- **Do** lift cards -translate-y-1.5 on hover with an intensified emerald glow. The lift is the interaction reward.
- **Do** use Outfit across the entire system. Weight and tracking provide hierarchy.
- **Do** use the verified chip pattern (emerald-50 bg, emerald-800 text, checkmark icon) consistently wherever verification is signaled.
- **Do** respect `prefers-reduced-motion` — every animation needs a crossfade or instant fallback.

### Don't:

- **Don't** use generic SaaS blue/gray dashboards. The platform must feel distinctly Islamic in aesthetic — emerald and gold, not blue and gray.
- **Don't** use gray drop shadows (rgba(0,0,0,...) or rgba(15,23,42,...) beyond structural base shadows). Gray shadows read as default; tinted shadows read as brand.
- **Don't** use border-left or border-right greater than 1px as a colored accent stripe on cards, list items, or alerts. Side-stripe borders are prohibited.
- **Don't** apply gradient text (background-clip: text + gradient background). Use a single solid color; emphasize via weight or size.
- **Don't** put tiny uppercase tracked eyebrows above every section. One named kicker per page as a deliberate brand signal; more than that is the AI scaffold.
- **Don't** use glassmorphism decoratively (blurs and glass cards as default). Rare and purposeful, or nothing.
- **Don't** use hero-metric templates (big number + small label + supporting stats + gradient accent). SaaS cliché.
- **Don't** use identical card grids with icon + heading + text repeated endlessly. Vary the rhythm.
- **Don't** use border-radius 32px+ on cards, sections, or inputs. Cards top out at 1.5rem (24px); over-rounding reads as inexperienced.
- **Don't** pair 1px border + wide drop shadow (blur ≥16px) on the same element. The ghost-card pattern is prohibited.
- **Don't** use hand-drawn / sketchy SVG illustrations or crudely-drawn scenes. If a real asset cannot be rendered, ship no illustration.
- **Don't** use diagonal stripe backgrounds (repeating-linear-gradient) or decorative grid overlays as default decoration.
- **Don't** use dark patterns or urgency tricks. The trust is earned through verification, not manufactured through pressure.
- **Don't** introduce a second typeface. Outfit is the single family; hierarchy comes from weight and tracking alone.
