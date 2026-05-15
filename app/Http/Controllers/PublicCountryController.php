<?php

namespace App\Http\Controllers;

use App\Support\Location\PublicCountryPreference;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicCountryController extends Controller
{
    public function __invoke(
        Request $request,
        string $country,
        PublicCountryRegistry $registry,
        PublicCountryPreference $preference,
    ): RedirectResponse {
        if (! $registry->has($country)) {
            abort(404);
        }

        if (! $registry->isEnabled($country)) {
            return redirect()->back();
        }

        $preference->set($country, $request);

        return redirect()->back();
    }
}
