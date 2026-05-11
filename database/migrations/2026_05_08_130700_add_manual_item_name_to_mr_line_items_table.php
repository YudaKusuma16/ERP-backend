<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: kolom & index ini sudah dibuat di create migration.
        // Migrasi ini hanya untuk DB lama yang belum punya kolom item_name.
        if (! Schema::hasColumn('mr_line_items', 'item_name')) {
            Schema::table('mr_line_items', function (Blueprint $table) {
                $table->string('item_name')->nullable()->after('item_id');
            });
        }

        $indexExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'mr_line_items' AND indexname = 'mr_line_items_item_name_index'"
        ))->isNotEmpty();

        if (! $indexExists) {
            Schema::table('mr_line_items', function (Blueprint $table) {
                $table->index('item_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            try {
                $table->dropIndex(['item_name']);
            } catch (\Throwable $e) {
                // ignore
            }
            if (Schema::hasColumn('mr_line_items', 'item_name')) {
                $table->dropColumn('item_name');
            }
        });
    }
};
