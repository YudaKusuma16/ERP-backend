<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rd_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rd_id')->constrained('receiving_documents')->cascadeOnDelete();
            $table->string('item_name');
            $table->string('serial_number')->nullable();
            $table->string('tag_number')->nullable()->unique();
            $table->string('location')->nullable();
            $table->text('condition_notes')->nullable();
            $table->timestamps();

            $table->index('rd_id');
            $table->index('tag_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rd_line_items');
    }
};