<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class FrontendController
{
    use AuthorizesRequests;

    protected function requireAccountActor(Request $request): AccountActor&Authenticatable
    {
        $account = $request->user('sanctum');

        abort_unless($account instanceof AccountActor && $account instanceof Authenticatable, 401);

        return $account;
    }

    protected function accountTimezone(AccountActor $account): string
    {
        $timezone = app(AccountDirectory::class)->profile($account->actorId())?->timezone;

        abort_unless(is_string($timezone) && $timezone !== '', 401);

        return $timezone;
    }

    protected function currentUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return null;
        }

        return $user;
    }

    protected function requireUser(Request $request): User
    {
        $user = $this->currentUser($request);

        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function requestId(Request $request): string
    {
        return $request->header('X-Request-ID', (string) Str::uuid());
    }

    protected function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    protected function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
