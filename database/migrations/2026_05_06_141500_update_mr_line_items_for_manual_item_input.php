<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('mr_line_items', 'item_name')) {
            Schema::table('mr_line_items', function (Blueprint $table) {
                $table->string('item_name')->nullable()->after('item_id');
            });
        }

        // Make item_id nullable + FK nullOnDelete (safe for Postgres without doctrine/dbal).
        Schema::table('mr_line_items', function (Blueprint $table) {
            // drop existing FK if any
            try {
                $table->dropForeign(['item_id']);
            } catch (\Throwable $e) {
                // ignore if doesn't exist
            }
        });

        DB::statement('ALTER TABLE mr_line_items ALTER COLUMN item_id DROP NOT NULL');

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('master_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            try {
                $table->dropForeign(['item_id']);
            } catch (\Throwable $e) {
                // ignore
            }
        });

        // NOTE: may fail if NULL item_id rows exist.
        DB::statement('ALTER TABLE mr_line_items ALTER COLUMN item_id SET NOT NULL');

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('master_items')->cascadeOnDelete();
            if (Schema::hasColumn('mr_line_items', 'item_name')) {
                $table->dropColumn('item_name');
            }
        });
    }
};
