<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('road_indirect_cost_coefficients')) {
            if (Schema::hasTable('road_insurance_coefficients')) {
                Schema::rename('road_insurance_coefficients', 'road_indirect_cost_coefficients');
            } else {
                Schema::create('road_indirect_cost_coefficients', function (Blueprint $table) {
                    $table->id();
                    $table->string('title', 150);
                    $table->decimal('factor', 10, 4);
                    $table->text('notes')->nullable();
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamps();
                });
            }
        }

        if (Schema::hasColumn('road_indirect_cost_coefficients', 'setting_key')) {
            $settingKeyIndexes = array_filter(
                Schema::getIndexes('road_indirect_cost_coefficients'),
                fn (array $index): bool => $index['unique']
                    && in_array('setting_key', $index['columns'], true)
            );

            foreach ($settingKeyIndexes as $index) {
                Schema::table('road_indirect_cost_coefficients', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }

            Schema::table('road_indirect_cost_coefficients', function (Blueprint $table) {
                $table->dropColumn('setting_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('road_indirect_cost_coefficients') && !Schema::hasTable('road_insurance_coefficients')) {
            Schema::rename('road_indirect_cost_coefficients', 'road_insurance_coefficients');
            if (!Schema::hasColumn('road_insurance_coefficients', 'setting_key')) {
                Schema::table('road_insurance_coefficients', function (Blueprint $table) {
                    $table->string('setting_key', 50)->nullable()->unique();
                });
            }
        }
    }
};
