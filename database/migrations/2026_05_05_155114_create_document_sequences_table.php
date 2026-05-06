<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type');
            $table->string('prefix')->default('');
            $table->integer('year');
            $table->integer('month');
            $table->integer('current_sequence')->default(0);
            $table->enum('reset_period', ['monthly', 'yearly'])->default('monthly');
            $table->timestamps();

            $table->unique(['document_type', 'year', 'month'], 'doc_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};