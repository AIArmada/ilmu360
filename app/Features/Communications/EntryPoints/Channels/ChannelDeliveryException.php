<?php

declare(strict_types=1);

namespace App\Features\Communications\EntryPoints\Channels;

use RuntimeException;

class ChannelDeliveryException extends RuntimeException
{
    /**
     * @param  list<array{
     *     destination_id: string|null,
     *     status: string,
     *     provider_message_id?: string|null,
     *     meta?: array<string, mixed>
     * }>  $results
     */
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly array $results = [],
    ) {
        parent::__construct($message);
    }
}
