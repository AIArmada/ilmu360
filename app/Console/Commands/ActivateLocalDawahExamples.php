<?php

namespace App\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralProgram;
use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Support\RewardMaterialTerms;
use AIArmada\Rewards\Support\RewardPublishAssembler;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('app:dawah:activate-local-examples')]
#[Description('Activate the local-only Ilmu360 Dawah points and referral examples.')]
final class ActivateLocalDawahExamples extends Command
{
    private const string POINTS_FAMILY_KEY = 'dawah-points-v1';

    private const string REFERRAL_SLUG = 'ilmu360-dawah-community';

    private const string DEMO_PASSWORD = 'Ilmu360Demo!2026';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This activation command can run only when APP_ENV=local.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(fn (): array => $this->activateExamples());
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Local Dawah example activation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Local Dawah examples active: points=%s, referral=%s, benefit=%s, demo_users=%d.',
            $result['points_program'],
            $result['referral_program'],
            $result['benefit_item'],
            $result['demo_users'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{points_program: string, referral_program: string, benefit_item: string, demo_users: int}
     */
    private function activateExamples(): array
    {
        $admin = User::query()->where('email', 'superadmin@ilmu360.com')->first();

        if (! $admin instanceof User) {
            throw new RuntimeException('The local superadmin account is required to provision the benefit item.');
        }

        $sharer = $this->upsertDemoUser('sharer.demo@ilmu360.test', 'Dawah Demo Sharer');
        $visitor = $this->upsertDemoUser('visitor.demo@ilmu360.test', 'Dawah Demo Visitor');

        $program = RewardProgram::query()->where('family_key', self::POINTS_FAMILY_KEY)->first();

        if (! $program instanceof RewardProgram) {
            $program = app(CreateRewardProgramAction::class)->handle(
                self::POINTS_FAMILY_KEY,
                'Ilmu360 Community Points',
                RewardProgramMode::Points,
                null,
                null,
                'dawah-community',
            );
        } elseif ($program->mode !== RewardProgramMode::Points) {
            throw new RuntimeException('The local Dawah points family already exists with a different mode.');
        }

        $version = $program->versions()->reorderDesc('version')->first();
        $published = RewardPublishAssembler::assemble(RewardProgramMode::Points, $this->publishInput());

        if ($version === null || $version->material_terms_hash !== RewardMaterialTerms::hashForVersion($published['rules'], $published['terms'])) {
            $publishedAt = CarbonImmutable::now('UTC');
            $effectiveFrom = $publishedAt;

            if ($version !== null) {
                $latestEffective = CarbonImmutable::parse($version->effective_from)->setTimezone('UTC');
                $latestPublished = CarbonImmutable::parse($version->published_at)->setTimezone('UTC');
                $publishedAt = $publishedAt->max($latestPublished);
                $effectiveFrom = $publishedAt->max($latestEffective->addSecond());
            }

            app(PublishRewardProgramVersionAction::class)->handle(
                $program,
                $effectiveFrom,
                $published['rules'],
                $published['terms'],
                reason: 'Local Dawah example activation',
                publishedByType: 'system',
                publishedAt: $publishedAt,
            );
        }

        $program->refresh();
        $transition = app(TransitionRewardProgramStatusAction::class);

        if ($program->status === RewardProgramStatus::Draft) {
            $program = $transition->handle($program, RewardProgramStatus::Ready);
        }

        if ($program->status === RewardProgramStatus::Ready || $program->status === RewardProgramStatus::Paused) {
            $program = $transition->handle($program, RewardProgramStatus::Active);
        }

        if ($program->status !== RewardProgramStatus::Active) {
            throw new RuntimeException('The local Dawah points family could not be activated.');
        }

        $benefit = RewardBenefitItem::query()
            ->where('program_id', $program->getKey())
            ->where('benefit_key', 'community-learning-pack')
            ->first();

        if (! $benefit instanceof RewardBenefitItem) {
            $benefit = app(CreateRewardBenefitItemAction::class)->handle(
                $program,
                'community-learning-pack',
                'Community Learning Pack',
                ['Use for one locally managed learning resource.'],
                500,
                'pts',
                false,
                100,
                'user',
                (string) $admin->getKey(),
                'admin',
            );
        }

        $referral = OwnerContext::withOwner(null, function (): ReferralProgram {
            $existing = ReferralProgram::query()->where('slug', self::REFERRAL_SLUG)->first();

            if ($existing instanceof ReferralProgram) {
                if ($existing->status !== ReferralProgramStatus::Active) {
                    throw new RuntimeException(sprintf(
                        'Existing referral program [%s] has status [%s] and cannot be reactivated because the referrals package exposes no supported status transition.',
                        self::REFERRAL_SLUG,
                        $existing->status->value,
                    ));
                }

                return $existing;
            }

            return app(CreateReferralProgram::class)->handle([
                'name' => 'Ilmu360 Community Referral',
                'slug' => self::REFERRAL_SLUG,
                'status' => ReferralProgramStatus::Active,
                'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
                'invite_expiry_days' => 30,
                'max_invites_per_referrer' => 10,
                'max_qualified_per_referrer_per_month' => 5,
                'referrer_reward' => [
                    'driver' => 'reward_points',
                    'points' => 5,
                    'family_key' => self::POINTS_FAMILY_KEY,
                ],
                'referee_reward' => [
                    'driver' => 'reward_points',
                    'points' => 5,
                    'family_key' => self::POINTS_FAMILY_KEY,
                ],
                'currency' => 'MYR',
                'metadata' => [
                    'reward_family_key' => self::POINTS_FAMILY_KEY,
                    'local_example' => true,
                ],
            ]);
        });

        return [
            'points_program' => (string) $program->getKey(),
            'referral_program' => (string) $referral->getKey(),
            'benefit_item' => (string) $benefit->getKey(),
            'demo_users' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishInput(): array
    {
        $roles = ['participant', 'sharer'];
        $outcomes = ['event_registration', 'event_checkin'];
        $milestones = [];

        foreach ([1, 3, 5, 10, 20] as $index => $threshold) {
            $level = $index + 1;

            $milestones[] = [
                'key' => 'bintang-'.$level,
                'label' => 'Bintang '.$level,
                'outcomes' => $outcomes,
                'roles' => $roles,
                // Registration facts are Accepted; check-ins are StaffVerified.
                'min_verification' => 'accepted',
                'first_only' => false,
                'target_count' => $threshold,
                'window_days' => 365,
                'reward_type' => 'points',
                'points' => 5 * $level,
                'unit' => 'pts',
            ];
        }

        return [
            'component_rows' => [[
                'type' => 'points',
                'variants' => [
                    [
                        'outcomes' => ['signup'],
                        'roles' => $roles,
                        'min_verification' => 'accepted',
                        'first_only' => true,
                        'points' => 3,
                        'unit' => 'pts',
                    ],
                    [
                        'outcomes' => ['event_registration'],
                        'roles' => $roles,
                        'min_verification' => 'accepted',
                        'first_only' => false,
                        'points' => 5,
                        'unit' => 'pts',
                    ],
                    [
                        'outcomes' => ['event_checkin'],
                        'roles' => $roles,
                        'min_verification' => 'staff_verified',
                        'first_only' => false,
                        'points' => 15,
                        'unit' => 'pts',
                    ],
                ],
            ]],
            'milestone_rows' => $milestones,
            'eligibility_outcomes' => ['signup', ...$outcomes],
            'eligibility_categories' => [],
            'min_verification' => 'accepted',
            'first_only' => false,
            'exclude_self_credit' => true,
            'require_enrollment' => true,
            'require_claimed_identity' => true,
            'preclaim_lookback_days' => 30,
            'hold_days' => 3,
            'cap_scope_1' => 'recipient_source_component',
            'cap_limit_1' => 1,
            'cap_scope_2' => 'recipient_program_day',
            'cap_limit_2' => 3,
            'cap_scope_3' => 'recipient_program_month',
            'cap_limit_3' => 20,
            'ambiguous_device' => true,
            'manual_review_outcomes' => [],
            'terms_label' => 'Ilmu360 Community Points Terms',
            'terms_version_label' => 'v1',
            'terms_body' => 'Mata ganjaran untuk penglibatan komuniti yang disahkan. Tiada papan kedudukan awam.\n\nPoints recognise verified community participation. There is no public leaderboard.',
            'terms_earn_basis' => 'Pendaftaran dan kehadiran yang layak serta disahkan; check-in mesti disahkan oleh staf. / Eligible registrations and attendance must be verified; check-ins require staff verification.',
            'terms_changes' => 'Perubahan terpakai selepas versi baharu dan penerimaan ahli. / Changes apply through a new version and member reacceptance.',
            'terms_no_ranking' => 'Ganjaran dipaparkan kepada ahli sahaja; tiada ranking awam. / Rewards remain private to members; there is no public ranking.',
        ];
    }

    private function upsertDemoUser(string $email, string $name): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => self::DEMO_PASSWORD,
            'email_verified_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $user;
    }
}
