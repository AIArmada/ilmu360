<?php

namespace App\Features\Reports\EntryPoints\Http;

use AIArmada\CommerceSupport\Support\RequestFingerprint;
use App\Features\Reports\Actions\ResolveReportCategoryOptionsAction;
use App\Features\Reports\Actions\SubmitReportAction;
use App\Features\Reports\Data\ReportSubmissionData;
use App\Features\Reports\Models\Report;
use App\Platform\Media\MediaEvidence;
use App\Shared\Auth\AccountActor;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Group('Report', 'Authenticated reporting endpoints for user-submitted data and content issue reports.')]
class ReportController extends Controller
{
    use AuthorizesRequests;

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
        ReportSubjectResolver $reportSubjects,
        SubmitReportAction $submitReportAction,
    ): JsonResponse {
        $this->authorize('create', Report::class);

        $reporterFingerprint = RequestFingerprint::resolve($request);

        $validated = $request->validate([
            'entity_type' => ['required', Rule::in($reportSubjects->validKeys())],
            'entity_id' => ['required', 'uuid'],
            'category' => [
                'required',
                Rule::in($resolveReportCategoryOptionsAction->validKeys()),
            ],
            'description' => ['required_if:category,other', 'nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:'.MediaEvidence::MAX_FILES],
            'evidence.*' => ['file', MediaEvidence::mimeRule(), 'max:'.MediaEvidence::maxFileSizeKb()],
        ]);

        $subject = $reportSubjects->find($validated['entity_type'], $validated['entity_id']);

        if (! $subject instanceof ReportSubject) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The specified entity does not exist.',
                ],
            ], 404);
        }

        try {
            $user = $request->user();
            $reporter = $user instanceof AccountActor ? $user : null;

            $report = $submitReportAction->handle(
                $subject,
                $reporter,
                $reporterFingerprint,
                $validated['category'],
                $validated['description'] ?? null,
                $request,
                is_array($request->file('evidence')) ? $request->file('evidence') : null,
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
