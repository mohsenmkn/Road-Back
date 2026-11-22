<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $table = 'road_expense_categories';
    protected $fillable = ['name', 'max_amount_rials', 'is_active'];
    protected $casts = ['max_amount_rials' => 'integer', 'is_active' => 'boolean'];

    public function expenses(): HasMany { return $this->hasMany(TripExpense::class, 'category_id'); }
}

