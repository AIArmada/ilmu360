# App Feedback Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/maklum-balas` produce actionable reports (right page, steps, expected vs actual, screenshot) without raising abandonment.

**Architecture:** Keep Livewire 4 + Filament 5 form + `SubmitAppFeedbackAction` as source of truth; store structured answers in `details` JSON, auto-captured context in `diagnostics`/`page_url`, follow-ups in new `app_feedback_messages`; no new deps.

**Tech Stack:** PHP 8.5, Laravel (see composer.json), Livewire 4, Filament 5, Spatie MediaLibrary v11 (`evidence` collection, `app_feedback_private` disk), Pest 5, Pest TIA wrapper `./pest`.

**Spec:** User brief 2026-11-03 (quality > quantity) + DeepSeek V4.1 Flash max second opinion in session; current behavior in `app/Livewire/Pages/AppFeedback/Index.php:40-231`, `app/Support/AppFeedback/AppFeedbackInput.php:1-225`, `app/Actions/AppFeedback/SubmitAppFeedbackAction.php:1-151`.

## Global Constraints

- PHP `^8.5`, constructor property promotion, explicit return types, curly braces always.
- DB: `uuid('id')->primary()`, `foreignUuid()` with no `->constrained()` / no cascades, `timestampsTz()`, `timestampTz()` for lifecycle stamps, migration `up()` only idempotent (`hasTable`/`hasColumn` guard), no `down()` change.
- No `SoftDeletes` / `softDeletes()`; use `KeepsDeletedModels` only where already used.
- Filament form truth lives in action validation; keep `Index.php:form()` rules in sync with `AppFeedbackInput::rules()`.
- Pint: `vendor/bin/pint --dirty --format agent` after PHP edits; PHPStan level 6: `vendor/bin/phpstan analyse --ansi` must pass.
- Tests: Pest 5, run narrowest set via `./pest --parallel --compact <path>` or `--filter`, TIA via `./pest --parallel --tia --compact` for final gate.
- Privacy: evidence only reporter + global admin (`AppFeedbackEvidenceController.php:19-25`, `AppFeedbackPolicy.php:17-25`); never expose query strings/fragments; never pass raw `$request` to signals.

## Review Focus

- Retry with same `submission_key` but changed auto-captured `page_url`/context must not throw `submission_changed` → pinned by Task 2 Step 4 (`payloadHash` excludes `page_url`/diagnostics) + existing `returns the same record when the same submission is retried` in `AppFeedbackSubmissionTest.php:182-200`.
- Private path (e.g. `/dashboard/...`) stored path-only must never leak to another user via history, signals, or GitHub escalation → pinned by Task 1 Step 1 + existing `shows only the authenticated member feedback history` in `AppFeedbackSubmissionTest.php:337-356` and Task 6 signals sanitization.
- Evidence download URL with another user's `mediaId` or non-numeric id must 404, never 403-leak existence → pinned by existing `does not expose evidence to other users` in `AppFeedbackSubmissionTest.php:294-335`, kept green by Task 3 Step 5.
- Quota-exceeded (3/hour rolling) must show as form-level banner near submit, not under `category` → pinned by Task 4 Step 3 (`$quotaError` banner) + existing `limits each user to three new submissions per hour` in `AppFeedbackSubmissionTest.php:160-180`.
- `details` key order / surrounding whitespace must not change `payload_hash` and cause false duplicate rejection → pinned by Task 2 Step 4 (`ksort`+trim before hash) + new `stores structured bug details` test asserting retry with reordered keys returns same record.

---

### Task 1: Context capture fix (highest ROI, no schema)

**Files:**
- Modify: `app/Support/AppFeedback/AppFeedbackInput.php:119-196`
- Modify: `app/Livewire/Pages/AppFeedback/Index.php:58-66,198-211`
- Modify: `resources/views/layouts/app.blade.php:386,549,697`
- Modify: `resources/lang/ms/app-feedback.php`, `resources/lang/en/app-feedback.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackPrivacyTest.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php`

**Interfaces:**
- Consumes: `AppFeedbackInput::sanitizePageUrl(?string): ?string`, `Index::defaultFormState(Request): array`.
- Produces: `AppFeedbackInput::sanitizePageUrl()` with allow-private-paths policy + `Index::mount(Request $request)` accepting `?dari=/path` (sanitized, referer fallback) used by layout links.

- [ ] **Step 1: Write failing test for private-path context preserved path-only**

```php
it('preserves dashboard path without query for context', function () {
    $url = \App\Support\AppFeedback\AppFeedbackInput::sanitizePageUrl('https://example.test/dashboard/majlis/pendaftaran?token=abc#top');
    expect($url)->toBe('https://example.test/dashboard/majlis/pendaftaran');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackPrivacyTest.php --filter="preserves dashboard"`
