<?php

declare(strict_types=1);

namespace App\Support\Api\Member;

use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Filament\Ahli\Resources\References\ReferenceResource as AhliReferenceResource;
use App\Filament\Ahli\Resources\Speakers\SpeakerResource as AhliSpeakerResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\User;
use App\Support\Api\Admin\AdminResourceMutationService;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Mcp\McpWriteSchemaFormatter;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberResourceMutationService
{
    public function __construct(
        private readonly AdminResourceMutationService $adminMutationService,
        private readonly MemberPermissionGate $memberPermissionGate,
        private readonly McpWriteSchemaFormatter $schemaFormatter,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return in_array($resourceClass, [
            AhliInstitutionResource::class,
            AhliSpeakerResource::class,
            AhliReferenceResource::class,
            AhliEventResource::class,
        ], true);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function canWriteResource(string $resourceClass, ?User $user = null): bool
    {
        if (! $user instanceof User || ! $this->supports($resourceClass)) {
            return false;
        }

        return match ($resourceClass) {
            AhliInstitutionResource::class => $this->memberPermissionGate->hasAnyInstitutionPermission($user, 'institution.update'),
            AhliSpeakerResource::class => $this->memberPermissionGate->hasAnySpeakerPermission($user, 'speaker.update'),
            AhliReferenceResource::class => $this->memberPermissionGate->hasAnyReferencePermission($user, 'reference.update'),
            AhliEventResource::class => $this->memberPermissionGate->hasAnyEventPermission($user, 'event.update')
                || $this->memberPermissionGate->hasAnyInstitutionPermission($user, 'event.update')
                || $this->memberPermissionGate->hasAnySpeakerPermission($user, 'event.update'),
            default => false,
        };
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, Model $record): array
    {
        $schema = $this->adminMutationService->schema(
            $this->adminResourceClass($resourceClass),
            $resourceKey,
            'update',
            $record,
        );

        return $this->schemaFormatter->formatSchema($schema, 'member-update-record', [
            'resource_key' => $resourceKey,
            'record_key' => (string) $record->getRouteKey(),
            'payload' => 'object',
            'validate_only' => false,
        ]);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function rules(string $resourceClass): array
    {
        return $this->adminMutationService->rules(
            $this->adminResourceClass($resourceClass),
            updating: true,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidatedPayload(string $resourceClass, array $validated, ?Model $record = null): array
    {
        return $this->adminMutationService->normalizeValidatedPayload(
            $this->adminResourceClass($resourceClass),
            $validated,
            $record,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): Model
    {
        return $this->adminMutationService->update(
            $this->adminResourceClass($resourceClass),
            $record,
            $validated,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        return $this->adminMutationService->previewNormalizedPayload($normalizedPayload);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return class-string<resource>
     */
    private function adminResourceClass(string $resourceClass): string
    {
        return match ($resourceClass) {
            AhliInstitutionResource::class => InstitutionResource::class,
            AhliSpeakerResource::class => SpeakerResource::class,
            AhliReferenceResource::class => ReferenceResource::class,
            AhliEventResource::class => EventResource::class,
            default => throw new NotFoundHttpException,
        };
    }
}
