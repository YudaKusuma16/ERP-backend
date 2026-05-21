<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->foreignId('wo_id')
                ->nullable()
                ->after('source_type')
                ->constrained('work_orders')
                ->nullOnDelete();

            $table->index('wo_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wo_id');
        });
    }
};

