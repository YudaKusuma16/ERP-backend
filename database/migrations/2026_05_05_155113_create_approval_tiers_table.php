<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_tiers', function (Blueprint $table) {
            $table->id();
            $table->enum('document_type', ['pr', 'po']);
            $table->unsignedBigInteger('min_value')->default(0);
            $table->unsignedBigInteger('max_value')->nullable();
            $table->integer('tier_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_tiers');
    }
};