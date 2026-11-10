<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Communications\AccountCommunications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $supportedLocales = array_keys(config('app.supported_locales', []));

        if (! in_array($locale, $supportedLocales, true)) {
            abort(404);
        }

        $request->session()->put('locale', $locale);

        $user = $request->user();

        if ($user instanceof User) {
            app(AccountCommunications::class)->updateBasePreference((string) $user->getKey(), ['locale' => $locale]);
        }

        return redirect()->back();
    }
}
