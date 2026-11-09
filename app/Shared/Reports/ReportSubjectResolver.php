<?php

declare(strict_types=1);

namespace App\Shared\Reports;

interface ReportSubjectResolver
{
    /**
     * @return list<string>
     */
    public function validKeys(): array;

    public function label(string $typeKey): string;

    public function exists(string $typeKey, string $entityId): bool;

    public function find(string $typeKey, string $entityId): ?ReportSubject;

    /**
     * Resolve a public report subject by its canonical slug or UUID.
     */
    public function resolve(string $typeKey, string $identifier): ?ReportSubject;

    public function presentation(ReportSubject $subject): ReportSubjectPresentation;
}
