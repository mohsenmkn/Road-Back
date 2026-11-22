<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\RoadTransport\App\Models\ExpenseCategory;

class ExpenseCategoryController extends Controller
{
    public function index() { return ExpenseCategory::orderBy('name')->paginate(100); }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:road_expense_categories,name'], 'max_amount_rials' => ['required', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']]);
        return response()->json(['data' => ExpenseCategory::create($data)], 201);
    }

    public function update(Request $request, ExpenseCategory $category)
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100', 'unique:road_expense_categories,name,'.$category->id], 'max_amount_rials' => ['sometimes', 'required', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']]);
        $category->update($data);
        return response()->json(['data' => $category->fresh()]);
    }

    public function destroy(ExpenseCategory $category)
    {
        if ($category->expenses()->exists()) $category->update(['is_active' => false]);
        else $category->delete();
        return response()->json(['message' => 'نوع هزینه حذف یا غیرفعال شد.']);
    }
}

