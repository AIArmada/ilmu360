<?php

use App\Actions\AppFeedback\EscalateAppFeedbackToGitHubAction;
use App\Actions\AppFeedback\LinkAppFeedbackGitHubIssueAction;
use App\Enums\AppFeedbackGitHubStatus;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Models\AppFeedback;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

function fakeGitHubReporter(bool $fail = false, ?Closure $duringCreate = null): GitHubIssueReporterContract
{
    return new class($fail, $duringCreate) implements GitHubIssueReporterContract
    {
        /** @var list<array{title: string, body: string}> */
        public array $calls = [];

        public function __construct(
            private readonly bool $fail,
            private readonly ?Closure $duringCreate,
        ) {}

        public function isEnabled(): bool
        {
            return true;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function createPlainIssue(string $title, string $body): array
        {
            $this->calls[] = ['title' => $title, 'body' => $body];

            if ($this->duringCreate !== null) {
                ($this->duringCreate)();
            }

            if ($this->fail) {
                throw new RuntimeException('GitHub is unavailable.');
            }

            return [
                'issue' => [
                    'repository' => 'AIArmada/ilmu360',
                    'number' => 123,
                    'title' => $title,
                    'api_url' => 'https://api.github.com/repos/AIArmada/ilmu360/issues/123',
                    'html_url' => 'https://github.com/AIArmada/ilmu360/issues/123',
                    'assigned_to_copilot' => false,
                    'copilot_model' => null,
                    'attempted_models' => [],
                ],
            ];
        }

        public function createAdminIssue(string $title, string $body): array
        {
            throw new RuntimeException('Not used.');
        }
    };
}

it('creates a redacted github issue for a reviewed submission', function () {
    $reporter = fakeGitHubReporter();
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create([
        'title' => 'Tajuk dalaman',
        'description' => 'Butiran dalaman yang tidak boleh dihantar.',
        'page_url' => 'https://ilmu360.test/majlis/contoh',
        'diagnostics' => ['user_agent' => 'Sensitive browser'],
    ]);

    $result = app(EscalateAppFeedbackToGitHubAction::class)->handle(
        $admin,
        $feedback,
        'Butang simpan gagal',
        'Butang simpan pada halaman majlis tidak memberi respons.',
    );

    expect($result->github_escalation_status)->toBe(AppFeedbackGitHubStatus::Created)
        ->and($result->github_issue_number)->toBe(123)
        ->and($result->github_issue_url)->toBe('https://github.com/AIArmada/ilmu360/issues/123')
        ->and($reporter->calls)->toHaveCount(1);

    $body = $reporter->calls[0]['body'];

    expect($reporter->calls[0]['title'])->toBe('Butang simpan gagal')
        ->and($body)->toContain('Butang simpan pada halaman majlis tidak memberi respons.')
        ->and($body)->toContain('ilmu360-app-feedback:'.$result->github_escalation_token)
        ->and($body)->not->toContain($feedback->description)
        ->and($body)->not->toContain('Sensitive browser')
        ->and($body)->not->toContain('https://ilmu360.test/majlis/contoh')
        ->and($body)->not->toContain($feedback->reporter->name)
        ->and($body)->not->toContain($feedback->reporter->email);
});

it('does not create a second github issue for the same feedback', function () {
    $reporter = fakeGitHubReporter();
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    $action = app(EscalateAppFeedbackToGitHubAction::class);
    $action->handle($admin, $feedback, 'Isu pertama', 'Ringkasan isu pertama yang disemak.');

    expect(fn () => $action->handle($admin, $feedback->fresh(), 'Isu kedua', 'Ringkasan isu kedua yang disemak.'))
        ->toThrow(ValidationException::class);

    expect($reporter->calls)->toHaveCount(1);
});

it('keeps local feedback when github escalation fails', function () {
    Log::spy();

    $reporter = fakeGitHubReporter(fail: true);
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    expect(fn () => app(EscalateAppFeedbackToGitHubAction::class)->handle(
        $admin,
        $feedback,
        'Isu gagal',
        'Ringkasan isu yang gagal dihantar ke GitHub.',
    ))->toThrow(ValidationException::class);

    $feedback->refresh();

    expect($feedback->github_escalation_status)->toBe(AppFeedbackGitHubStatus::Unknown)
        ->and($feedback->github_unknown_at)->not->toBeNull()
        ->and($feedback->exists)->toBeTrue();
});

it('refuses github escalation when the integration is not configured', function () {
    $reporter = new class implements GitHubIssueReporterContract
    {
        public int $calls = 0;

        public function isEnabled(): bool
        {
            return false;
        }

        public function isConfigured(): bool
        {
            return false;
        }

        public function createPlainIssue(string $title, string $body): array
        {
            $this->calls++;

            return [];
        }

        public function createAdminIssue(string $title, string $body): array
        {
            $this->calls++;

            return [];
        }
    };

    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->create();

    expect(fn () => app(EscalateAppFeedbackToGitHubAction::class)->handle(
        $admin,
        $feedback,
        'Isu tanpa integrasi',
        'Ringkasan isu tanpa integrasi GitHub.',
    ))->toThrow(ValidationException::class);

    expect($reporter->calls)->toBe(0)
        ->and($feedback->fresh()->github_escalation_status)->toBe(AppFeedbackGitHubStatus::NotRequested);
});

it('links an existing github issue without sending a request', function () {
    $reporter = fakeGitHubReporter();
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->githubUnknown()->create();

    $result = app(LinkAppFeedbackGitHubIssueAction::class)->handle(
        $admin,
        $feedback,
        'https://github.com/AIArmada/ilmu360/issues/321',
    );

    expect($result->github_escalation_status)->toBe(AppFeedbackGitHubStatus::Created)
        ->and($result->github_issue_number)->toBe(321)
        ->and($reporter->calls)->toHaveCount(0);
});

it('rejects a github issue url outside the configured repository', function () {
    $reporter = fakeGitHubReporter();
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->githubUnknown()->create();

    expect(fn () => app(LinkAppFeedbackGitHubIssueAction::class)->handle(
        $admin,
        $feedback,
        'https://github.com/other/repository/issues/321',
    ))->toThrow(ValidationException::class);
});

it('does not replace a linked github issue when passed a stale feedback model', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $feedback = AppFeedback::factory()->githubUnknown()->create();
    $staleFeedback = $feedback->fresh();
    $action = app(LinkAppFeedbackGitHubIssueAction::class);
    $action->handle($admin, $feedback, 'https://github.com/AIArmada/ilmu360/issues/321');

    expect(fn () => $action->handle($admin, $staleFeedback, 'https://github.com/AIArmada/ilmu360/issues/456'))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseHas('app_feedback', [
        'id' => $feedback->getKey(),
        'github_escalation_status' => 'created',
        'github_issue_number' => 321,
        'github_issue_url' => 'https://github.com/AIArmada/ilmu360/issues/321',
    ]);
});

