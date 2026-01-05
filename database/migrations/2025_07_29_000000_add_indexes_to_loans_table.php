<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Add composite index for common query patterns
            $table->index(['branch_id', 'status', 'date_applied'], 'loans_branch_status_date_idx');
            $table->index(['status', 'date_applied'], 'loans_status_date_idx');
            $table->index(['customer_id', 'status'], 'loans_customer_status_idx');
            $table->index(['product_id', 'status'], 'loans_product_status_idx');
            // Index for date_applied for sorting
            $table->index('date_applied', 'loans_date_applied_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex('loans_branch_status_date_idx');
            $table->dropIndex('loans_status_date_idx');
            $table->dropIndex('loans_customer_status_idx');
            $table->dropIndex('loans_product_status_idx');
            $table->dropIndex('loans_date_applied_idx');
        });
    }
};
