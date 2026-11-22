<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_manual_incomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vehicle_code')->index();
            $table->string('company_name', 150);
            $table->dateTime('from_at')->index();
            $table->dateTime('to_at');
            $table->decimal('working_hours', 7, 2);
            $table->unsignedBigInteger('revenue_rials');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_code', 'from_at'], 'road_manual_income_vehicle_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_manual_incomes');
    }
};