it('preserves a reconciled github issue when an in flight creation succeeds', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $feedback = AppFeedback::factory()->create();

    $reporter = fakeGitHubReporter(duringCreate: function () use ($admin, $feedback): void {
        app(LinkAppFeedbackGitHubIssueAction::class)->handle(
            $admin,
            $feedback,
            'https://github.com/AIArmada/ilmu360/issues/321',
        );
    });
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    $result = app(EscalateAppFeedbackToGitHubAction::class)->handle(
        $admin,
        $feedback,
        'Isu disemak',
        'Ringkasan isu yang telah disemak oleh pentadbir.',
    );

    expect($result->github_issue_number)->toBe(321)
        ->and($reporter->calls)->toHaveCount(1);

    $this->assertDatabaseHas('app_feedback', [
        'id' => $feedback->getKey(),
        'github_escalation_status' => 'created',
        'github_issue_number' => 321,
        'github_issue_url' => 'https://github.com/AIArmada/ilmu360/issues/321',
        'github_unknown_at' => null,
    ]);
});

it('preserves a reconciled github issue when an in flight creation fails', function () {
    Log::spy();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $feedback = AppFeedback::factory()->create();

    $reporter = fakeGitHubReporter(fail: true, duringCreate: function () use ($admin, $feedback): void {
        app(LinkAppFeedbackGitHubIssueAction::class)->handle(
            $admin,
            $feedback,
            'https://github.com/AIArmada/ilmu360/issues/321',
        );
    });
    $this->app->instance(GitHubIssueReporterContract::class, $reporter);

    expect(fn () => app(EscalateAppFeedbackToGitHubAction::class)->handle(
        $admin,
        $feedback,
        'Isu disemak',
        'Ringkasan isu yang telah disemak oleh pentadbir.',
    ))->toThrow(ValidationException::class);

    expect($reporter->calls)->toHaveCount(1);

    $this->assertDatabaseHas('app_feedback', [
        'id' => $feedback->getKey(),
        'github_escalation_status' => 'created',
        'github_issue_number' => 321,
        'github_issue_url' => 'https://github.com/AIArmada/ilmu360/issues/321',
        'github_unknown_at' => null,
    ]);
});
