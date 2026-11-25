<?php

namespace App\Features\Membership\Models;

use AIArmada\CommerceSupport\Support\CacheVersionStore;
use AIArmada\Membership\Models\MembershipApplication as BaseMembershipApplication;
use App\Features\Membership\Api\MemberSubjectType;
use App\Platform\Auditing\AuditsModelChanges;
use Database\Factories\MembershipApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MembershipApplication extends BaseMembershipApplication implements AuditableContract, HasMedia
{
    public const string PENDING_COUNT_VERSION_KEY = 'nav_badge:membership_applications:pending:version:v1';

    #[\Override]
    protected $table = 'membership_applications';

    /** @use HasFactory<MembershipApplicationFactory> */
    use AuditsModelChanges, HasFactory, InteractsWithMedia;

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'subject_type' => MemberSubjectType::class,
        ]);
    }

    public function subjectType(): ?MemberSubjectType
    {
        $subjectType = $this->getAttribute('subject_type');

        return $subjectType instanceof MemberSubjectType ? $subjectType : null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    #[\Override]
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->useDisk(config('media-library.disk_name'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('evidence')
            ->width(200)
            ->height(200)
            ->fit(Fit::Crop, 200, 200)
            ->format('webp');
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saved(static fn () => app(CacheVersionStore::class)->bump(self::PENDING_COUNT_VERSION_KEY));
        static::deleted(static fn () => app(CacheVersionStore::class)->bump(self::PENDING_COUNT_VERSION_KEY));
    }

    protected static function newFactory(): MembershipApplicationFactory
    {
        return MembershipApplicationFactory::new();
    }
}
