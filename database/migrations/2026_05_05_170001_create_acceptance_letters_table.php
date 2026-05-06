<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_letters', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->foreignId('wo_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('status')->default('auto_created');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decline_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('wo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_letters');
    }
};