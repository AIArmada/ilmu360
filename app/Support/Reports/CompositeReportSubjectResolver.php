<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectResolver;
use App\Shared\Reports\ReportSubjectType;
use InvalidArgumentException;

/**
 * Reports composition over feature-owned report subject providers.
 *
 * Directory supplies its four resource types; the interim Events provider
 * supplies event until Events exposes its own API.
 */
final readonly class CompositeReportSubjectResolver implements ReportSubjectResolver
{
    public function __construct(
        private DirectoryReportSubjectProvider $directorySubjects,
        private EventReportSubjectResolver $eventSubjects,
    ) {}

    public function validKeys(): array
    {
        return ReportSubjectType::keys();
    }

    public function label(string $typeKey): string
    {
        return $this->providerFor($this->type($typeKey))->label($typeKey);
    }

    public function exists(string $typeKey, string $entityId): bool
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type instanceof ReportSubjectType
            && $this->providerFor($type)->exists($typeKey, $entityId);
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type instanceof ReportSubjectType
            ? $this->providerFor($type)->find($typeKey, $entityId)
            : null;
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type instanceof ReportSubjectType
            ? $this->providerFor($type)->resolve($typeKey, $identifier)
            : null;
    }

    public function presentation(ReportSubject $subject): ReportSubjectPresentation
    {
        return $this->providerFor($subject->type)->presentation($subject);
    }

    private function providerFor(ReportSubjectType $type): ReportSubjectResolver
    {
        return $type === ReportSubjectType::Event
            ? $this->eventSubjects
            : $this->directorySubjects;
    }

    private function type(string $typeKey): ReportSubjectType
    {
        return ReportSubjectType::tryFrom($typeKey)
            ?? throw new InvalidArgumentException("Unsupported report subject type [{$typeKey}]");
    }
}
