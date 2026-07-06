<?php

namespace App\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Data\ContactMethodData;
use AIArmada\Events\Models\EventRegistration as PackageEventRegistration;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
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

        static::creating(function (Registration $registration): void {
            $registration->applyLegacyDefaults();
        });

        static::saved(function (Registration $registration): void {
            $registration->syncPrimaryParticipantRecord();
        });

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
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRegistrant(Builder $query, Model $registrant): Builder
    {
        return $query
            ->where('registrant_type', $registrant->getMorphClass())
            ->where('registrant_id', (string) $registrant->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query
            ->where('registrant_type', $user->getMorphClass())
            ->where('registrant_id', (string) $user->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPrimaryContact(Builder $query, ?string $email = null, ?string $phone = null): Builder
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
                    if ($email !== null) {
                        $contactQuery->where('primary_participants.metadata->contact->email', $email);
                    }

                    if ($phone !== null) {
                        $method = $email !== null ? 'orWhere' : 'where';

                        $contactQuery->{$method}('primary_participants.metadata->contact->phone', $phone);
                    }
                });
        });
    }

    public function stagePrimaryParticipant(string $name, ?string $email = null, ?string $phone = null): self
    {
        $this->setMetadataValue('primary_participant.name', $this->normalizedString($name));
        $this->setMetadataValue('primary_participant.contact.email', $this->normalizedString($email));
        $this->setMetadataValue('primary_participant.contact.phone', $this->normalizedString($phone));

        return $this;
    }

    public function setCheckinToken(?string $checkinToken): self
    {
        $this->setMetadataValue('checkin_token', $this->normalizedString($checkinToken));

        return $this;
    }

    public function isForUser(User $user): bool
    {
        return parent::getAttribute('registrant_type') === $user->getMorphClass()
            && (string) parent::getAttribute('registrant_id') === (string) $user->getKey();
    }

    public function resolvedUserId(): ?string
    {
        return parent::getAttribute('registrant_type') === self::userMorphClass()
            ? (string) parent::getAttribute('registrant_id')
            : null;
    }

    public function resolvedName(): ?string
    {
        $name = OwnerContext::withOwner(null, fn (): ?string => $this->resolvePrimaryParticipantName());

        if ($name !== null) {
            return $name;
        }

        $draftName = $this->participantDraftValue('name');

        if ($draftName !== null) {
            return $draftName;
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

        $draftEmail = $this->participantDraftValue('contact.email');

        if ($draftEmail !== null) {
            return $draftEmail;
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

        $draftPhone = $this->participantDraftValue('contact.phone');

        if ($draftPhone !== null) {
            return $draftPhone;
        }

        $registrant = $this->registrant;

        return $registrant instanceof User ? $this->normalizedString($registrant->phone) : null;
    }

    public function resolvedCheckinToken(): ?string
    {
        $checkinToken = $this->metadataValue('checkin_token');

        return is_string($checkinToken) && $checkinToken !== '' ? $checkinToken : null;
    }

    public function syncPrimaryParticipantRecord(): void
    {
        $this->syncPrimaryParticipant();
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

    private function applyLegacyDefaults(): void
    {
        if (filled(parent::getAttribute('registrant_id')) && blank(parent::getAttribute('registrant_type'))) {
            parent::setAttribute('registrant_type', self::userMorphClass());
        }

        if (blank($this->registration_type)) {
            $this->registration_type = 'individual';
        }

        if (blank($this->source)) {
            $this->source = 'website';
        }

        if (blank($this->total_participants)) {
            $this->total_participants = 1;
        }

        if (blank($this->status)) {
            parent::setAttribute('status', 'confirmed');
        }
    }

    private function syncPrimaryParticipant(): void
    {
        $registrant = $this->registrant;

        $name = $this->normalizedString(
            $this->participantDraftValue('name')
                ?? ($registrant instanceof User ? $registrant->name : null),
        );

        $email = $this->normalizedString(
            $this->participantDraftValue('contact.email')
                ?? ($registrant instanceof User ? $registrant->email : null),
        );

        $phone = $this->normalizedString(
            $this->participantDraftValue('contact.phone')
                ?? ($registrant instanceof User ? $registrant->phone : null),
        );

        if ($name === null) {
            return;
        }

        OwnerContext::withOwner(null, function () use ($email, $name, $phone): void {
            $participant = $this->resolvePrimaryParticipant() ?? $this->participants()->make([
                'event_registration_id' => $this->getKey(),
            ]);

            if (! $participant instanceof EventRegistrationParticipant) {
                return;
            }

            $participant->fill([
                'event_id' => $this->event_id,
                'event_occurrence_id' => $this->event_occurrence_id,
                'event_session_id' => $this->event_session_id,
                'participant_type' => parent::getAttribute('registrant_type'),
                'participant_id' => parent::getAttribute('registrant_id'),
                'name' => $name,
                'is_primary' => true,
                'status' => 'active',
                'metadata' => array_filter([
                    'contact' => array_filter([
                        'email' => $email,
                        'phone' => $phone,
                    ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
                ], static fn (mixed $value): bool => $value !== []),
            ]);

            $participant->save();

            $this->syncParticipantContactMethods($participant, $email, $phone);
        });
    }

    private function setMetadataValue(string $path, mixed $value): void
    {
        $metadata = parent::getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        Arr::set($metadata, $path, $value);

        parent::setAttribute('metadata', $metadata);
    }

    private function metadataValue(string $path): mixed
    {
        $metadata = parent::getAttribute('metadata');

        return is_array($metadata) ? data_get($metadata, $path) : null;
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function participantDraftValue(string $path): ?string
    {
        $value = $this->metadataValue("primary_participant.{$path}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function syncParticipantContactMethods(EventRegistrationParticipant $participant, ?string $email, ?string $phone): void
    {
        $participant->contactMethodsOfType('email')->delete();
        $participant->contactMethodsOfType('phone')->delete();

        if ($email !== null) {
            $participant->addContactMethod(new ContactMethodData(
                type: 'email',
                purpose: 'general',
                value: $email,
                isPrimary: true,
            ));
        }

        if ($phone !== null) {
            $participant->addContactMethod(new ContactMethodData(
                type: 'phone',
                purpose: 'general',
                value: $phone,
                countryCode: config('contacting.defaults.country_code', 'MY'),
                isPrimary: true,
            ));
        }
    }

    private function primaryParticipantContactValue(string $key): ?string
    {
        /** @var EventRegistrationParticipant|null $participant */
        $participant = OwnerContext::withOwner(null, fn (): ?EventRegistrationParticipant => $this->resolvePrimaryParticipant());

        if (! $participant instanceof EventRegistrationParticipant) {
            return $this->participantDraftValue("contact.{$key}");
        }

        $value = data_get($participant->getAttribute('metadata'), "contact.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function userMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
