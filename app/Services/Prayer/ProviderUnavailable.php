<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use RuntimeException;

/**
 * A prayer-times provider could not serve a query (network, 4xx/5xx, bad
 * payload, unpublished month). Callers degrade down the fallback chain;
 * this exception must never surface on the submit path.
 */
final class ProviderUnavailable extends RuntimeException {}
