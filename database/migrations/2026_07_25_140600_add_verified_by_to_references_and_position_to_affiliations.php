<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            if (! Schema::hasColumn('persons', 'speaker_status')) {
                $table->string('speaker_status')->nullable()->index()->after('status');
            }

            if (! Schema::hasColumn('persons', 'allow_public_event_submission')) {
                $table->boolean('allow_public_event_submission')->default(true);
            }

            if (! Schema::hasColumn('persons', 'public_submission_locked_at')) {
                $table->timestampTz('public_submission_locked_at')->nullable();
            }

            if (! Schema::hasColumn('persons', 'public_submission_locked_by')) {
                $table->foreignUuid('public_submission_locked_by')->nullable();
            }
        });
    }
};
