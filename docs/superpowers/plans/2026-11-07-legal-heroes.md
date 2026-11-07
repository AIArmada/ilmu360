# Legal Heroes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `/privasi`, `/terma`, and `/hubungi-kami` separate full-bleed courtyard heroes behind the overlay header.

**Architecture:** Generate one matched imagegen set (3 subjects × desktop + mobile), then wire per-`pageKey` art selection into the shared `pages/legal.blade.php` hero using the existing `hero-field` picture/wash pattern.

**Tech Stack:** Laravel Blade, Tailwind, codex exec built-in imagegen, WebP via PIL, Pest + browser plugin, Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-11-07-legal-heroes-design.md`

## Global Constraints

- One generation per image via codex built-in imagegen, then PNG verify + WebP encode (quality 84, method 6).
- No baked-in text, people, logos, or watermarks in any art; mobile art keeps the left ~60% empty for copy.
- Reuse the directory hero structure (`hero-field` picture + cream washes); keep forced-colors fallback behavior.
- Keep header/footer chrome, tabs, and contact email-reveal/social behavior unchanged.
- Serve WebP with PNG fallback; per-page `og_image` points at the desktop PNG.

## Review Focus

- Unknown `pageKey` still renders a hero (falls back to privacy art) — pinned by Task 2 render test.
- Mobile copy never collides with tabletop props at 390×844 on all three pages — pinned by Task 3 mobile screenshots.
- Missing WebP still shows the PNG fallback — pinned by Task 2 markup test.
- Forced-colors mode hides art and keeps copy legible — pinned by Task 2 markup test.
- Stale `public/build` CSS could mask hero classes — pinned by Task 3 `npm run build` before probes.

---

### Task 1: Generate the six hero images

**Files:**
- Create: `public/images/legal/privacy-hero-desktop-v1.png`, `public/images/legal/privacy-hero-desktop-v1.webp`, `public/images/legal/privacy-hero-mobile-v1.png`, `public/images/legal/privacy-hero-mobile-v1.webp`, `public/images/legal/terms-hero-desktop-v1.png`, `public/images/legal/terms-hero-desktop-v1.webp`, `public/images/legal/terms-hero-mobile-v1.png`, `public/images/legal/terms-hero-mobile-v1.webp`, `public/images/legal/contact-hero-desktop-v1.png`, `public/images/legal/contact-hero-desktop-v1.webp`, `public/images/legal/contact-hero-mobile-v1.png`, `public/images/legal/contact-hero-mobile-v1.webp`

**Interfaces:**
- Consumes: style refs `public/images/institutions/pusat-ilmu-hero-background-v1.webp` + `public/images/institutions/pusat-ilmu-hero-background-mobile-v1.webp`.
- Produces: the 12 files above (desktop ~1672×941 landscape, mobile 2:3 portrait ≈1024×1536).

- [ ] **Step 1: Write the three imagegen prompts to `/tmp/opencode/legal-heroes-prompts.txt`**

Shared world for all three: sunlit Malaysian Islamic courtyard, ivory carved limestone + pointed arch right, white dome + minarets, palms, emerald + brass accents, bright morning light, left 55–60% empty cream wall/floor; subjects: privacy = carved screen + small lockbox (shelter), terms = open book + seal + stacked volumes, contact = welcoming doorway + lantern + letters.

- [ ] **Step 2: Run codex once per page (3 runs) with the two institutions refs attached**

Run: `codex exec --dangerously-bypass-approvals-and-sandbox -C /home/forge/workspaces/aiarmada/ilmu360 --skip-git-repo-check "<page prompt>" -i public/images/institutions/pusat-ilmu-hero-background-v1.webp -i public/images/institutions/pusat-ilmu-hero-background-mobile-v1.webp`
Expected: each run reports two valid PNGs at the exact `public/images/legal/*-v1.png` paths.

- [ ] **Step 3: Verify PNG integrity and dimensions**

Run: `python3 -c "from PIL import Image; [print(p, Image.open(p).size, Image.open(p).format) for p in [...]]"`
Expected: desktops ≈1672×941, mobiles 2:3 portrait, format PNG.

- [ ] **Step 4: Encode the six WebP variants (quality 84, method 6)**

Run: `python3 -c "from PIL import Image; Image.open(src).save(dst, 'WEBP', quality=84, method=6)"` per image.
Expected: six `.webp` siblings next to the PNGs.

- [ ] **Step 5: Commit**

```bash
git add public/images/legal/
git commit -m "Add separate legal hero art for privacy, terms, and contact"
```

### Task 2: Wire per-page heroes into the shared legal template

**Files:**
- Modify: `resources/views/pages/legal.blade.php`
- Test: browser probes (no new permanent test file; layout-only change)

**Interfaces:**
- Consumes: the 12 files from Task 1.
- Produces: `$heroArt` map (`pageKey` → desktop png/webp, mobile webp, width, height, og path) and the hero header block every legal page renders.

- [ ] **Step 1: Write a failing render check proving each page serves its own art**

Run: `vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); visit("/privasi", ["timeout" => 30000])->assertSee("Privasi"); visit("/terma", ["timeout" => 30000])->assertSee("Terma"); visit("/hubungi-kami", ["timeout" => 30000])->assertSee("Hubungi");'`
Expected: FAIL — hero `<picture>` still absent / shared `default-mosque-hero.png` og image.

- [ ] **Step 2: Implement `$heroArt` + hero header in `resources/views/pages/legal.blade.php`**

Signature: `@php $heroArt` match on `$pageKey` (`terms`, `contact`, default `privacy`), each entry `{desktopPng, desktopWebp, mobileWebp, width, height}`; hero block mirrors `resources/views/livewire/pages/app-feedback/index.blade.php:24-72` (outer `data-art-direction="living-majlis"` wrapper stays, top `section` becomes `hero-field` picture + `from-[#fffdf8]/78` wash + mobile wash, content keeps title/intro/updated pill/tabs with `pt-48/sm:pt-52`); `og_image`/`og_image_width`/`og_image_height` read from `$heroArt`.

- [ ] **Step 3: Re-run the render check to verify it passes**

Run: same probe as Step 1 plus `->assertNoJavaScriptErrors()` per page.
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/pages/legal.blade.php
git commit -m "Wire separate heroes into privacy, terms, and contact pages"
```

### Task 3: Verify styling, responsive crop, and suite

**Files:**
- Test: throwaway `--agent` probes + existing suite (no new files)

**Interfaces:**
- Consumes: Tasks 1–2 output.
- Produces: `storage/verify/legal/` evidence + green suite report.

- [ ] **Step 1: Rebuild assets**

Run: `npm run build`
Expected: `public/build/manifest.json` rewritten, no build errors.

- [ ] **Step 2: Capture styled desktop + 390×844 screenshots for all three pages**

Run: `vendor/bin/pest --agent='require base_path(".claude/skills/verify/bin/boot.php"); \Pest\Browser\Playwright\Playwright::setTimeout(30000); $u = \App\Models\User::factory()->create(); $this->actingAs($u); visit("/privasi", ["timeout"=>30000])->assertSee("Privasi")->assertNoJavaScriptErrors()->screenshot(fullPage: false, filename: "verify-legal-privacy"); visit("/terma", ["timeout"=>30000])->assertSee("Terma")->assertNoJavaScriptErrors()->screenshot(fullPage: false, filename: "verify-legal-terms"); visit("/hubungi-kami", ["timeout"=>30000])->assertSee("Hubungi")->assertNoJavaScriptErrors()->screenshot(fullPage: false, filename: "verify-legal-contact"); visit("/hubungi-kami", ["timeout"=>30000])->on()->resize(390, 844)->assertSee("Hubungi")->assertNoJavaScriptErrors()->screenshot(fullPage: false, filename: "verify-legal-contact-mobile");'`
Expected: PASS with 4+ screenshots; eyeball that copy sits on empty cream on mobile.

- [ ] **Step 3: Archive evidence and clean throwaways**

Run: `mkdir -p storage/verify/legal && cp tests/Browser/Screenshots/verify-legal-*.png storage/verify/legal/ && rm -f tests/Browser/Screenshots/verify-legal-*.png`
Expected: timestamped PNGs under `storage/verify/legal/`.

- [ ] **Step 4: Run the affected suite, Pint, and PHPStan**

Run: `grep -rln "legal\|privacy\|terms\|contact" tests/Feature | head` then `./pest --parallel <those files> --compact`; `vendor/bin/pint --dirty --format agent`; `vendor/bin/phpstan analyse --ansi`
Expected: all green, 0 PHPStan errors.
