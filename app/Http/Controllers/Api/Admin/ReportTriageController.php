<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\Admin\AdminReportTriageService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Report Triage', 'Explicit admin workflow endpoints for triaging, resolving, dismissing, or reopening reports. These actions mirror the moderation workflow and are not part of the generic admin CRUD surface.')]
class ReportTriageController extends Controller
{
    public function __construct(
        private readonly AdminReportTriageService $triageService,
    ) {}

    #[PathParameter('recordKey', 'Existing report route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Get report triage schema',
        description: 'Returns the triage contract for one report, including the currently allowed report actions.',
    )]
    public function schema(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->triageService->schema(
            recordKey: $recordKey,
            actor: $this->currentUser($request),
        ));
    }

    #[PathParameter('recordKey', 'Existing report route key returned by the admin collection or record endpoints.', example: '0195b86a-3c15-73fa-a2d8-5a45f6a7f701')]
    #[Endpoint(
        title: 'Run a report triage action',
        description: 'Runs one explicit triage action for a report, such as triage, resolve, dismiss, or reopen.',
    )]
    public function triage(string $recordKey, Request $request): JsonResponse
    {
        return response()->json($this->triageService->triage(
            recordKey: $recordKey,
            payload: $request->all(),
            actor: $this->currentUser($request),
        ));
    }

    private function currentUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
