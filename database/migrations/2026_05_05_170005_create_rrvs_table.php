<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rrvs', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->foreignId('sr_id')->nullable()->constrained('service_requests')->nullOnDelete();
            $table->foreignId('dn_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('master_vendors')->nullOnDelete();
            $table->text('replacement_item_detail')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('sr_id');
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rrvs');
    }
};