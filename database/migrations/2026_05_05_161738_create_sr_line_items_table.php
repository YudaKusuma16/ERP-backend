<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sr_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sr_id')->constrained('service_requests')->cascadeOnDelete();
            $table->string('service_name');
            $table->decimal('qty', 15, 2)->default(0);
            $table->string('unit');
            $table->decimal('est_cost', 15, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('flagged')->default(false);
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sr_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sr_line_items');
    }
};