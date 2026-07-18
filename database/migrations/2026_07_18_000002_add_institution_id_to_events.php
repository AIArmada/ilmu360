<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $eventsTable = (string) config('events.database.tables.events', 'events');

        Schema::table($eventsTable, function (Blueprint $table): void {
            $table->uuid('institution_id')->nullable()->index();
            $table->index(['institution_id', 'status', 'visibility']);
        });
    }
};
