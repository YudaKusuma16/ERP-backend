<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_rd_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_rd_id')->constrained('pre_receiving_documents')->cascadeOnDelete();
            $table->foreignId('po_line_id')->nullable()->constrained('po_line_items')->nullOnDelete();
            $table->string('item_name');
            $table->decimal('ordered_qty', 15, 2)->default(0);
            $table->decimal('received_qty', 15, 2)->default(0);
            $table->string('received_unit');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('pre_rd_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_rd_lines');
    }
};