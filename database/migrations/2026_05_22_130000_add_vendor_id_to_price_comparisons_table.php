<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_comparisons', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('po_id')->constrained('master_vendors')->nullOnDelete();
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_comparisons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
