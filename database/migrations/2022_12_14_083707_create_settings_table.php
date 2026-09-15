<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings') && ! $this->hasPackageIntegerSchema()) {
            return;
        }

        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->jsonb('payload');

            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }

    private function hasPackageIntegerSchema(): bool
    {
        try {
            return in_array(Schema::getColumnType('settings', 'id'), ['integer', 'bigint'], true);
        } catch (Throwable) {
            return false;
        }
    }
};
