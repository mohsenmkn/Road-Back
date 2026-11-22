<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_bill_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('road_bill_id')->constrained('road_bills')->cascadeOnDelete();
            $table->unsignedInteger('vehicle_code')->index();
            $table->string('vehicle_plate', 100)->nullable();
            $table->string('bill_number', 50)->index();
            $table->string('bill_serial', 50)->default('');
            $table->timestamp('bill_issued_at')->nullable()->index();
            $table->unsignedBigInteger('revenue_rials')->default(0);
            $table->unsignedBigInteger('expense_rials')->nullable();
            $table->json('financial_details');
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique('road_bill_id');
            $table->index(['vehicle_code', 'bill_issued_at'], 'road_bill_financials_vehicle_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_bill_financials');
    }
};
