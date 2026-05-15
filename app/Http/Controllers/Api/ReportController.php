<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\ResolveReportEntityMetadataAction;
use App\Actions\Reports\ResolveReporterFingerprintAction;
use App\Actions\Reports\SubmitReportAction;
use App\Data\Api\Report\ReportSubmissionData;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use App\Support\Api\Frontend\FrontendMediaSyncService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Group('Report', 'Authenticated reporting endpoints for user-submitted data and content issue reports.')]
class ReportController extends Controller
{
    /**
     * Store a newly created report.
     * Per documentation B5b - POST /reports
     */
    #[Endpoint(
        title: 'Submit a report',
        description: 'Creates a content or data report against a supported entity when the authenticated user passes report authorization and duplicate checks.',
    )]
    public function store(
        Request $request,
        ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        ResolveReportEntityMetadataAction $resolveReportEntityMetadataAction,
        SubmitReportAction $submitReportAction,
        ResolveReporterFingerprintAction $resolveReporterFingerprintAction,
        FrontendMediaSyncService $frontendMediaSyncService,
    ): JsonResponse {
        $this->authorize('create', Report::class);

        $reporterFingerprint = $resolveReporterFingerprintAction->handle($request);
        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        $validated = $request->validate([
            'entity_type' => ['required', Rule::in($resolveReportEntityMetadataAction->validKeys())],
            'entity_id' => ['required', 'uuid'],
            'category' => [
                'required',
                Rule::in($resolveReportCategoryOptionsAction->validKeys()),
            ],
            'description' => ['required_if:category,other', 'nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:8'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', "max:{$maxUploadSizeKb}"],
        ]);

        // Verify entity exists
        $entityClass = $resolveReportEntityMetadataAction->handle($validated['entity_type'])['model_class'];
        $entity = $entityClass::query()->find($validated['entity_id']);

        if (! $entity) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The specified entity does not exist.',
                ],
            ], 404);
        }

        try {
            /** @var User|null $user */
            $user = $request->user();

            $report = $submitReportAction->handle(
                $entity,
                $validated['entity_type'],
                $user,
                $reporterFingerprint,
                $validated['category'],
                $validated['description'] ?? null,
                $request,
            );

            $frontendMediaSyncService->syncMultiple(
                $report,
                is_array($request->file('evidence')) ? $request->file('evidence') : null,
                'evidence',
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'duplicate_report') {
                throw $exception;
            }

            return response()->json([
                'error' => [
                    'code' => 'conflict',
                    'message' => 'You have already reported this entity within the last 24 hours.',
                ],
            ], 409);
        }

        return response()->json([
            'message' => 'Report submitted successfully. Our team will review it.',
            'data' => ReportSubmissionData::fromModel($report->fresh('media') ?? $report)->toArray(),
            'meta' => [
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], 201);
    }
}
