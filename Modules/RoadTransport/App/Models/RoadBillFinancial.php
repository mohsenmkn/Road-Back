<?php

namespace Modules\RoadTransport\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadBillFinancial extends Model
{
    protected $table = 'road_bill_financials';
    protected $guarded = ['id'];
    protected $casts = [
        'bill_issued_at' => 'datetime',
        'revenue_rials' => 'integer',
        'expense_rials' => 'integer',
        'financial_details' => 'array',
        'synced_at' => 'datetime',
    ];
}
