<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates both feedback tables', function () {
    expect(Schema::hasTable('app_feedback'))->toBeTrue()
        ->and(Schema::hasTable('app_feedback_messages'))->toBeTrue();
});
