<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_instructions', function (Blueprint $table) {
            $table->foreignId('pr_id')->nullable()->after('mr_id')->constrained('purchase_requisitions')->nullOnDelete();
            $table->index('pr_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_instructions', function (Blueprint $table) {
            $table->dropForeign(['pr_id']);
        });
    }
};
