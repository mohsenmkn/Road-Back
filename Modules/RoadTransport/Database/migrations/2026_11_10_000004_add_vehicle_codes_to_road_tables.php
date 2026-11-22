<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\RoadTransport\App\Models\RoadVehicle;

return new class extends Migration {
    private array $vehicles = [
        [501, '977ع14'], [502, '261ع15'], [503, '282ع24'], [504, '988ع14'], [505, '982ع14'],
        [506, '973ع14'], [507, '231ع15'], [508, '258ع15'], [509, '862ع12'], [510, '997ع14'],
        [511, '999ع14'], [512, '214ع15'], [513, '537ع23'], [514, '995ع14'], [515, '981ع14'],
        [516, '232ع15'], [517, '265ع15'], [518, '248ع15'], [519, '236ع15'], [520, '223ع15'],
        [521, '259ع24'], [522, '219ع15'], [523, '247ع15'], [524, '221ع15'], [525, '238ع15'],
        [526, '235ع15'], [527, '224ع15'], [528, '225ع15'], [529, '237ع15'], [530, '222ع15'],
        [531, '266ع15'], [532, '286ع24'], [533, '196ع24'], [534, '251ع24'], [535, '992ع14'],
        [536, '199ع24'], [537, '313ع24'], [538, '985ع14'], [539, '838ع18'], [540, '339ع24'],
        [541, '269ع24'], [542, '918ع74'], [543, '986ع14'], [544, '991ع14'], [545, '863ع12'],
        [546, '216ع15'], [547, '242ع15'], [548, '975ع14'], [549, '866ع23'], [550, '325ع24'],
        [551, '246ع15'], [552, '155ع11'], [553, '998ع14'], [554, '798ع98'], [555, '976ع14'],
        [556, '987ع14'], [557, '989ع14'], [558, '866ع12'], [559, '767ع24'], [560, '978ع14'],
        [561, '983ع14'], [562, '953ع74'], [563, '855ع12'], [564, '218ع15'], [565, '299ع24'],
        [566, '245ع15'], [567, '241ع15'], [568, '212ع15'], [569, '688ع24'], [570, '996ع14'],
        [571, '864ع12'], [572, '733ع24'], [573, '843ع12'], [574, '212ع24'], [575, '884ع74'],
        [576, '294ع24'], [577, '726ع24'], [578, '672ع24'], [579, '297ع24'], [580, '916ع23'],
        [581, '924ع23'], [582, '271ع24'], [583, '979ع14'], [584, '729ع24'], [585, '994ع14'],
        [586, '323ع24'], [587, '375ع98'], [588, '719ع24'], [589, '984ع14'], [590, '856ع12'],
        [591, '163ع11'], [592, '534ع23'], [593, '324ع17'], [594, '979ع74'], [595, '867ع12'],
        [596, '972ع14'], [597, '611ع99'], [598, '914ع23'], [599, '711ع24'], [600, '227ع15'],
        [701, '993ع14'], [702, '868ع12'], [703, '249ع24'], [704, '213ع15'],
    ];

    public function up(): void
    {
        Schema::create('road_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vehicle_code')->unique();
            $table->string('plate', 100);
            $table->string('normalized_plate', 100)->unique();
            $table->timestamps();
        });

        Schema::table('road_bills', function (Blueprint $table) {
            $table->unsignedInteger('vehicle_code')->nullable()->after('vehicle_plate')->index();
        });

        Schema::table('road_trips', function (Blueprint $table) {
            $table->unsignedInteger('vehicle_code')->nullable()->after('vehicle_plate')->index();
        });

        $now = now();
        $rows = array_map(fn (array $vehicle) => [
            'vehicle_code' => $vehicle[0],
            'plate' => $vehicle[1],
            'normalized_plate' => RoadVehicle::normalizePlate($vehicle[1]),
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->vehicles);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('road_vehicles')->insert($chunk);
        }

        $plateCodes = DB::table('road_vehicles')->pluck('vehicle_code', 'normalized_plate');
        foreach (['road_bills', 'road_trips'] as $table) {
            DB::table($table)->select(['id', 'vehicle_plate'])->orderBy('id')->chunk(500, function ($records) use ($table, $plateCodes) {
                foreach ($records as $record) {
                    $code = $plateCodes->get(RoadVehicle::normalizePlate($record->vehicle_plate));
                    if ($code !== null) {
                        DB::table($table)->where('id', $record->id)->update(['vehicle_code' => $code]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('road_trips', function (Blueprint $table) {
            $table->dropIndex(['vehicle_code']);
            $table->dropColumn('vehicle_code');
        });
        Schema::table('road_bills', function (Blueprint $table) {
            $table->dropIndex(['vehicle_code']);
            $table->dropColumn('vehicle_code');
        });
        Schema::dropIfExists('road_vehicles');
    }
};
