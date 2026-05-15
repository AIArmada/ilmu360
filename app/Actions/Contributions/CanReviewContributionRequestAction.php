<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Enums\ContributionRequestType;
use App\Models\ContributionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class CanReviewContributionRequestAction
{
    use AsAction;

    public function handle(User $user, ContributionRequest $request): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        if ($request->type === ContributionRequestType::Create) {
            return false;
        }

        return $request->entity instanceof Model
            && $user->can('update', $request->entity);
    }
}
