# ilmu360° MCP CSV / JSON Event Creation Playbook

Updated: May 5, 2026  
Audience: MCP consumers creating events from spreadsheet-style sources.

This guide explains the **human-safe workflow** for creating events from CSV/JSON payloads through MCP tools.

The main risk is not schema validation failure. The main risk is creating **wrong-but-valid records** (wrong speaker, wrong reference, duplicate events, outdated corrections).

---

## Source-of-truth precedence

Use this precedence order:

1. Latest corrected file version.
2. User's latest manual correction message.
3. Older file versions (for audit context only, not creation).

When a corrected file appears, stop using old versions.

If the user later corrects a row after upload, that correction overrides file content.

Example correction:

`2026-05-10 Selepas Maghrib Us Khafiz Tohfah Kuliah Maghrib Misbahul Munir`

Interpretation:

- Speaker: `Us Khafiz Tohfah`
- Reference: `Misbahul Munir`
- Do not split `Tohfah` out as a separate reference.

---

## Institution-first resolution

Resolve the institution before building any event payloads.

For masjid schedule imports, do not create a new institution unless no safe match exists.

For this workflow pattern, the expected institution identity is:

- `Masjid Sultan Salahudin Abdul Aziz Shah`
- aliases: `Masjid Biru`, `Masjid Shah Alam`

---

## Row parsing contract

Each source row must map into a clean event object with at least:

- `title`
- `event_date`
- timing mode (`prayer_time` or `custom_time`)
- `event_type`
- speaker
- optional reference
- institution
- publication defaults (`status`, `visibility`, `event_format`)

Example row:

`2026-05-10 | Selepas Maghrib | Us Khafiz Tohfah | Kuliah Maghrib | Kuliah / Ceramah | Misbahul Munir`

Example normalized payload:

```json
{
  "title": "Kuliah Maghrib",
  "event_date": "2026-05-10",
  "prayer_time": "selepas_maghrib",
  "event_type": ["kuliah_ceramah"],
  "speaker": "Mohd Khafiz Tohfah Al-Yamani",
  "reference": "Al-Misbah Al-Munir",
  "institution": "Masjid Sultan Salahudin Abdul Aziz Shah",
  "status": "approved",
  "visibility": "public",
  "event_format": "physical"
}
```

---

## Timing mapping rules

Use prayer-relative timing when the source says phrases like:

- `Selepas Subuh` -> `selepas_subuh`
- `Selepas Maghrib` -> `selepas_maghrib`
- `Sebelum Jumaat` / Friday-before-prayer context -> `sebelum_jumaat`
- `Selepas Jumaat` -> `selepas_jumaat`
- `Selepas Isyak` -> `selepas_isyak`

For specific clock times, use:

- `prayer_time: lain_waktu`
- `custom_time: HH:MM`

Friday-specific rule:

- For `Kuliah Jumaat`, do not auto-map to `selepas_zuhur`.
- Prefer `prayer_time: sebelum_jumaat` plus matching type/title context.

---

## Event type mapping rules

Use the closest specific type, not `other`, when a known mapping exists.

- `Kuliah / Ceramah` -> `kuliah_ceramah`
- `Kuliah Maghrib` -> `kuliah_ceramah`
- `Kuliah Subuh` -> `kuliah_ceramah`
- `Kuliah Jumaat` -> usually `tazkirah`
- `Majlis Bacaan Yasin` -> `bacaan_yasin`
- `Ta'lim / Majlis Ta'lim` -> `talim`
- `Khutbah Jumaat` -> `khutbah_jumaat`

---

## Speaker resolution workflow

Do not rely on full-name exact match only.

1. Strip honorifics (`Us`, `Ust`, `Ustaz`, `Ustazah`, `Dato`, `Dr`, `Tuan Guru`, `Maulana`).
2. Search distinctive fragments (`Johari`, `Muhadir`, `Tohfah`, `Kamos`, `Jais`, `Khafiz`).
3. Confirm identity safety before assigning the record.

Good match pattern:

- CSV: `Us Khafiz Tohfah`
- Directory: `Mohd Khafiz Tohfah Al-Yamani`

Unsafe match pattern:

- CSV: `Halim Hapiz`
- Directory candidate: `Mohd Hapiz Mahaiyadin`

