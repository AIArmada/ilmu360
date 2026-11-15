<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class EventInstitutionQuickAddMapsProof
{
    /**
     * @param  array<string, mixed>  $record
     */
    private function __construct(private array $record) {}

    public static function begin(
        string $token,
        string $nonce,
        string $formPath,
        string $addressPath,
        string $ownerPath,
    ): self {
        return new self([
            'token' => $token,
            'nonce' => $nonce,
            'form_path' => $formPath,
            'address_path' => $addressPath,
            'owner_path' => $ownerPath,
            'selection' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self($record);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function withSelection(array $selection): self
    {
        return new self([
            ...$this->record,
            'selection' => $selection,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->record;
    }
}
