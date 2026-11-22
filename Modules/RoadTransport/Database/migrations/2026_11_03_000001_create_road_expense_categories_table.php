<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_routes', function (Blueprint $table) {
            $table->id();
            $table->string('origin', 150);
            $table->string('destination', 150);
            $table->unsignedSmallInteger('allowed_hours');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['origin', 'destination']);
        });

        Schema::create('road_trips', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 50);
            $table->string('bill_serial', 50)->nullable();
            $table->string('bill_company_code', 50)->nullable();
            $table->string('driver_mobile', 20);
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('origin', 150);
            $table->string('destination', 150);
            $table->foreignId('route_id')->nullable()->constrained('road_routes')->nullOnDelete();
            $table->timestamp('bill_issued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->string('odometer_photo_path')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('bill_snapshot')->nullable();
            $table->timestamps();
            $table->index(['bill_number', 'bill_serial']);
            $table->index(['driver_mobile', 'status']);
        });

        Schema::create('road_driver_otps', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 50);
            $table->string('bill_serial', 50)->nullable();
            $table->string('driver_mobile', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('session_token_hash')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamps();
            $table->index(['driver_mobile', 'created_at']);
        });

        Schema::create('road_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('max_amount_rials');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('road_trip_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained('road_trips')->nullOnDelete();
            $table->foreignId('category_id')->constrained('road_expense_categories');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_mobile', 20)->nullable();
            $table->unsignedBigInteger('amount_rials');
            $table->dateTime('spent_at');
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'spent_at'], 'road_trip_expenses_trip_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_trip_expenses');
        Schema::dropIfExists('road_expense_categories');
        Schema::dropIfExists('road_driver_otps');
        Schema::dropIfExists('road_trips');
        Schema::dropIfExists('road_routes');
    }
};

