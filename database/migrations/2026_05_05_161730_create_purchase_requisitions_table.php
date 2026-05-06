<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->enum('source_type', ['mr', 'sr'])->default('mr');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->enum('pr_type', ['project', 'non_project']);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->integer('tier_count')->default(1);
            $table->integer('current_tier')->default(0);
            $table->string('status')->default('auto_created');
            $table->foreignId('pihak1_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('pr_type');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};