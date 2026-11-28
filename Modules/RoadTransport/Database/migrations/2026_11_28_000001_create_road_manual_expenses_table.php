<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_manual_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vehicle_code')->index();
            $table->string('subject', 150);
            $table->dateTime('spent_at')->index();
            $table->unsignedBigInteger('amount_rials');
            $table->text('description')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_code', 'spent_at'], 'road_manual_expense_vehicle_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_manual_expenses');
    }
};
