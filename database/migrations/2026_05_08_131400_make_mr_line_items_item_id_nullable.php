<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            // drop existing FK first (typically mr_line_items_item_id_foreign)
            $table->dropForeign(['item_id']);
        });

        // make item_id nullable (Postgres-safe without doctrine/dbal)
        DB::statement('ALTER TABLE mr_line_items ALTER COLUMN item_id DROP NOT NULL');

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('master_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
        });

        // WARNING: will fail if there are NULL item_id rows.
        DB::statement('ALTER TABLE mr_line_items ALTER COLUMN item_id SET NOT NULL');

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('master_items')->cascadeOnDelete();
        });
    }
};

