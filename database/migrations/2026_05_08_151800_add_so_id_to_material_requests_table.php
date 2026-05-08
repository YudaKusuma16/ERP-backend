<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->foreignId('so_id')
                ->nullable()
                ->after('wo_id')
                ->constrained('sales_orders')
                ->nullOnDelete();

            $table->index('so_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('so_id');
        });
    }
};

