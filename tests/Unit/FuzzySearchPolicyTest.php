<?php

declare(strict_types=1);

use App\Support\Search\FuzzySearchPolicy;

it('keeps the application fuzzy comparability policy explicit', function (): void {
    expect(FuzzySearchPolicy::isComparable('salam', 'salem', 1))->toBeTrue()
        ->and(FuzzySearchPolicy::isComparable('salam', 'salem', 0))->toBeFalse()
        ->and(FuzzySearchPolicy::isComparable('salam', 'kalem', 2))->toBeFalse()
        ->and(FuzzySearchPolicy::isComparable('salam', 'salem'))->toBeTrue();
});
