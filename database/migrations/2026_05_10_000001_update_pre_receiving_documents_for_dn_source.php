<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make this migration idempotent across local env resets.
        if (Schema::hasColumn('pre_receiving_documents', 'po_id')) {
            Schema::table('pre_receiving_documents', function (Blueprint $table) {
                $table->foreignId('po_id')->nullable()->change();
            });
        }

        if (!Schema::hasColumn('pre_receiving_documents', 'dn_id')) {
            Schema::table('pre_receiving_documents', function (Blueprint $table) {
                $table->foreignId('dn_id')->nullable()->after('po_id')->constrained('delivery_notes')->nullOnDelete();
                $table->index('dn_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pre_receiving_documents', 'dn_id')) {
            Schema::table('pre_receiving_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('dn_id');
            });
        }

        if (Schema::hasColumn('pre_receiving_documents', 'po_id')) {
            Schema::table('pre_receiving_documents', function (Blueprint $table) {
                $table->foreignId('po_id')->nullable(false)->change();
            });
        }
    }
};

