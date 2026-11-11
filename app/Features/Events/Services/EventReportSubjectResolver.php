<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\FilamentEvents\Resources\EventResource;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Support\EventModel;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectType;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Interim Events report-subject provider.
 *
 * Reports composes it with Directory's provider. Querying through the event
 * morph alias keeps this adapter free of the private root event model.
 *
 * @internal
 */
final readonly class EventReportSubjectResolver implements EventReportSubjectProvider
{
    public function __construct(
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    public function validKeys(): array
    {
        return [ReportSubjectType::Event->value];
    }

    public function label(string $typeKey): string
    {
        return $this->type($typeKey) === ReportSubjectType::Event
            ? __('Event')
            : throw new InvalidArgumentException("Unsupported Events report subject [{$typeKey}]");
    }

    public function exists(string $typeKey, string $entityId): bool
    {
        return $this->typeOrNull($typeKey) === ReportSubjectType::Event
            && trim($entityId) !== ''
            && EventModel::query('event')->whereKey($entityId)->exists();
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        if ($this->typeOrNull($typeKey) !== ReportSubjectType::Event || trim($entityId) === '') {
            return null;
        }

        $event = EventModel::query('event')->find($entityId);

        return $event instanceof Model ? $this->subject($event) : null;
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        if ($this->typeOrNull($typeKey) !== ReportSubjectType::Event || trim($identifier) === '') {
            return null;
        }

        $event = $this->slugOrUuidResolver->first(EventModel::query('event'), 'events.slug', $identifier);

        return $event instanceof Model ? $this->subject($event) : null;
    }

    public function presentation(ReportSubject $subject): ReportSubjectPresentation
    {
        $redirectUrl = $subject->publicUrl;

        if ($redirectUrl === null) {
            throw new InvalidArgumentException("Report subject [{$subject->type->value}] has no public URL.");
        }

        return new ReportSubjectPresentation(
            subjectLabel: $subject->label,
            subjectTitle: $subject->title,
            redirectUrl: $redirectUrl,
            profileImageUrl: $subject->imageUrl,
        );
    }

    private function type(string $typeKey): ReportSubjectType
    {
        return $this->typeOrNull($typeKey)
            ?? throw new InvalidArgumentException("Unsupported Events report subject [{$typeKey}]");
    }

    private function typeOrNull(string $typeKey): ?ReportSubjectType
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type === ReportSubjectType::Event ? $type : null;
    }

    private function subject(Model $event): ReportSubject
    {
        $fallbackTitle = (string) $event->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Event,
            id: $fallbackTitle,
            label: __('Event'),
            title: filled($event->getAttribute('title')) ? (string) $event->getAttribute('title') : $fallbackTitle,
            slug: (string) $event->getAttribute('slug'),
            publicUrl: route('events.show', $event),
            adminUrl: EventResource::getUrl('view', ['record' => $event], panel: 'admin'),
            imageUrl: null,
        );
    }
}
