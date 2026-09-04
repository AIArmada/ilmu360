<?php

namespace App\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventRegistration as PackageEventRegistration;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Registration extends PackageEventRegistration implements AuditableContract
{
    /** @use HasFactory<RegistrationFactory> */
    use AuditsModelChanges, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_occurrence_id',
        'event_session_id',
        'registrant_type',
        'registrant_id',
        'registration_no',
        'registration_type',
        'status',
        'source',
        'total_participants',
        'total_amount',
        'currency',
        'external_order_id',
        'external_order_type',
        'payment_status',
        'registered_at',
        'approved_at',
        'completed_at',
        'cancelled_at',
        'rejected_at',
        'waitlisted_at',
        'refund_pending_at',
        'refunded_at',
        'expired_at',
        'status_reason',
        'notes',
        'parent_registration_id',
        'is_bundle_root',
        'pass_entitlements',
        'metadata',
    ];

    #[\Override]
    protected static function eventModelClass(): string
    {
        return Event::class;
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::deleting(function (Registration $registration): void {
            $registration->attendances()->each(fn ($attendance): bool => (bool) $attendance->delete());
            $registration->answers()->each(fn ($answer): bool => (bool) $answer->delete());
            $registration->items()->each(fn ($item): bool => (bool) $item->delete());
            $registration->participants()->each(function (EventRegistrationParticipant $participant): void {
                $participant->contactMethods()->delete();
                $participant->answers()->each(fn ($answer): bool => (bool) $answer->delete());
                $participant->attendances()->each(fn ($attendance): bool => (bool) $attendance->delete());
                $participant->delete();
            });
            $registration->checkins()->each(fn (EventCheckin $checkin): bool => (bool) $checkin->delete());
        });
    }

    #[\Override]
    protected static function newFactory(): RegistrationFactory
    {
        return RegistrationFactory::new();
    }

    /**
     * @return HasMany<EventCheckin, $this>
     */
    public function checkins(): HasMany
    {
        return $this->hasMany(EventCheckin::class, 'event_registration_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function forRegistrant(Builder $query, Model $registrant): Builder
    {
        return $query
            ->where('registrant_type', $registrant->getMorphClass())
            ->where('registrant_id', (string) $registrant->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): Builder
    {
        return $query
            ->where('registrant_type', $user->getMorphClass())
            ->where('registrant_id', (string) $user->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function forPrimaryContact(Builder $query, ?string $email = null, ?string $phone = null): Builder
    {
        if ($email === null && $phone === null) {
            return $query->whereRaw('0 = 1');
        }

        $registrationTable = $query->getModel()->getTable();
        $participantTable = (new EventRegistrationParticipant)->getTable();

        return $query->whereExists(function ($participantQuery) use ($email, $participantTable, $phone, $registrationTable): void {
            $participantQuery
                ->select(DB::raw('1'))
                ->from("{$participantTable} as primary_participants")
                ->whereColumn('primary_participants.event_registration_id', "{$registrationTable}.id")
                ->where('primary_participants.is_primary', true)
                ->where(function ($contactQuery) use ($email, $phone): void {
                    $contactQuery->whereExists(function ($cmQuery) use ($email, $phone): void {
                        $cmQuery
                            ->select(DB::raw('1'))
                            ->from('contact_methods')
                            ->whereColumn('contact_methods.contactable_id', 'primary_participants.id')
                            ->where('contact_methods.contactable_type', 'event_registration_participant')
                            ->where(function ($typeQuery) use ($email, $phone): void {
                                if ($email !== null) {
                                    $typeQuery->where('contact_methods.type', 'email')
                                        ->where('contact_methods.value', $email);
                                }

                                if ($phone !== null) {
                                    $method = $email !== null ? 'orWhere' : 'where';

                                    $typeQuery->{$method}(function ($q) use ($phone): void {
                                        $q->where('contact_methods.type', 'phone')
                                            ->where('contact_methods.value', $phone);
                                    });
                                }
                            });
                    });
                });
        });
    }

    public function isForUser(User $user): bool
    {
        return parent::getAttribute('registrant_type') === $user->getMorphClass()
            && (string) parent::getAttribute('registrant_id') === (string) $user->getKey();
    }

    public function resolvedUserId(): ?string
    {
        return parent::getAttribute('registrant_type') === $this->userMorphClass()
            ? (string) parent::getAttribute('registrant_id')
            : null;
    }

    public function resolvedName(): ?string
    {
        $name = OwnerContext::withOwner(null, fn (): ?string => $this->resolvePrimaryParticipantName());

        if ($name !== null) {
            return $name;
        }

        $registrant = $this->registrant;

        return $registrant instanceof User ? $this->normalizedString($registrant->name) : null;
    }

    public function resolvedEmail(): ?string
    {
        $email = $this->primaryParticipantContactValue('email');

        if ($email !== null) {
            return $email;
        }

        $registrant = $this->registrant;

        return $registrant instanceof User ? $this->normalizedString($registrant->email) : null;
    }

    public function resolvedPhone(): ?string
    {
        $phone = $this->primaryParticipantContactValue('phone');

        if ($phone !== null) {
            return $phone;
        }

        $registrant = $this->registrant;

        return $registrant instanceof User ? $this->normalizedString($registrant->phone) : null;
    }

    public function statusValue(): string
    {
        $status = $this->getAttribute('status');

        if (is_string($status)) {
            return $status;
        }

        if (is_object($status) && method_exists($status, 'getValue')) {
            $value = $status->getValue();

            return is_string($value) ? $value : (string) $value;
        }

        return (string) $status;
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function primaryParticipantContactValue(string $key): ?string
    {
        /** @var EventRegistrationParticipant|null $participant */
        $participant = OwnerContext::withOwner(null, fn (): ?EventRegistrationParticipant => $this->resolvePrimaryParticipant());

        if (! $participant instanceof EventRegistrationParticipant) {
            return null;
        }

        $value = OwnerContext::withOwner(null, fn (): mixed => $participant->contactMethodsOfType($key)->where('is_primary', true)->value('value')
            ?? $participant->contactMethodsOfType($key)->value('value'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function userMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
