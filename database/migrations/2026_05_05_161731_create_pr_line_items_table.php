<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('unit');
            $table->decimal('initial_price', 20, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('pr_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_line_items');
    }
};