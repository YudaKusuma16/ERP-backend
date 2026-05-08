<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mr_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mr_id')->constrained('material_requests')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('master_items')->nullOnDelete();
            $table->string('item_name')->nullable();
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('unit');
            $table->text('description')->nullable();
            $table->boolean('flagged')->default(false);
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('mr_id');
            $table->index('item_id');
            $table->index('item_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mr_line_items');
    }
};