Expected: FAIL (currently returns null for `/dashboard` prefix).

- [ ] **Step 3: Implement `sanitizePageUrl` policy fix in `app/Support/AppFeedback/AppFeedbackInput.php`**

Keep stripping query/fragment/control chars; block only token-bearing routes (`/pas/`, `/pendaftaran/{token}`, `/refund/`, `/jemputan/`, `/oauth`, `/password`, etc.); allow authenticated private paths path-only. Fix host handling: prefer `$request->getHttpHost()`-relative path storage over `config('app.url')` rewrite. Update `isBlockedPath()` accordingly.

- [ ] **Step 4: Wire `?dari=` through layout links and `Index::mount`**

Change 3 layout links to `route('app-feedback.index', ['dari' => request()->path()])`; in `mount()`/`defaultFormState()` read `dari` first (sanitize), fallback to `referer` header. Show as read-only chip in form (not editable `TextInput`), keep hidden `page_url` value.

- [ ] **Step 5: Update privacy tests + run**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/`
Expected: PASS. Update `AppFeedbackPrivacyTest.php:25-29` expectation deliberately with comment.

- [ ] **Step 6: Commit**

```bash
git add app/Support/AppFeedback/AppFeedbackInput.php app/Livewire/Pages/AppFeedback/Index.php resources/views/layouts/app.blade.php tests/Feature/AppFeedback/
git commit -m "feat(feedback): preserve private-path context for quality reports"
```

### Task 2: Structured `details` JSON per category (1 nullable column)

**Files:**
- Create: `database/migrations/2026_11_03_000001_add_details_to_app_feedback_table.php`
- Modify: `app/Models/AppFeedback.php:64-70,89-107`
- Modify: `app/Support/AppFeedback/AppFeedbackInput.php:12-70,94-117`
- Modify: `app/Actions/AppFeedback/SubmitAppFeedbackAction.php:42-50`
- Modify: `app/Livewire/Pages/AppFeedback/Index.php:68-123`
- Modify: `database/factories/AppFeedbackFactory.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php`

**Interfaces:**
- Consumes: Task 1 `sanitizePageUrl`.
- Produces: `AppFeedback::$details: ?array` (cast `array`, fillable), `AppFeedbackInput::$details: array` validated as restricted-keys array, included (sorted/trimmed) in `payloadHash()`; form emits `data.details.*` per category.

- [ ] **Step 1: Write failing test for structured bug details round-trip**

```php
it('stores structured bug details without concatenation', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);
    \Livewire\Livewire::test(\App\Livewire\Pages\AppFeedback\Index::class)
        ->fillForm(['category' => 'bug', 'title' => 'Butang simpan gagal', 'description' => 'Menekan simpan tiada respons di halaman majlis.', 'details' => ['expected' => 'Rekod tersimpan', 'steps' => "1. Buka majlis\n2. Tekan simpan"]])
        ->call('submit')->assertHasNoFormErrors();
    expect(\App\Models\AppFeedback::sole()->details['expected'])->toBe('Rekod tersimpan');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php --filter="structured bug"`
Expected: FAIL (`details` column / form field missing).

- [ ] **Step 3: Add migration (up-only, idempotent)**

`$table->json('details')->nullable()` (use `json`, consistent with `diagnostics`); guard with `hasColumn`.

- [ ] **Step 4: Implement model + DTO + action + form**

Model: fillable + `casts()['details'=>'array']`. DTO: `details` prop, `rules()` => `['details'=>'nullable|array','details.*'=>'nullable|string|max:2000']` + per-category required (`expected` for bug, `problem` for feature) via `sometimes` closure; `fromValidated` trims, drops empties, `ksort`; `payloadHash()` hashes sorted `details` + title/category/description + evidence manifests, excludes volatile `page_url`/diagnostics. Form: `details.*` textareas shown per category (`visible(fn(Get $get)=>$get('category')===...)`), minimal set only.

- [ ] **Step 5: Run tests + phpstan + pint**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/` then `vendor/bin/phpstan analyse --ansi` then `vendor/bin/pint --dirty --format agent`
Expected: PASS, no new PHPStan errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_03_000001_add_details_to_app_feedback_table.php app/Models/AppFeedback.php app/Support/AppFeedback/AppFeedbackInput.php app/Actions/AppFeedback/SubmitAppFeedbackAction.php app/Livewire/Pages/AppFeedback/Index.php database/factories/AppFeedbackFactory.php tests/
git commit -m "feat(feedback): add structured details for actionable reports"
```

### Task 3: Evidence paste/preview (no schema)

**Files:**
- Modify: `app/Livewire/Pages/AppFeedback/Index.php:101-119`
- Modify: `resources/views/livewire/pages/app-feedback/index.blade.php:140-154`
- Modify: `app/Models/AppFeedback.php:125-135`
- Test: `tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php`

**Interfaces:**
- Consumes: Task 2 form structure.
- Produces: Evidence upload with preview + paste + imageEditor; history shows image thumbs via controller (PDFs stay download-only).

- [ ] **Step 1: Write failing test for pasted screenshot accepted**

```php
it('accepts pasted evidence upload', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);
    \Livewire\Livewire::test(\App\Livewire\Pages\AppFeedback\Index::class)
        ->fillForm(['category' => 'bug', 'title' => 'Ralat paparan', 'description' => 'Paparan pecah pada skrin kecil, lihat lampiran.', 'evidence' => [\Illuminate\Http\UploadedFile::fake()->image('paste.png')]])
        ->call('submit')->assertHasNoFormErrors();
    expect(\App\Models\AppFeedback::sole()->getMedia('evidence'))->toHaveCount(1);
});
```

- [ ] **Step 2: Run to verify baseline**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php --filter="pasted evidence"`
Expected: PASS already (proves no regression); keep as guard.

