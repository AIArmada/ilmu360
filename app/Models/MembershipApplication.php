<?php

namespace App\Models;

use AIArmada\Membership\Models\MembershipApplication as BaseMembershipApplication;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
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
    /** @use HasFactory<MembershipApplicationFactory> */
    use AuditsModelChanges, HasFactory, InteractsWithMedia;

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'subject_type' => MemberSubjectType::class,
        ]);
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
}
