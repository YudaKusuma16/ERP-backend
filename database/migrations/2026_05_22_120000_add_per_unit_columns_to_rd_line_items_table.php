<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rd_line_items', function (Blueprint $table) {
            $table->foreignId('pre_rd_line_id')->nullable()->after('rd_id')->constrained('pre_rd_lines')->nullOnDelete();
            $table->unsignedInteger('unit_index')->nullable()->after('pre_rd_line_id')->comment('1-based unit index within the pre_rd_line (1 of N)');

            $table->index('pre_rd_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('rd_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pre_rd_line_id');
            $table->dropColumn('unit_index');
        });
    }
};