- [ ] **Step 3: Implement `SpatieMediaLibraryFileUpload` flips in `Index.php`**

`previewable()->pasteable()->imageEditor()->reorderable()`; keep `multiple()->maxFiles(8)`, mime + private disk + `storeFiles(false)`; dynamic helper nudging screenshot for `bug`/`ui_ux` (no hard require).

- [ ] **Step 4: Add `thumb` conversion + inline history thumbs**

`registerMediaConversions()`: `thumb` 1080² webp for images; blade: image mime shows `<img>` via existing download route with `preview` query, PDFs stay pill links; keep `nosniff`.

- [ ] **Step 5: Run tests**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Pages/AppFeedback/Index.php app/Models/AppFeedback.php resources/views/livewire/pages/app-feedback/index.blade.php tests/
git commit -m "feat(feedback): paste and preview evidence for better bug reports"
```

### Task 4: Inline guards + quota UX + drafts (no schema)

**Files:**
- Modify: `app/Livewire/Pages/AppFeedback/Index.php:125-151,213-225`
- Modify: `resources/views/livewire/pages/app-feedback/index.blade.php:36-60`
- Modify: `app/Actions/AppFeedback/SubmitAppFeedbackAction.php:71-80`
- Test: `tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php`

**Interfaces:**
- Consumes: Tasks 1-3 form.
- Produces: Targeted low-effort checks, form-level quota banner, `sessionStorage` draft autosave cleared on success.

- [ ] **Step 1: Write failing test for description-equals-title rejected**

```php
it('rejects description that repeats the title', function () {
    $user = \App\Models\User::factory()->create();
    $data = ['submission_key' => (string) \Illuminate\Support\Str::uuid(), 'category' => 'bug', 'title' => 'Butang rosak', 'description' => 'Butang rosak'];
    expect(fn () => app(\App\Actions\AppFeedback\SubmitAppFeedbackAction::class)->handle($user, $data, request()))->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackSubmissionTest.php --filter="repeats the title"`
Expected: FAIL (currently `min:10` passes `Butang rosak` 12 chars).

- [ ] **Step 3: Implement targeted checks + error placement + quota hint**

Action: reject normalized `description == title` or `<4 words` for `bug`; keep `min:10` floor. `Index::mapValidationErrors()`: keep field errors on fields, render `quota`/`submission_key` as form-level banner (`$quotaError` public prop) near submit in blade. Show remaining quota (3/hour rolling) helper. Add `sessionStorage` draft autosave via Alpine (`x-data`, save `data` on input, clear on `successMessage`).

- [ ] **Step 4: Run tests**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Pages/AppFeedback/Index.php app/Actions/AppFeedback/SubmitAppFeedbackAction.php resources/views/livewire/pages/app-feedback/index.blade.php tests/
git commit -m "feat(feedback): targeted quality guards and quota UX"
```

### Task 5: Reporter follow-up thread (new table, only required schema)

**Files:**
- Create: `database/migrations/2026_11_03_000002_create_app_feedback_messages_table.php`
- Create: `app/Models/AppFeedbackMessage.php`
- Modify: `app/Models/AppFeedback.php` (hasMany messages)
- Create: `app/Actions/AppFeedback/PostAppFeedbackMessageAction.php`
- Modify: `app/Livewire/Pages/AppFeedback/Index.php` + `resources/views/livewire/pages/app-feedback/index.blade.php` (timeline + reply box)
- Modify: `app/Policies/AppFeedbackPolicy.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackThreadTest.php`

**Interfaces:**
- Consumes: `AppFeedback` identity + Task 1-4 form.
- Produces: `AppFeedbackMessage {id: uuid, feedback_id: uuid, author_id: ?uuid, author_role: reporter|maintainer|system, visibility: reporter|internal, body: string}` + `PostAppFeedbackMessageAction::handle(User $author, AppFeedback $feedback, string $body): AppFeedbackMessage` + `AppFeedback::visibleMessages()` for reporter view.

- [ ] **Step 1: Write failing test for reporter reply reopens triage**

```php
it('lets reporter reply to own feedback', function () {
    $user = \App\Models\User::factory()->create();
    $feedback = \App\Models\AppFeedback::factory()->create(['reporter_id' => $user->getKey()]);
    $msg = app(\App\Actions\AppFeedback\PostAppFeedbackMessageAction::class)->handle($user, $feedback, 'Maklumat tambahan: berlaku di Chrome.');
    expect($msg->visibility)->toBe('reporter')->and($feedback->messages()->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackThreadTest.php`
Expected: FAIL (table/model/action missing).

- [ ] **Step 3: Create migration + model (up-only, no constraints/cascades)**

`uuid id`, `foreignUuid feedback_id/author_id(nullable)`, `string author_role 16`, `string visibility 16 default reporter`, `text body`, `timestampsTz`, index `(feedback_id,created_at)`. Model `HasUuids`, `BelongsTo feedback/author`, no SoftDeletes.

- [ ] **Step 4: Implement action + policy + Livewire timeline**

Action validates `body required min:3 max:2000`, authorizes reporter-owner or global admin, forces `visibility=reporter` for reporter. Policy `reply()`. Livewire: `visibleMessages` computed, reply form under each `feedback` card when `status` in `new,triaged`; show `resolution_note` + messages chronologically.

- [ ] **Step 5: Run tests + phpstan + pint**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackThreadTest.php` then `vendor/bin/phpstan analyse --ansi`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_03_000002_create_app_feedback_messages_table.php app/Models/AppFeedbackMessage.php app/Models/AppFeedback.php app/Actions/AppFeedback/PostAppFeedbackMessageAction.php app/Policies/AppFeedbackPolicy.php app/Livewire/Pages/AppFeedback/Index.php resources/views/livewire/pages/app-feedback/index.blade.php tests/Feature/AppFeedback/AppFeedbackThreadTest.php
git commit -m "feat(feedback): reporter follow-up thread for clarification"
```

### Task 6: Triage notification + admin/search + signals hygiene (no schema)

**Files:**
- Modify: `app/Actions/AppFeedback/TransitionAppFeedbackAction.php:63-86`
- Modify: `app/Filament/Resources/AppFeedback/Tables/AppFeedbackTable.php`
- Modify: `app/Filament/Resources/AppFeedback/Schemas/AppFeedbackInfolist.php`
- Modify: `app/Services/Signals/ProductSignalsService.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackTransitionTest.php`, `tests/Feature/AppFeedback/AppFeedbackSignalsTest.php`

**Interfaces:**
- Consumes: Tasks 1-5 models.
- Produces: Reporter notified on `triaged`; admin table searchable `description`+`reporter.email` with aging/default-needs-attention filter; signals carry sanitized `has_evidence/details_keys/has_page_url`, never raw URL.

- [ ] **Step 1: Write failing test for triage notification**

```php
it('notifies reporter when triaged', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $admin = \App\Models\User::factory()->create(); $user = \App\Models\User::factory()->create();
    $feedback = \App\Models\AppFeedback::factory()->create(['reporter_id' => $user->getKey()]);
    app(\App\Actions\AppFeedback\TransitionAppFeedbackAction::class)->handle($admin, $feedback, \App\Enums\AppFeedbackStatus::Triaged);
    \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\AppFeedbackReceivedNotification::class);
});
```

Adjust to new `AppFeedbackTriagedNotification` name chosen in implementation; test pins intent.

- [ ] **Step 2: Run to verify it fails**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackTransitionTest.php --filter="triaged"`
Expected: FAIL (today only terminal notifies).

- [ ] **Step 3: Implement notification + table + signals**

Add `AppFeedbackTriagedNotification`, call in `TransitionAppFeedbackAction` when target `triaged`. Table: add `description`, `reporter.email` to searchable, `last_state_change_at` aging (`since()`), default filter `status in [new,triaged]`. Signals: pass sanitized `page_url` host-stripped + flags, never raw `$request->fullUrl()`.

- [ ] **Step 4: Run affected tests**

Run: `./pest --parallel --compact tests/Feature/AppFeedback/AppFeedbackTransitionTest.php tests/Feature/AppFeedback/AppFeedbackSignalsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/AppFeedback/TransitionAppFeedbackAction.php app/Notifications/ tests/ app/Filament/Resources/AppFeedback/ app/Services/Signals/
git commit -m "feat(feedback): triage notification and admin triage hygiene"
```
