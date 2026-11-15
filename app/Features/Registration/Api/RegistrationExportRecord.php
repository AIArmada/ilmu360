<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

interface RegistrationExportRecord
{
    public function exportId(): string;

    public function resolvedName(): ?string;

    public function fallbackRegistrantName(): ?string;

    public function resolvedEmail(): ?string;

    public function fallbackRegistrantEmail(): ?string;

    public function resolvedPhone(): ?string;

    public function statusValue(): string;

    public function exportRegisteredAt(): ?\DateTimeInterface;

    public function exportCreatedAt(): ?\DateTimeInterface;
}
