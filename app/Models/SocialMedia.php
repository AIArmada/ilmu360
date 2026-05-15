<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use App\Support\SocialMedia\SocialMediaLinkResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class SocialMedia extends Model implements AuditableContract
{
    use AuditsModelChanges, HasUuids;

    protected $fillable = [
        'socialable_type',
        'socialable_id',
        'platform',
        'url',
        'username',
        'order_column',
    ];

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (SocialMedia $socialMedia): void {
            $normalized = SocialMediaLinkResolver::normalize(
                self::normalizePlatformValue($socialMedia->platform),
                $socialMedia->username,
                $socialMedia->getAttribute('url'),
            );

            $socialMedia->platform = $normalized['platform'];
            $socialMedia->username = $normalized['username'];
            $socialMedia->url = $normalized['url'];
        });
    }

    public function getResolvedUrlAttribute(): ?string
    {
        return SocialMediaLinkResolver::resolveUrl(
            self::normalizePlatformValue($this->platform),
            is_string($this->username) ? $this->username : null,
            is_string($this->getAttribute('url')) ? $this->getAttribute('url') : null,
        );
    }

    public function getDisplayUsernameAttribute(): ?string
    {
        return SocialMediaLinkResolver::displayUsername(
            self::normalizePlatformValue($this->platform),
            is_string($this->username) ? $this->username : null,
        );
    }

    public function getIconFileAttribute(): string
    {
        $platform = self::normalizePlatformValue($this->platform);

        return strtolower($platform ?? 'link').'.svg';
    }

    public function getIconUrlAttribute(): string
    {
        return asset('storage/social-media-icons/'.$this->icon_file);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function socialable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'order_column' => 'integer',
        ];
    }

    private static function normalizePlatformValue(mixed $platform): ?string
    {
        if ($platform instanceof BackedEnum) {
            return is_string($platform->value) && trim($platform->value) !== ''
                ? $platform->value
                : null;
        }

        if (is_string($platform) && trim($platform) !== '') {
            return $platform;
        }

        return null;
    }
}
