<?php

declare(strict_types=1);

it('does not expose cross-feature ORM ports from feature APIs', function (): void {
    $ports = glob(app_path('Features/*/Api/Orm/*.php'));

    expect($ports === false ? [] : $ports)->toBe([]);
});
