<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('unit');
            $table->decimal('final_price', 20, 2)->default(0);
            $table->decimal('discount', 20, 2)->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->default('fixed');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('po_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_line_items');
    }
};