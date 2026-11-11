<?php

declare(strict_types=1);

namespace App\Shared\Registration;

/**
 * Sends the confirmation for a newly created attendance record.
 *
 * Registration calls this after its self-check-in transaction commits so a
 * replayed/duplicate check-in never re-notifies. The implementation resolves
 * the attendance record and owns delivery through the root notification
 * service, keeping the feature independent of that service.
 */
interface CheckInNotifier
{
    public function notifyConfirmed(string $checkinId): void;
}
