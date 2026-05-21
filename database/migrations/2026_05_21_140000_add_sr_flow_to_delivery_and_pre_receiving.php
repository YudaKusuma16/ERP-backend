<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_instructions', function (Blueprint $table) {
            $table->foreignId('sr_id')->nullable()->after('mr_id')->constrained('service_requests')->nullOnDelete();
            $table->index('sr_id');
        });

        Schema::table('pre_receiving_documents', function (Blueprint $table) {
            $table->foreignId('sr_id')->nullable()->after('mr_id')->constrained('service_requests')->nullOnDelete();
            $table->index('sr_id');
        });

        Schema::table('pre_rd_lines', function (Blueprint $table) {
            $table->foreignId('sr_line_id')->nullable()->after('mr_line_id')->constrained('sr_line_items')->nullOnDelete();
            $table->index('sr_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('pre_rd_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sr_line_id');
        });

        Schema::table('pre_receiving_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sr_id');
        });

        Schema::table('delivery_instructions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sr_id');
        });
    }
};
