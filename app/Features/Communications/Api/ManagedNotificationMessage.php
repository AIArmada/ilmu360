<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class ManagedNotificationMessage
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $mailReplacements
     * @param  array<string, mixed>  $operationSnapshot
     */
    public function __construct(
        public array $channels,
        public InAppNotificationPayload $inAppPayload,
        public string $mailSubjectTranslationKey,
        public string $mailBodyTranslationKey,
        public array $mailReplacements,
        public array $operationSnapshot,
    ) {}
}
