<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use AIArmada\Communications\Models\CommunicationDelivery;
use App\Support\Communications\GuardedDeliverySend;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mail channel that refuses to send a cancelled, settled, or ambiguous
 * delivery.
 *
 * Behaves exactly like the native mail channel once the bound delivery
 * is claimed (same message building, same mailer), but skips without
 * sending when a correction cancelled the transport first, another
 * worker already settled the send, or a prior attempt left the
 * delivery UNKNOWN/ambiguous (provider may already have accepted).
 *
 * External I/O never runs inside a DB transaction: the claim plus the
 * durable attempt/ownership boundary commit FIRST with a frozen
 * stable operation/recipient snapshot, the native send runs outside
 * any transaction, and the Sent recording is fenced against the
 * owning attempt in a second transaction. A crash after provider
 * acceptance but before recording leaves a started-but-unresolved
 * attempt and a Sending delivery that can never automatically resend
 * or release a slot; unsupported SMTP ambiguity stays
 * operator-resolvable, never a guessed failure or success.
 *
 * Any exception from the native send — a transport timeout or
 * disconnect, a post-acceptance listener/recorder crash, or any
 * local error class — is not confirmed non-delivery: the exception
 * class cannot prove the provider never accepted (a post-send
 * listener may throw LogicException/Error after acceptance), so
 * every failure defaults to the same UNKNOWN hold. The committed
 * attempt stays started-but-unresolved, the delivery stays Sending,
 * and only an authoritative confirmation or an explicit operator
 * decision with evidence may settle it. Nothing here automatically
 * fails, resends, or releases.
 */
class DigestMailChannel
{
    public function __construct(
        private readonly MailFactory $mailer,
        private readonly Markdown $markdown,
    ) {}

    public function send(object $notifiable, Notification $notification): mixed
    {
        $deliveryId = GuardedDeliverySend::deliveryIdFor($notification, static::class);

        if ($deliveryId === null) {
            return $this->native()->send($notifiable, $notification);
        }

        $communicationId = GuardedDeliverySend::communicationIdOf($notification);

        if ($communicationId === null) {
            GuardedDeliverySend::detach($notification, static::class);

            return null;
        }

        $operation = self::operationSnapshot($notification);

        $claimed = DB::transaction(function () use ($deliveryId, $communicationId, $operation): ?array {
            $delivery = GuardedDeliverySend::claimExternal($deliveryId, $communicationId);

            if (! $delivery instanceof CommunicationDelivery) {
                return null;
            }

            $attempt = GuardedDeliverySend::startExternalAttempt($deliveryId, $delivery, $operation);

            return [(string) $delivery->getKey(), (string) $attempt->getKey()];
        });

        if (! is_array($claimed)) {
            GuardedDeliverySend::detach($notification, static::class);

            return null;
        }

        [$lockedDeliveryId, $attemptId] = $claimed;

        try {
            $response = $this->native()->send($notifiable, $notification);
        } catch (Throwable $sendException) {
            // Every send exception holds UNKNOWN: the committed attempt
            // began before native send, and the exception class cannot
            // prove the provider never accepted. The attempt stays
            // started-but-unresolved and the delivery stays Sending:
            // never an automatic Failed, resend, or release. The rethrow
            // fails the job loudly while the failure handler preserves
            // the ambiguous hold for authoritative or operator resolution.
            GuardedDeliverySend::detach($notification, static::class);

            throw $sendException;
        }

        $result = self::resultFromResponse($response);

        try {
            $recorded = DB::transaction(fn (): bool => GuardedDeliverySend::recordExternalSent(
                $communicationId, $lockedDeliveryId, $attemptId, $result,
            ));
        } catch (Throwable $recordingException) {
            // Provider accepted but recording crashed: the attempt stays
            // started-without-response and the delivery stays Sending
            // (UNKNOWN). Never guess failure/success, never resend;
            // the channel throws so the job fails loudly while the
            // failure handler leaves the ambiguous delivery untouched
            // for authoritative or operator resolution.
            GuardedDeliverySend::detach($notification, static::class);

            throw $recordingException;
        }

        if (! $recorded) {
            GuardedDeliverySend::detach($notification, static::class);

            return $response;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operationSnapshot(Notification $notification): array
    {
        if (! property_exists($notification, 'summary') || ! is_array($notification->summary)) {
            return [];
        }

        $summary = $notification->summary;

        return [
            'plan_key' => isset($summary['plan_key']) && is_string($summary['plan_key']) ? $summary['plan_key'] : null,
            'slot_id' => isset($summary['slot_id']) && is_string($summary['slot_id']) ? $summary['slot_id'] : null,
            'payload_hash' => hash('sha256', (string) json_encode([
                'text' => $summary['text'] ?? '',
                'counts' => $summary['counts'] ?? [],
                'sections' => $summary['sections'] ?? [],
                'window' => $summary['window'] ?? [],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultFromResponse(mixed $response): array
    {
        if (is_object($response) && method_exists($response, 'toArray')) {
            $converted = $response->toArray();

            return is_array($converted) ? $converted : [];
        }

        if (is_array($response)) {
            return $response;
        }

        if (is_string($response) && $response !== '') {
            return ['provider_message_id' => $response];
        }

        if (is_object($response) && method_exists($response, 'getMessageId')) {
            $messageId = $response->getMessageId();

            return is_string($messageId) && $messageId !== '' ? ['provider_message_id' => $messageId] : [];
        }

        return [];
    }

    private function native(): MailChannel
    {
        return new MailChannel($this->mailer, $this->markdown);
    }
}
