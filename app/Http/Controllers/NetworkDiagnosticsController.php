<?php

namespace App\Http\Controllers;

use App\Http\Requests\NetworkDiagnosticsRequest;
use App\Services\Diagnostics\NetworkDiagnosticsService;
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
