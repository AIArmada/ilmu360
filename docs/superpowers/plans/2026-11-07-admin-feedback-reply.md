# Admin Feedback Reply Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let global admins reply to an app feedback thread from the Filament view page and email the reporter.

**Architecture:** Reuse the existing `app_feedback_messages` thread, `PostAppFeedbackMessageAction`, and `reply` policy. The action dispatches a queued mail notification when a maintainer posts a reporter-visible message; the Filament view gains a `Balas` header action that calls the same action. No schema changes, no status transitions, no ticket machinery.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest 5, Laravel notifications (queued mail).

**Spec:** Inline design approved in session 2026-11-07. Current behavior: `app/Actions/AppFeedback/PostAppFeedbackMessageAction.php`, `app/Filament/Resources/AppFeedback/Pages/ViewAppFeedback.php`, `app/Notifications/AppFeedbackTriagedNotification.php`.

## Global Constraints

- Reuse `AppFeedbackMessage`, `PostAppFeedbackMessageAction`, and `AppFeedbackPolicy::reply()`; do not add tables or columns.
- Notifications: `ShouldQueue`, `afterCommit()`, mail only, `notifications-mail` queue, failures logged with `Log::warning` and never block the reply.
- Only `author_role = maintainer` + `visibility = reporter` messages email the reporter; reporter replies and self-replies never email.
- Never email diagnostics, internal notes, or evidence — only the feedback title, reply body, status, and thread link.
- Translations in both `resources/lang/en/` and `resources/lang/ms/`.
- PHP 8.5 conventions: explicit return types, curly braces, constructor property promotion where used.
- After PHP edits: `vendor/bin/pint --dirty --format agent`; PHPStan level 6 must pass for touched files.
- Ask before running any `git commit` (AGENTS.md requires per-command approval).

## Review Focus

- Reporter replying must not trigger mail — pinned by Task 2 test `does not email the reporter when they reply`.
- A maintainer replying to their own feedback must not email themselves — pinned by Task 2 test `does not email a maintainer about their own reply`.
- Mail delivery failure must not lose the saved message — pinned by Task 2 test `still saves the message when the reply email fails`.
- Replies on Resolved/Declined feedback stay blocked — pinned by the existing `rejects replies to closed feedback` test in `AppFeedbackThreadTest.php`.
- The email must link to the correct thread anchor even when feedback is not on page one — pinned by Task 1's `actionUrl` assertion.

---

### Task 1: Reply notification and copy

**Files:**
- Create: `app/Notifications/AppFeedbackRepliedNotification.php`
- Create: `tests/Feature/AppFeedback/AppFeedbackReplyNotificationTest.php`
- Modify: `resources/lang/en/notifications.php` (`app_feedback` block, after `triaged`)
- Modify: `resources/lang/ms/notifications.php` (`app_feedback` block, after `triaged`)

**Interfaces:**
- Consumes: `AppFeedback`, `AppFeedbackMessage`, `notifications.mail.greeting`, `notifications.mail.generic_recipient`.
- Produces: `AppFeedbackRepliedNotification::__construct(AppFeedback $feedback, AppFeedbackMessage $message)`, `toMail(object $notifiable): MailMessage`, `viaQueues(): array`, `actionUrl(): string` (thread anchor).

- [ ] **Step 1: Write the failing notification test**

```php
use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use App\Models\User;
use App\Notifications\AppFeedbackRepliedNotification;

it('builds the reply email with the message and thread link', function () {
    $reporter = User::factory()->create(['name' => 'Aminah']);
    $feedback = AppFeedback::factory()->create([
        'reporter_id' => $reporter->getKey(),
        'title' => 'Butang simpan gagal',
    ]);

    $message = AppFeedbackMessage::factory()->create([
        'feedback_id' => $feedback->getKey(),
        'author_role' => 'maintainer',
        'visibility' => 'reporter',
        'body' => 'Kami sedang menyiasat isu ini.',
    ]);

    $mail = (new AppFeedbackRepliedNotification($feedback, $message))->toMail($reporter);

    expect($mail->subject)->toContain('Butang simpan gagal')
        ->and(implode("\n", $mail->introLines))->toContain('Kami sedang menyiasat isu ini.')
        ->and($mail->actionUrl)->toBe(
            route('app-feedback.index', ['lihat' => $feedback->getKey()]).'#maklum-balas-'.$feedback->getKey()
        );
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackReplyNotificationTest.php`
Expected: FAIL — class `AppFeedbackRepliedNotification` not found.

- [ ] **Step 3: Implement `AppFeedbackRepliedNotification`**

