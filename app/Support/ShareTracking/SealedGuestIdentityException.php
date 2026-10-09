<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use RuntimeException;

/**
 * The guest identity presented for a share write was sealed by a claim
 * after the caller read it: its credential is revoked, expired, or
 * detached, or a claim row now owns its history. Thrown only inside the
 * creation transaction after the revalidation locks are held, so the
 * attempt rolls back and the caller issues a truthful fresh anonymous
 * identity instead of authoring a post-seal link into owned history.
 * Never escapes the share-tracking service boundary.
 */
final class SealedGuestIdentityException extends RuntimeException {}
