<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['asset', 'consumable', 'spare_part', 'other'])->default('consumable');
            $table->string('unit');
            $table->string('category')->nullable();
            $table->string('asset_code')->nullable();
            $table->string('coa')->nullable();
            $table->enum('status', ['inactive', 'pending_accounting', 'active', 'declined'])->default('inactive');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_items');
    }
};