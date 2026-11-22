<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadVehicle extends Model
{
    protected $table = 'road_vehicles';
    protected $guarded = ['id'];
    protected $casts = ['vehicle_code' => 'integer'];

    public static function normalizePlate(?string $plate): string
    {
        $plate = strtr(trim((string) $plate), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            'ي'=>'ی','ك'=>'ک',
        ]);

        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $plate) ?? '');
    }

    public static function codeForPlate(?string $plate): ?int
    {
        $normalized = self::normalizePlate($plate);
        if ($normalized === '') return null;

        $code = static::query()->where('normalized_plate', $normalized)->value('vehicle_code');
        return $code === null ? null : (int) $code;
    }

    public static function isRegistered(?string $plate, ?int $code = null): bool
    {
        $registeredCode = self::codeForPlate($plate);
        return $registeredCode !== null && ($code === null || $registeredCode === $code);
    }
}
