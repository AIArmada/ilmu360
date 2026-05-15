<?php

namespace App\Support\Authz;

use AIArmada\FilamentAuthz\Models\AuthzScope;
use Illuminate\Support\Facades\Schema;

final class MemberRoleScopes
{
    private const string SCOPEABLE_TYPE = 'member-role-scope';

    private const string INSTITUTION_SCOPEABLE_ID = '11111111-1111-4111-8111-111111111111';

    private const string SPEAKER_SCOPEABLE_ID = '22222222-2222-4222-8222-222222222222';

    private const string EVENT_SCOPEABLE_ID = '33333333-3333-4333-8333-333333333333';

    private const string REFERENCE_SCOPEABLE_ID = '44444444-4444-4444-8444-444444444444';

    public function institutionId(): string
    {
        return self::INSTITUTION_SCOPEABLE_ID;
    }

    public function speakerId(): string
    {
        return self::SPEAKER_SCOPEABLE_ID;
    }

    public function eventId(): string
    {
        return self::EVENT_SCOPEABLE_ID;
    }

    public function referenceId(): string
    {
        return self::REFERENCE_SCOPEABLE_ID;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            $this->institutionId() => 'Institution Members',
            $this->speakerId() => 'Speaker Members',
            $this->eventId() => 'Event Members',
            $this->referenceId() => 'Reference Members',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function roleResourceOptions(): array
    {
        if (! Schema::hasTable('authz_scopes')) {
            return [];
        }

        return [
            (string) $this->institution()->getKey() => 'Institution Members',
            (string) $this->speaker()->getKey() => 'Speaker Members',
            (string) $this->event()->getKey() => 'Event Members',
            (string) $this->reference()->getKey() => 'Reference Members',
        ];
    }

    public function institution(): AuthzScope
    {
        return $this->ensure($this->institutionId(), 'Institution Members');
    }

    public function speaker(): AuthzScope
    {
        return $this->ensure($this->speakerId(), 'Speaker Members');
    }

    public function event(): AuthzScope
    {
        return $this->ensure($this->eventId(), 'Event Members');
    }

    public function reference(): AuthzScope
    {
        return $this->ensure($this->referenceId(), 'Reference Members');
    }

    private function ensure(string $scopeableId, string $label): AuthzScope
    {
        return AuthzScope::query()->firstOrCreate(
            [
                'scopeable_type' => self::SCOPEABLE_TYPE,
                'scopeable_id' => $scopeableId,
            ],
            [
                'label' => $label,
            ],
        );
    }
}
