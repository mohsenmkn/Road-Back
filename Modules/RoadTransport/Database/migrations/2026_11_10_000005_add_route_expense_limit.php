<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('road_routes', function (Blueprint $table) {
            $table->unsignedBigInteger('max_expense_rials')->nullable()->after('allowed_hours');
        });
    }

    public function down(): void
    {
        Schema::table('road_routes', function (Blueprint $table) {
            $table->dropColumn('max_expense_rials');
        });
    }
};
