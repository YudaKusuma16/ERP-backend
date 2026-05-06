<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('vendor_name');
            $table->decimal('quoted_price', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('po_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_comparisons');
    }
};