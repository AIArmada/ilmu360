<?php

declare(strict_types=1);

namespace App\Features\Communications\Contributors;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\NotificationInbox;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preserves account communication preferences, destinations and inboxes
 * across deletion and restoration. Scoped family and trigger rules remain
 * untouched when the base preference is detached.
 */
final readonly class CommunicationsAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    public function key(): string
    {
        return 'communications';
    }

    public function priority(): int
    {
        return 20;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'notification_setting' => $this->basePreference($accountId)?->attributesToArray(),
            'notification_destinations' => CommunicationDestination::query()
                ->where('recipient_type', $this->userMorphClass())
                ->where('recipient_id', $accountId)
                ->get()
                ->map(static fn (CommunicationDestination $destination): array => $destination->attributesToArray())
                ->all(),
            'notification_inboxes' => NotificationInbox::query()
                ->where('recipient_type', $this->userMorphClass())
                ->where('recipient_id', $accountId)
                ->get()
                ->map(static fn (NotificationInbox $inbox): array => $inbox->attributesToArray())
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->basePreferenceQuery($accountId)->delete();

        CommunicationDestination::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->delete();

        NotificationInbox::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->delete();
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->restoreModel(CommunicationPreference::class, $snapshot['notification_setting'] ?? null);

        foreach ($snapshot['notification_destinations'] ?? [] as $attributes) {
            $this->restoreModel(CommunicationDestination::class, $attributes);
        }

        foreach ($snapshot['notification_inboxes'] ?? [] as $attributes) {
            $this->restoreModel(NotificationInbox::class, $attributes);
        }
    }

    public function afterRestore(string $accountId): void {}

    private function basePreference(string $accountId): ?CommunicationPreference
    {
        return $this->basePreferenceQuery($accountId)->first();
    }

    /**
     * @return Builder<CommunicationPreference>
     */
    private function basePreferenceQuery(string $accountId): Builder
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $this->userMorphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type');
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function restoreModel(string $modelClass, mixed $attributes): void
    {
        if (! is_array($attributes)) {
            return;
        }

        /** @var Model $model */
        $model = new $modelClass;
        $model->timestamps = false;
        $model->forceFill($attributes);
        $model->saveQuietly();
    }

    private function userMorphClass(): string
    {
        return $this->userMorphType->morphClass();
    }
}
