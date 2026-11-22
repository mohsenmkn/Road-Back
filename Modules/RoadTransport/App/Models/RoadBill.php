<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadBill extends Model
{
    protected $table = 'road_bills';
    protected $guarded = ['id'];

    protected $casts = [
        'bill_issued_at' => 'datetime',
        'bill_canceled' => 'boolean',
        'bill_snapshot' => 'array',
        'synced_at' => 'datetime',
    ];
}
