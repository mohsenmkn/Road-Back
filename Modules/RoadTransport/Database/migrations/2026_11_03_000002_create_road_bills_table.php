<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_bills', function (Blueprint $table) {
            $table->id();
            $table->string('company_code', 50)->default('');
            $table->string('office_code', 50)->default('');
            $table->string('bill_number', 50);
            $table->string('bill_serial', 50)->default('');
            $table->string('driver_mobile', 20)->nullable()->index();
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate', 100)->nullable();
            $table->string('origin', 150)->nullable();
            $table->string('destination', 150)->nullable();
            $table->timestamp('bill_issued_at')->nullable()->index();
            $table->boolean('bill_canceled')->default(false);
            $table->json('bill_snapshot');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['company_code', 'office_code', 'bill_number', 'bill_serial'],
                'road_bills_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_bills');
    }
};
