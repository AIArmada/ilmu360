<?php

namespace App\Platform\Diagnostics\EntryPoints\Http;

use App\Http\Controllers\Controller;

use App\Platform\Diagnostics\EntryPoints\Http\Requests\NetworkDiagnosticsRequest;
use App\Platform\Diagnostics\NetworkDiagnosticsService;
use Illuminate\Contracts\View\View;

class NetworkDiagnosticsController extends Controller
{
    public function __invoke(NetworkDiagnosticsRequest $request, NetworkDiagnosticsService $networkDiagnosticsService): View
    {
        return view('diagnostics.network', [
            'report' => $networkDiagnosticsService->run(),
        ]);
    }
}
