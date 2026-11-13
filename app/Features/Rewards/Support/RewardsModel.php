<?php

declare(strict_types=1);

namespace App\Features\Rewards\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime model access for Rewards while its single app table model stays in the
 * legacy root namespace. Resolution goes through the registered morph alias so
 * the feature never imports the root model class directly.
 */
final class RewardsModel
{
    public const string SLOT_PLANNED = 'planned';

    public const string SLOT_SENDING = 'sending';

    public const string SLOT_SENT = 'sent';

    public const string SLOT_CANCELLED = 'cancelled';

    /**
     * @return class-string<Model>
     */
    public static function digestSlot(): string
    {
        return MorphModelResolver::resolve('reward_digest_slot');
    }

    /**
     * @return Builder<Model>
     */
    public static function digestSlotQuery(): Builder
    {
        return self::digestSlot()::query();
    }
}
