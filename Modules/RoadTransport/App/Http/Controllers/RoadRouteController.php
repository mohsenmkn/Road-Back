<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\RoadTransport\App\Models\RoadRoute;

class RoadRouteController extends Controller
{
    public function index()
    {
        $now = now();
        $billRoutes = DB::table('road_bills')
            ->select('origin', 'destination')
            ->whereNotNull('origin')->whereNotNull('destination')
            ->where('origin', '<>', '')->where('destination', '<>', '')
            ->distinct()->get();

        $missingRoutes = $billRoutes->map(fn ($route) => [
            'origin' => trim($route->origin),
            'destination' => trim($route->destination),
            'allowed_hours' => 48,
            'max_expense_rials' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($missingRoutes, 500) as $chunk) {
            DB::table('road_routes')->insertOrIgnore($chunk);
        }

        return RoadRoute::query()
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('road_bills')
                    ->whereRaw('TRIM(road_bills.origin) = road_routes.origin')
                    ->whereRaw('TRIM(road_bills.destination) = road_routes.destination');
            })
            ->orderBy('origin')->orderBy('destination')->get();
    }

    public function update(Request $request, RoadRoute $route)
    {
        $data = $request->validate([
            'allowed_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:720'],
            'max_expense_rials' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $route->update($data);
        return response()->json(['data' => $route->fresh()]);
    }

}
