<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Models\User;
use App\Platform\Api\Admin\AdminResourceMutationService;
use App\Platform\Api\Resources\ResourceActor;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceSurface;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MemberResourceMutationService
{
    public function __construct(
        private readonly MemberResourceDirectory $directory,
        private readonly ResourceActorResolver $actors,
        private readonly AdminResourceMutationService $adminMutationService,
        private readonly MemberResourceWriter $writer,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return $this->directory->definition($resourceClass)?->adminResourceClass() !== null;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function canWriteResource(string $resourceClass, ?User $user = null): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $this->directory->definition($resourceClass)
            ?->canWrite($this->actorFor($user)) ?? false;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function canWriteRecord(string $resourceClass, User $user, Model $record): bool
    {
        return $this->directory->definition($resourceClass)
            ?->canWriteExistingRecord($user, $record) ?? false;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, Model $record): array
    {
        return $this->writer->schemaForRecord(
            $this->definition($resourceClass),
            $resourceKey,
            $record,
        );
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
        return $this->definition($resourceClass)->adminResourceClass()
            ?? throw new NotFoundHttpException;
    }

    private function definition(string $resourceClass): AbstractMemberResourceDefinition
    {
        return $this->directory->definition($resourceClass)
            ?? throw new NotFoundHttpException;
    }

    private function actorFor(User $user): ResourceActor
    {
        return $this->actors->fromUser($user, ResourceSurface::Member);
    }
}
