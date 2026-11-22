<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('road_insurance_coefficients', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 50)->unique();
            $table->string('title', 150);
            $table->decimal('factor', 10, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_insurance_coefficients');
    }
};
