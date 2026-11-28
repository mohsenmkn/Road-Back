<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadManualExpense extends Model
{
    protected $table = 'road_manual_expenses';
    protected $guarded = ['id'];
    protected $casts = [
        'spent_at' => 'datetime',
        'amount_rials' => 'integer',
    ];
}