Mirror `AppFeedbackTriagedNotification`: `ShouldQueue` + `Queueable`, `afterCommit()` in the constructor, `via()` returns `['mail']`, `viaQueues()` maps `mail` to `notifications-mail`. `toMail()` uses subject `notifications.app_feedback.replied.subject` with `['title' => $this->feedback->title]`, the shared greeting helper, intro line, reply label line, `->line($this->message->body)`, status line (`$this->feedback->status->getLabel()`), action `notifications.app_feedback.replied.action` pointing at the thread anchor, and footer with `config('app.name')`.

- [ ] **Step 4: Add `notifications.app_feedback.replied` copy**

English: `subject` = `New reply on your feedback: :title`, `intro` = `Our team has replied to your feedback.`, `reply_label` = `Reply:`, `status` = `Current status: :status`, `action` = `View reply`, `footer` = `Thank you for helping us improve :app.`

Malay: `subject` = `Balasan baharu untuk maklum balas anda: :title`, `intro` = `Pasukan kami telah membalas maklum balas anda.`, `reply_label` = `Balasan:`, `status` = `Status semasa: :status`, `action` = `Lihat balasan`, `footer` = `Terima kasih kerana membantu kami menambah baik :app.`

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackReplyNotificationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit (ask first)**

```bash
git add app/Notifications/AppFeedbackRepliedNotification.php tests/Feature/AppFeedback/AppFeedbackReplyNotificationTest.php resources/lang/en/notifications.php resources/lang/ms/notifications.php
git commit -m "feat(feedback): add reporter reply notification"
```

---

### Task 2: Dispatch the email from the message action

**Files:**
- Modify: `app/Actions/AppFeedback/PostAppFeedbackMessageAction.php`
- Test: `tests/Feature/AppFeedback/AppFeedbackThreadTest.php`

**Interfaces:**
- Consumes: `AppFeedbackRepliedNotification` from Task 1.
- Produces: `PostAppFeedbackMessageAction::handle()` now dispatches the reply notification for maintainer-visible messages; signature unchanged.

- [ ] **Step 1: Write the failing tests**

Add to `AppFeedbackThreadTest.php` (and seed roles with `beforeEach(fn () => $this->seed(\Database\Seeders\RoleSeeder::class));`). Add `use App\Notifications\AppFeedbackRepliedNotification;` and `use Illuminate\Support\Facades\Notification;` to the imports:

```php
it('notifies the reporter when a maintainer replies', function () {
    Notification::fake();

    $reporter = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    $message = app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Kami sedang menyiasat.');

    expect($message->author_role)->toBe('maintainer');

    Notification::assertSentTo($reporter, AppFeedbackRepliedNotification::class);
});

it('does not email the reporter when they reply', function () {
    Notification::fake();

    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    app(PostAppFeedbackMessageAction::class)->handle($reporter, $feedback, 'Maklumat tambahan.');

    Notification::assertNothingSent();
});

it('does not email a maintainer about their own reply', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create(['reporter_id' => $admin->getKey()]);

    app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Balasan kepada diri sendiri.');

    Notification::assertNothingSent();
});

it('still saves the message when the reply email fails', function () {
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('mail transport down'));
    Log::shouldReceive('warning')->once();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $feedback = AppFeedback::factory()->create(['reporter_id' => User::factory()->create()->getKey()]);

    $message = app(PostAppFeedbackMessageAction::class)->handle($admin, $feedback, 'Balasan ujian.');

    expect($message->exists)->toBeTrue()
        ->and($feedback->messages()->count())->toBe(1);
});
```

