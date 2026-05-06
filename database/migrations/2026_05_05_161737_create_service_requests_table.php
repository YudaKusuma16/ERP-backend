<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('date');
            $table->enum('source_type', ['internal', 'customer', '3rd_party', 'project']);
            $table->string('source_doc_ref')->nullable();
            $table->foreignId('requestor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->string('decline_reason')->nullable();
            $table->foreignId('approved_by_dept_head')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_pihak2')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('source_type');
            $table->index('requestor_id');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};