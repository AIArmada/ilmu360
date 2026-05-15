<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        if (! Schema::hasIndex('media', 'media_model_collection_order_index')) {
            Schema::table('media', function (Blueprint $table): void {
                $table->index(
                    ['model_type', 'model_id', 'collection_name', 'order_column'],
                    'media_model_collection_order_index'
                );
            });
        }

        if (! Schema::hasIndex('media', 'media_collection_created_at_index')) {
            Schema::table('media', function (Blueprint $table): void {
                $table->index(['collection_name', 'created_at'], 'media_collection_created_at_index');
            });
        }
    }
};