Add `use Illuminate\Support\Facades\Log;` to the same imports.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackThreadTest.php`
Expected: FAIL — `AppFeedbackRepliedNotification` is never sent.

- [ ] **Step 3: Implement the dispatch**

After `$message->save()`, call a private `notifyReporterOfReply(AppFeedback $feedback, AppFeedbackMessage $message, User $author): void`. It returns unless `$message->author_role === 'maintainer'`, `$message->visibility === 'reporter'`, `$feedback->reporter instanceof User`, and the reporter key differs from the author key. It wraps `Notification::send($reporter, new AppFeedbackRepliedNotification($feedback, $message))` in `try/catch (Throwable)` and logs `Unable to send app feedback reply notification.` with `feedback_id` and `error`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackThreadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit (ask first)**

```bash
git add app/Actions/AppFeedback/PostAppFeedbackMessageAction.php tests/Feature/AppFeedback/AppFeedbackThreadTest.php
git commit -m "feat(feedback): email reporters on maintainer replies"
```

---

### Task 3: Filament reply action

**Files:**
- Modify: `app/Filament/Resources/AppFeedback/Pages/ViewAppFeedback.php`
- Modify: `resources/lang/en/app-feedback.php` (`admin` block)
- Modify: `resources/lang/ms/app-feedback.php` (`admin` block)
- Test: `tests/Feature/AppFeedback/AppFeedbackAdminTest.php`

**Interfaces:**
- Consumes: `PostAppFeedbackMessageAction::handle(User $author, AppFeedback $feedback, string $body): AppFeedbackMessage`; `AppFeedbackPolicy::reply()`; `AppFeedbackStatus::New|Triaged`.
- Produces: Filament header action named `reply` with a required `body` field (3–2000 chars).

- [ ] **Step 1: Write the failing admin test**

Add to `AppFeedbackAdminTest.php` (and add `use App\Notifications\AppFeedbackRepliedNotification;` to the imports):

```php
it('replies to feedback from the admin view action', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $reporter = User::factory()->create();
    $feedback = AppFeedback::factory()->create(['reporter_id' => $reporter->getKey()]);

    $this->actingAs($admin);

    Livewire::test(ViewAppFeedback::class, ['record' => $feedback->getRouteKey()])
        ->callAction('reply', ['body' => 'Kami sedang menyiasat isu ini.'])
        ->assertHasNoActionErrors();

    $message = $feedback->messages()->sole();

    expect($message->author_role)->toBe('maintainer')
        ->and($message->body)->toBe('Kami sedang menyiasat isu ini.');

    Notification::assertSentTo($reporter, AppFeedbackRepliedNotification::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackAdminTest.php`
Expected: FAIL — no `reply` action exists.

- [ ] **Step 3: Implement `replyAction()`**

In `ViewAppFeedback`, add `$this->replyAction()` first in `getHeaderActions()`. The action is `Action::make('reply')`, label `app-feedback.admin.reply`, icon `Heroicon::OutlinedChatBubbleLeftRight`, visible when the record status is `New` or `Triaged` and `auth()->user()?->can('reply', $this->feedback()) === true`. Schema: `Textarea::make('body')` with label `app-feedback.admin.reply_body`, helper `app-feedback.admin.reply_help`, `required()`, `minLength(3)`, `maxLength(2000)`, `rows(4)`. In `->action()`, resolve the actor, call `$postAppFeedbackMessageAction->handle($user, $this->feedback(), (string) $data['body'])`, call `$this->refreshFeedback()`, and send a success `Notification` titled `app-feedback.admin.reply`.

Add imports for `App\Actions\AppFeedback\PostAppFeedbackMessageAction` and keep the existing `Filament\Notifications\Notification` import.

- [ ] **Step 4: Add admin copy**

English: `reply` = `Reply`, `reply_body` = `Reply to reporter`, `reply_help` = `The reporter receives this reply by email. Do not include internal notes.`

Malay: `reply` = `Balas`, `reply_body` = `Balas kepada penghantar`, `reply_help` = `Penghantar menerima balasan ini melalui e-mel. Jangan masukkan catatan dalaman.`

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest --compact tests/Feature/AppFeedback/AppFeedbackAdminTest.php`
Expected: PASS.

- [ ] **Step 6: Commit (ask first)**

```bash
git add app/Filament/Resources/AppFeedback/Pages/ViewAppFeedback.php tests/Feature/AppFeedback/AppFeedbackAdminTest.php resources/lang/en/app-feedback.php resources/lang/ms/app-feedback.php
git commit -m "feat(feedback): add admin reply action"
```

---

### Task 4: Full verification

**Files:** none.

- [ ] **Step 1: Run the AppFeedback suite**

Run: `vendor/bin/pest --parallel --compact tests/Feature/AppFeedback`
Expected: PASS, no failures.

- [ ] **Step 2: Run formatting**

Run: `vendor/bin/pint --dirty --format agent`
Expected: passes with no changes.

- [ ] **Step 3: Run static analysis on touched PHP**

Run: `vendor/bin/phpstan analyse app/Actions/AppFeedback/PostAppFeedbackMessageAction.php app/Filament/Resources/AppFeedback/Pages/ViewAppFeedback.php app/Notifications/AppFeedbackRepliedNotification.php tests/Feature/AppFeedback/AppFeedbackThreadTest.php tests/Feature/AppFeedback/AppFeedbackAdminTest.php tests/Feature/AppFeedback/AppFeedbackReplyNotificationTest.php --ansi`
Expected: no errors.
