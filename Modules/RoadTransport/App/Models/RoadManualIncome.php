<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadManualIncome extends Model
{
    protected $table = 'road_manual_incomes';
    protected $guarded = ['id'];
    protected $casts = [
        'from_at' => 'datetime',
        'to_at' => 'datetime',
        'working_hours' => 'decimal:2',
        'revenue_rials' => 'integer',
    ];
}
