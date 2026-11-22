<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('road_driver_otps', function (Blueprint $table) {
            $table->foreignId('road_bill_id')
                ->nullable()
                ->after('id')
                ->constrained('road_bills')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('road_driver_otps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('road_bill_id');
        });
    }
};
