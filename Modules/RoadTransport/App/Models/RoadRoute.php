<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadRoute extends Model
{
    protected $table = 'road_routes';
    protected $fillable = ['origin', 'destination', 'allowed_hours', 'max_expense_rials', 'is_active'];
    protected $casts = ['allowed_hours' => 'integer', 'max_expense_rials' => 'integer', 'is_active' => 'boolean'];

    public function trips(): HasMany { return $this->hasMany(RoadTrip::class, 'route_id'); }
}
