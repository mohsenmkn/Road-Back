<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadIndirectCostCoefficient extends Model
{
    protected $table = 'road_indirect_cost_coefficients';
    protected $guarded = ['id'];
    protected $casts = ['factor' => 'float'];
}
