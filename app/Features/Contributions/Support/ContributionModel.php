<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime model access for Contributions while the model remains in the
 * legacy root namespace. Resolution goes through the registered morph alias
 * so the feature never imports its private model class directly.
 */
final class ContributionModel
{
    /**
     * @return class-string<Model>
     */
    public static function modelClass(): string
    {
        return MorphModelResolver::resolve('contribution_request');
    }
}
