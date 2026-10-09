<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rewards\ResolveDigestMailAmbiguityAction;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ResolveDigestMailAmbiguityCommand extends Command
{
    protected $signature = 'rewards:resolve-digest-mail-ambiguity {delivery : Ambiguous mail delivery id (UUID, Sending with an unresolved attempt)} {decision : Resolution decision: sent|failed} {--actor= : Resolving operator user id (must exist and hold super_admin/admin; verified, never self-claimed)} {--provider-message-id= : Provider message id observed in mail logs (required when decision=sent, 1..191 chars)} {--reason= : Verified non-delivery evidence/reason (required when decision=failed, 1..191 chars, provider confirmed never accepted)}';

    protected $description = 'Resolve an ambiguous digest-mail delivery with operator evidence; verifies the operator and never sends mail.';

    protected $help = 'Incident path for UNKNOWN/Sending digest mail (transport timeout after acceptance, or recording crash). Only Sending deliveries with a started-but-unresolved owning attempt may resolve; settled, non-ambiguous, or superseded work refuses. Decision sent requires the provider message id observed in mail logs; decision failed requires verified non-delivery evidence after confirming the provider never accepted. The operator id must exist and hold super_admin/admin (checked against the database, never a self-claimed role string). Actor, evidence, and time are stamped onto the owning attempt metadata. This command only records the decision and never transmits mail.';

    public function handle(ResolveDigestMailAmbiguityAction $resolve): int
    {
        $deliveryId = (string) $this->argument('delivery');
        $decision = (string) $this->argument('decision');
        $actorOption = $this->option('actor');
        $providerMessageId = $this->option('provider-message-id');
        $reason = $this->option('reason');

        if (! in_array($decision, ['sent', 'failed'], true)) {
            $this->error('Decision must be sent or failed.');

            return self::INVALID;
        }

        if (! is_string($actorOption) || $actorOption === '') {
            $this->error('Resolution requires --actor=<operator user id> for attribution.');

            return self::INVALID;
        }

        if ($decision === 'sent') {
            if (! is_string($providerMessageId) || $providerMessageId === '' || mb_strlen($providerMessageId) > 191) {
                $this->error('Resolving as sent requires --provider-message-id=<observed id, 1..191 chars>.');

                return self::INVALID;
            }
        } elseif (! is_string($reason) || $reason === '' || mb_strlen($reason) > 191) {
            $this->error('Resolving as failed requires --reason=<verified non-delivery evidence, 1..191 chars>.');

            return self::INVALID;
        }

        $operator = User::query()->whereKey($actorOption)->first();

        if (! $operator instanceof User) {
            $this->error('Unknown operator; resolution requires an existing staff/admin user.');

            return self::FAILURE;
        }

        if (! $operator->hasAnyRole(['super_admin', 'admin'])) {
            $this->error('Operator lacks resolution rights; super_admin/admin required.');

            return self::FAILURE;
        }

        try {
            $delivery = $resolve->handle(
                $deliveryId,
                $decision,
                'user',
                (string) $operator->getKey(),
                'admin',
                is_string($providerMessageId) && $providerMessageId !== '' ? $providerMessageId : null,
                is_string($reason) && $reason !== '' ? $reason : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $evidence = $decision === 'sent'
            ? 'provider_message_id='.((string) $providerMessageId)
            : 'reason='.((string) $reason);

        $this->info("Resolved mail delivery {$delivery->getKey()} as {$decision} ({$delivery->status->value}; {$evidence}; actor=user:{$operator->getKey()}:admin). No mail sent.");

        return self::SUCCESS;
    }
}
