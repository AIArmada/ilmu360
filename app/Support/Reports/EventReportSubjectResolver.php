<?php

declare(strict_types=1);

namespace App\Support\Reports;

use AIArmada\FilamentEvents\Resources\EventResource;
use App\Models\Event;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectResolver;
use App\Shared\Reports\ReportSubjectType;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @internal Interim Events report-subject provider until Events exposes its own
 * public subject API. Reports depends only on the Shared contract and composes
 * this provider with Directory's provider.
 */
final readonly class EventReportSubjectResolver implements ReportSubjectResolver
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
            && Event::query()->whereKey($entityId)->exists();
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        if ($this->typeOrNull($typeKey) !== ReportSubjectType::Event || trim($entityId) === '') {
            return null;
        }

        $event = Event::query()->find($entityId);

        return $event instanceof Event ? $this->subject($event) : null;
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        if ($this->typeOrNull($typeKey) !== ReportSubjectType::Event || trim($identifier) === '') {
            return null;
        }

        $event = $this->slugOrUuidResolver->first(Event::query(), 'events.slug', $identifier);

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
