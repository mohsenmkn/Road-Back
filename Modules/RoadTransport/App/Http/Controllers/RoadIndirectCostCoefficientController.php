<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\RoadTransport\App\Models\RoadIndirectCostCoefficient;

class RoadIndirectCostCoefficientController extends Controller
{
    public function index()
    {
        return response()->json(['data' => RoadIndirectCostCoefficient::query()->latest('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $coefficient = RoadIndirectCostCoefficient::create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $coefficient], 201);
    }

    public function update(Request $request, RoadIndirectCostCoefficient $coefficient)
    {
        $coefficient->update($this->validated($request));
        return response()->json(['data' => $coefficient->refresh()]);
    }

    public function destroy(RoadIndirectCostCoefficient $coefficient)
    {
        $coefficient->delete();
        return response()->json(['message' => 'ضریب هزینه غیرمستقیم حذف شد.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'factor' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:1,4'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

