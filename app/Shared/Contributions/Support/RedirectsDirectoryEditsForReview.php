<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait RedirectsDirectoryEditsForReview
{
    abstract protected function contributionSubjectRouteSegment(): string;

    protected function requireDirectoryPublishingPermission(): void
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User && $actor->canPublishDirectoryUpdates(), 403);
    }

    protected function redirectDirectoryEditForReview(int|string $record): bool
    {
        $actor = auth()->user();

        if ($actor instanceof User && $actor->canPublishDirectoryUpdates()) {
            return false;
        }

        $this->record = $this->resolveRecord($record);
        $entity = $this->getRecord();
        static::getResource()::authorizeEdit($entity);

        $this->redirectToContribution($entity);

        return true;
    }

    protected function redirectToContribution(Model $record): void
    {
        $slug = $record->getAttribute('slug');

        $this->redirectRoute('contributions.suggest-update', [
            'subjectType' => $this->contributionSubjectRouteSegment(),
            'subjectId' => is_string($slug) && trim($slug) !== '' ? $slug : (string) $record->getKey(),
        ]);
    }
}
