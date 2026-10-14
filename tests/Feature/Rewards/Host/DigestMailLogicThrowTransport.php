<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class DigestMailLogicThrowTransport implements TransportInterface
{
    public function __construct(
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new InvalidArgumentException('simulated logic failure inside transport: invalid recipient');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'logic-throw';
    }
}
