<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadTrip extends Model
{
    protected $table = 'road_trips';
    protected $guarded = ['id'];
    protected $casts = [
        'bill_issued_at' => 'datetime',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
        'finished_at' => 'datetime',
        'bill_snapshot' => 'array',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class, 'trip_id');
    }
}
