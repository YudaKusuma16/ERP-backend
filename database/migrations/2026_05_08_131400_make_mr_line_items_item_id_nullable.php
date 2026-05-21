<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: pada fresh install, kondisi ini sudah benar dari create migration.
        // Migrasi ini hanya berdampak pada DB lama yang masih punya item_id NOT NULL.
        Schema::table('mr_line_items', function (Blueprint $table) {
            try {
                $table->dropForeign(['item_id']);
            } catch (\Throwable $e) {
                // ignore kalau FK belum ada
            }
        });

        // ALTER ... DROP NOT NULL bersifat idempotent di Postgres.
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

        // WARNING: bisa gagal kalau ada baris dengan item_id NULL.
        DB::statement('ALTER TABLE mr_line_items ALTER COLUMN item_id SET NOT NULL');

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('master_items')->cascadeOnDelete();
        });
    }
};
