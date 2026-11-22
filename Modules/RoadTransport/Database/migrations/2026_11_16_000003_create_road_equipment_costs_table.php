<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_equipment_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vehicle_code')->index();
            $table->unsignedInteger('equipment_type_id')->index();
            $table->string('equipment_type_title', 255)->nullable();
            $table->string('equipment_code', 100);
            $table->string('equipment_title', 255)->nullable();
            $table->unsignedBigInteger('voucher_id');
            $table->string('voucher_number', 100)->nullable();
            $table->dateTime('voucher_date')->index();
            $table->string('voucher_state', 100)->nullable();
            $table->text('voucher_description')->nullable();
            $table->unsignedInteger('row_number');
            $table->string('account_title', 255)->nullable();
            $table->string('account_code', 100)->nullable();
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->decimal('amount', 20, 2)->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['vehicle_code', 'voucher_id', 'row_number'], 'road_eq_cost_voucher_row_unique');
            $table->index(['vehicle_code', 'voucher_date'], 'road_eq_cost_vehicle_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_equipment_costs');
    }
};
