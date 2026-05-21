<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_receiving_documents', function (Blueprint $table) {
            $table->dropForeign(['po_id']);
            $table->unsignedBigInteger('po_id')->nullable()->change();
            $table->foreign('po_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreignId('mr_id')->nullable()->after('po_id')->constrained('material_requests')->nullOnDelete();
            $table->index('mr_id');
        });

        Schema::table('pre_rd_lines', function (Blueprint $table) {
            $table->dropForeign(['po_line_id']);
            $table->unsignedBigInteger('po_line_id')->nullable()->change();
            $table->foreign('po_line_id')->references('id')->on('po_line_items')->nullOnDelete();
            $table->foreignId('mr_line_id')->nullable()->after('po_line_id')->constrained('mr_line_items')->nullOnDelete();
            $table->index('mr_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('pre_rd_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mr_line_id');
            $table->dropForeign(['po_line_id']);
            $table->unsignedBigInteger('po_line_id')->nullable(false)->change();
            $table->foreign('po_line_id')->references('id')->on('po_line_items')->cascadeOnDelete();
        });

        Schema::table('pre_receiving_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mr_id');
            $table->dropForeign(['po_id']);
            $table->unsignedBigInteger('po_id')->nullable(false)->change();
            $table->foreign('po_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });
    }
};
