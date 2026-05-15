<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Http\Controllers\Controller;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class FrontendController extends Controller
{
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

        abort_unless($user instanceof User, 403);

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
