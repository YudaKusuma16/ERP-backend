<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->string('item_name')->nullable()->after('item_id');
        });

        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreignId('item_id')->nullable()->change();
            $table->foreign('item_id')->references('id')->on('master_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->foreignId('item_id')->nullable(false)->change();
            $table->foreign('item_id')->references('id')->on('master_items')->cascadeOnDelete();
            $table->dropColumn('item_name');
        });
    }
};
