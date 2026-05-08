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
            $table->index('item_name');
        });
    }

    public function down(): void
    {
        Schema::table('mr_line_items', function (Blueprint $table) {
            $table->dropIndex(['item_name']);
            $table->dropColumn('item_name');
        });
    }
};

