<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_indirect_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('allocation_method', 30);
            $table->unsignedBigInteger('total_rials')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['year', 'month'], 'road_indirect_expense_period_idx');
        });

        Schema::create('road_indirect_expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indirect_expense_id')->constrained('road_indirect_expenses')->cascadeOnDelete();
            $table->unsignedInteger('vehicle_code')->index();
            $table->string('vehicle_plate', 100)->nullable();
            $table->unsignedInteger('personnel_code')->index();
            $table->string('employee_name', 150);
            $table->decimal('effective_days', 8, 2)->nullable();
            $table->unsignedBigInteger('amount_rials');
            $table->timestamps();
            $table->unique(['indirect_expense_id', 'vehicle_code'], 'road_indirect_expense_vehicle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_indirect_expense_allocations');
        Schema::dropIfExists('road_indirect_expenses');
    }
};
