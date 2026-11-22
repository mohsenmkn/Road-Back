<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadBitaSyncRun extends Model
{
    protected $table = 'road_bita_sync_runs';
    protected $guarded = ['id'];
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
