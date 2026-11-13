<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Services\CommunicationRecorderService;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardProgram;
use App\Models\RewardDigestSlot;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Fixtures for the claim-free Rewards characterization suite (Rw0).
 *
 * These helpers mirror the existing reward digest fixtures but live in a
 * support class so several characterization files share them without
 * global-function collisions.
 */
final class RewardCharacterizationFixtures
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $triggers
     */
    public static function digestUser(array $settings = [], array $triggers = []): User
    {
        $user = User::factory()->create([
            'timezone' => (string) ($settings['timezone'] ?? 'UTC'),
        ]);

        if (array_key_exists('digest_delivery_time', $settings)) {
            config(['notification-center.defaults.digest_delivery_time' => (string) $settings['digest_delivery_time']]);
            unset($settings['digest_delivery_time']);
        }

        if (array_key_exists('digest_weekly_day', $settings)) {
            config(['notification-center.defaults.digest_weekly_day' => (int) $settings['digest_weekly_day']]);
            unset($settings['digest_weekly_day']);
        }

        $sharing = $triggers['share_gratitude_digest'] ?? [];
        $cadence = (string) ($sharing['cadence'] ?? 'daily');
        unset($sharing['cadence']);

        $triggers['share_gratitude_digest'] = array_merge(['enabled' => true], $sharing);

        app(NotificationSettingsManager::class)->save($user, [
            'settings' => $settings,
            'families' => [
                'sharing' => [
                    'enabled' => true,
                    'cadence' => $cadence,
                ],
            ],
            'triggers' => $triggers,
        ]);

        return $user;
    }

    /**
     * @return array{0: RewardProgram, 1: mixed}
     */
    public static function digestProgram(string $prefix, User $user, RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

        EnrollRewardMemberAction::run(
            $program, 'user', (string) $user->getKey(), $version, 'member-portal',
            CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        );

        return [$program, $version];
    }

    /**
     * @return array{0: RewardProgram, 1: mixed}
     */
    public static function activeProgram(string $prefix, RewardProgramMode $mode = RewardProgramMode::Points): array
    {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

        return [$program->refresh(), $version];
    }

    /**
     * @return array{0: RewardAward, 1: FakeRewardSourceFactReader}
     */
    public static function evaluateAward(RewardProgram $program, User $user, string $source, string $at): array
    {
        $memberId = (string) $user->getKey();
        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

        (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            $source, CarbonImmutable::parse($at),
        );

        $award = RewardAward::query()
            ->where('source_business_key', $source)
            ->orderByDesc('created_at')
            ->firstOrFail();

        return [$award, $reader];
    }

    /**
     * @return array{0: RewardAward, 1: FakeRewardSourceFactReader}
     */
    public static function approveAward(RewardProgram $program, User $user, string $source, string $at): array
    {
        [$award, $reader] = self::evaluateAward($program, $user, $source, $at);

        $approved = (new TransitionRewardAwardAction(null, $reader))->handle(
            $award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'staff',
            'verified-ok', null, null, CarbonImmutable::parse($at),
        );

        return [$approved->refresh(), $reader];
    }

    public static function slotFor(User $user): RewardDigestSlot
    {
        return RewardDigestSlot::query()
            ->where('recipient_id', (string) $user->getKey())
            ->orderByDesc('created_at')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function slot(string $status, array $overrides = []): RewardDigestSlot
    {
        $recipientId = (string) Str::uuid();

        return RewardDigestSlot::query()->create(array_merge([
            'recipient_type' => 'user',
            'recipient_id' => $recipientId,
            'local_day' => '2026-06-16',
            'plan_key' => "gratitude:{$recipientId}:2026-06-16",
            'status' => $status,
            'categories' => ['awards'],
            'payload' => [
                'window' => ['from' => '2026-06-14T10:00:00+00:00', 'to' => '2026-06-15T10:00:00+00:00'],
                'total' => 1,
                'text' => 'original digest material',
            ],
            'timezone' => 'UTC',
            'due_at' => CarbonImmutable::parse('2026-06-16T08:00:00Z'),
        ], $overrides));
    }

    public static function failAllDeliveries(RewardDigestSlot $slot, string $reason = 'provider-down'): void
    {
        $slot->refresh();

        $deliveries = CommunicationDelivery::query()
            ->where('communication_id', (string) $slot->communication_id)
            ->orderBy('id')
            ->get();

        foreach ($deliveries as $delivery) {
            app(CommunicationRecorderService::class)->markFailed(
                (string) $slot->communication_id, (string) $delivery->getKey(), $reason,
            );
        }
    }
}
