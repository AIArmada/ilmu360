<?php

namespace App\Models;

use AIArmada\Membership\Models\MembershipApplication as BaseMembershipApplication;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MembershipApplication extends BaseMembershipApplication implements HasMedia
{
    use AuditsModelChanges, InteractsWithMedia;

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'subject_type' => MemberSubjectType::class,
        ]);
    }

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