Shared fragment alone is not proof.

Handle misspellings and transliteration variants carefully:

- `Tohfaz` / `Tohfah`
- `Muhadir` / `Muahdir`
- `Johari` / `Johary`
- `Jafri` / `Jaffri` / `Jeffry`
- `Tirmizi` / `Tarmizi`
- `Nurul Huda` / `Nur Al-Huda`

Only create a new speaker when no safe existing match is available.

---

## Reference resolution workflow

Use fragment-based lookup and canonical reuse, similar to speakers.

Useful fragments include:

- `Idaman`
- `Penuntut`
- `Fiqh Solat`
- `Misbah`
- `Minhajul`
- `Hikam`
- `Mizan`
- `Penawar`
- `Matla`

Canonical reuse examples:

- CSV `Idaman Penuntut` -> existing `Idaman Penuntut - Pada Menghuraikan Yang Kusut (Ilmu Fiqah)`
- CSV `Tasawuf Perlaksanaan` -> existing `Tasawwuf: Perlaksanaan Al-Quran dan Sunnah`
- CSV `Aqidah Ahli Sunnah Wal Jamaah` -> existing `Asas Ahli Sunnah Wal Jamaah`

Do not merge on generic single words like `Fiqh`, `Hadis`, or `Aqidah` only.

Create a new reference only after checking exact, partial, variant, and canonical alternatives.

---

## Prevent speaker/reference confusion

Do not re-interpret tokens from the speaker column as references unless the source structure explicitly places them in the reference column.

Critical example:

- `Us Khafiz Tohfah` is one speaker entity.
- `Tohfah` must not be promoted into a separate reference by default.

---

## Duplicate detection before create

Before writing, scan existing events in the same institution and date window.

Typical conflict dimensions:

- `date`
- `prayer_time`
- `title`
- `speaker`
- `reference`

Same date with different session types may be valid (for example Dhuha vs Maghrib).

---

## Validation and chunked creation

Use a two-phase execution:

1. Build all normalized payloads.
2. Validate all payloads (`validate_only`) in chunks.
3. Confirm zero failures.
4. Create using the same payloads.

Chunk recommendation: 7-10 rows per batch.

Benefits:

- easier error isolation
- safer retries
- cleaner progress reporting
- avoids oversized batch payloads

---

## Default fields for masjid public schedules

Unless instructed otherwise, use:

```json
{
  "status": "approved",
  "visibility": "public",
  "event_format": "physical",
  "gender": "all",
  "age_group": ["all_ages"],
  "timezone": "Asia/Kuala_Lumpur"
}
```

Do not enable registration requirement unless explicitly present in source instructions.

---

## Organizer mapping rule

For masjid schedule imports, organizer should normally be the institution itself.

```json
{
  "organizer_type": "institution",
  "organizer_key": "<institution-route-key>",
  "institution_key": "<institution-route-key>"
}
```

Do not default organizer to speaker unless explicitly requested.

---

## Keep a resolution log

Before create, keep a compact mapping log:

- CSV speaker -> resolved speaker
- CSV reference -> resolved reference
- created-new flags where no safe match existed

This reduces silent mapping mistakes and improves auditability.

---

## Operational checklist

- [ ] Confirm latest file/version
- [ ] Apply latest user corrections
- [ ] Confirm institution identity
- [ ] Parse rows into normalized event payloads
- [ ] Normalize date and timing mode
- [ ] Resolve speakers via fragment search and variant handling
- [ ] Resolve references via fragment search and canonical matching
- [ ] Create only truly missing speakers/references
- [ ] Check duplicate events by institution + date range
- [ ] Validate all event payloads (`validate_only`)
- [ ] Confirm zero validation failures
- [ ] Create in chunks
- [ ] Report totals (created / skipped / errors)

---

## MCP tool routing reference

When executing this playbook through admin MCP:

1. `fetch` -> `docs-admin-mcp-guide` (session preflight)
2. `admin-list-records` (resolve institution, speaker, reference, duplicate checks)
3. `admin-get-write-schema` (confirm writable fields and enums)
4. `admin-batch-create-events` with `validate_only=true`
5. `admin-batch-create-events` with `validate_only=false` after clean validation

For correction-heavy imports, keep per-chunk payload snapshots so retries are deterministic.
