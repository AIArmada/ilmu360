<?php

declare(strict_types=1);

namespace App\Features\Reports\Services;

use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Features\References\Api\Orm\ReferenceLookup;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectPresentation;
use App\Features\Reports\Api\ReportSubjectResolver;
use App\Features\Reports\Api\ReportSubjectType;
use InvalidArgumentException;

/** Application adapter composing independently-owned report subjects. */
final readonly class CompositeReportSubjectResolver implements ReportSubjectResolver
{
    public function __construct(
        private InstitutionLookup $institutions,
        private PersonLookup $persons,
        private ReferenceLookup $references,
        private DonationChannelLookup $donationChannels,
        private EventReportSubjectProvider $events,
    ) {}

    public function validKeys(): array
    {
        return ReportSubjectType::keys();
    }

    public function label(string $typeKey): string
    {
        return match ($this->type($typeKey)) {
            ReportSubjectType::Institution => __('Institution'),
            ReportSubjectType::Person => __('Speaker'),
            ReportSubjectType::Reference => __('Reference'),
            ReportSubjectType::DonationChannel => __('Donation Channel'),
            ReportSubjectType::Event => $this->events->label($typeKey),
        };
    }

    public function exists(string $typeKey, string $entityId): bool
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type instanceof ReportSubjectType && $entityId !== ''
            && $this->find($typeKey, $entityId) instanceof ReportSubject;
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);
        if (! $type instanceof ReportSubjectType || trim($entityId) === '') {
            return null;
        }

        return match ($type) {
            ReportSubjectType::Institution => $this->institutions->findReportSubject($entityId),
            ReportSubjectType::Person => $this->persons->findReportSubject($entityId),
            ReportSubjectType::Reference => $this->references->findReportSubject($entityId),
            ReportSubjectType::DonationChannel => $this->donationChannels->findReportSubject($entityId),
            ReportSubjectType::Event => $this->events->find($typeKey, $entityId),
        };
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);
        if (! $type instanceof ReportSubjectType || trim($identifier) === '') {
            return null;
        }

        return match ($type) {
            ReportSubjectType::Institution => $this->institutions->resolveReportSubject($identifier),
            ReportSubjectType::Person => $this->persons->resolveReportSubject($identifier),
            ReportSubjectType::Reference => $this->references->resolveReportSubject($identifier),
            ReportSubjectType::DonationChannel => $this->donationChannels->resolveReportSubject($identifier),
            ReportSubjectType::Event => $this->events->resolve($typeKey, $identifier),
        };
    }

    public function presentation(ReportSubject $subject): ReportSubjectPresentation
    {
        if ($subject->type === ReportSubjectType::Event) {
            return $this->events->presentation($subject);
        }

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
        return ReportSubjectType::tryFrom($typeKey)
            ?? throw new InvalidArgumentException("Unsupported report subject type [{$typeKey}]");
    }
}
