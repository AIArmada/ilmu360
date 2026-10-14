<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class DigestMailAcceptThenThrowTransport implements TransportInterface
{
    public function __construct(
        private readonly string $observer,
        private readonly ArrayTransport $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        file_put_contents($this->observer, "accepted\n", FILE_APPEND);

        throw new TransportException('simulated transport timeout after provider acceptance');
    }

    public function messages(): Collection
    {
        return $this->inner->messages();
    }

    public function __toString(): string
    {
        return 'accept-then-throw';
    }
}
