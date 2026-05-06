<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->foreignId('pre_rd_id')->constrained('pre_receiving_documents')->cascadeOnDelete();
            $table->string('status')->default('pending_input');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('pre_rd_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_documents');
    }
};