<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Fortify;

use App\Features\Identity\Actions\RecordSuccessfulLoginAction;
use App\Features\Identity\Api\GuestSubmissionNotice;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

final readonly class RecordSuccessfulLogin
{
    public function __construct(private RecordSuccessfulLoginAction $login) {}

    public function __invoke(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        $user = $request->user();

        if ($user instanceof User) {
            GuestSubmissionNotice::flash($this->login->handle($user, $request));
        }

        return $response;
    }
}
