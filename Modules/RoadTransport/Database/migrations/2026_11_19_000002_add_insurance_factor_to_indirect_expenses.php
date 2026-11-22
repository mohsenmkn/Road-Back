<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('road_indirect_expenses', function (Blueprint $table) {
            $table->decimal('factor', 10, 4)->nullable()->after('allocation_method');
        });
        Schema::table('road_indirect_expense_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('net_pay_rials')->nullable()->after('effective_days');
        });
    }

    public function down(): void
    {
        Schema::table('road_indirect_expense_allocations', function (Blueprint $table) {
            $table->dropColumn('net_pay_rials');
        });
        Schema::table('road_indirect_expenses', function (Blueprint $table) {
            $table->dropColumn('factor');
        });
    }
};
