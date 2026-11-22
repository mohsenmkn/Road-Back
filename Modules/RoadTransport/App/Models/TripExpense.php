<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripExpense extends Model
{
    protected $table = 'road_trip_expenses';
    protected $fillable = ['trip_id', 'category_id', 'registered_by', 'amount_rials', 'spent_at', 'description', 'receipt_path'];
    protected $casts = ['amount_rials' => 'integer', 'spent_at' => 'datetime'];

    public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }

    public function trip(): BelongsTo { return $this->belongsTo(RoadTrip::class, 'trip_id'); }
}
