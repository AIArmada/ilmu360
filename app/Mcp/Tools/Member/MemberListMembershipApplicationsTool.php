<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Models\MembershipApplication;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MemberListMembershipApplicationsTool extends AbstractMemberTool
{
    protected string $name = 'member-list-membership-applications';

    protected string $description = 'Use this when you need to list the authenticated member\'s membership applications. Do not use for admin-level membership application management.';

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);
            $this->validateArguments($request, []);

            $applications = $actor->membershipApplications()
                ->with(['reviewer', 'media'])
                ->latest('created_at')
                ->get();

            return [
                'data' => $applications->map(fn (MembershipApplication $app): array => [
                    'id' => $app->getKey(),
                    'status' => $app->status->value,
                    'status_label' => $app->status->label(),
                    'subject_type' => $app->subject_type instanceof \BackedEnum ? $app->subject_type->value : (string) $app->subject_type,
                    'justification' => $app->justification,
                    'granted_role' => $app->granted_role,
                    'created_at' => $app->created_at?->toIso8601String(),
                    'reviewed_at' => $app->reviewed_at?->toIso8601String(),
                ])->all(),
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
