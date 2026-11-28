<?php

use Illuminate\Support\Facades\Route;
use Modules\RoadTransport\App\Http\Controllers\TripExpenseController;
use Modules\RoadTransport\App\Http\Controllers\DriverTripController;
use Modules\RoadTransport\App\Http\Controllers\RoadRouteController;
use Modules\RoadTransport\App\Http\Controllers\ExpenseCategoryController;
use Modules\RoadTransport\App\Http\Controllers\BillFinancialController;
use Modules\RoadTransport\App\Http\Controllers\RoadIndirectCostCoefficientController;
use Modules\RoadTransport\App\Http\Controllers\RoadBitaSyncController;

Route::prefix('v1/road-transport/driver')->middleware('throttle:30,1')->group(function () {
    Route::post('request-otp', [DriverTripController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('verify-otp', [DriverTripController::class, 'verifyOtp'])->middleware('throttle:20,1');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('start-trip', [DriverTripController::class, 'start']);
        Route::get('current-trip', [DriverTripController::class, 'current']);
        Route::get('expense-categories', [DriverTripController::class, 'categories']);
        Route::get('expenses', [DriverTripController::class, 'expenses']);
        Route::post('expenses', [DriverTripController::class, 'storeExpense']);
    });
});

Route::middleware(['auth:sanctum', 'user.can_login'])->prefix('v1/road-transport')->group(function () {
    Route::get('expense-categories', [TripExpenseController::class, 'categories'])->middleware('permission:road_expenses.read');
    Route::get('trips', [TripExpenseController::class, 'trips'])->middleware('permission:road_expenses.read');
    Route::get('bill-financials/vehicles', [BillFinancialController::class, 'vehicles'])->middleware('permission:road_expenses.read');
    Route::get('bill-financials/bita-sync/latest', [RoadBitaSyncController::class, 'latest'])->middleware('permission:road_expenses.read');
    Route::post('bill-financials/bita-sync', [RoadBitaSyncController::class, 'sync'])->middleware('permission:road_bills.sync');
    Route::get('bill-financials/monthly', [BillFinancialController::class, 'monthly'])->middleware('permission:road_expenses.read');
    Route::post('bill-financials/manual-income', [BillFinancialController::class, 'storeManualIncome'])->middleware('permission:road_manual_income.create');
    Route::post('bill-financials/manual-expense', [BillFinancialController::class, 'storeManualExpense'])->middleware('permission:road_manual_income.create');
    Route::get('bill-financials/indirect-expenses', [RoadIndirectCostCoefficientController::class, 'index'])->middleware('permission:road_indirect_cost.manage');
    Route::post('bill-financials/indirect-expenses', [RoadIndirectCostCoefficientController::class, 'store'])->middleware('permission:road_indirect_cost.manage');
    Route::put('bill-financials/indirect-expenses/{coefficient}', [RoadIndirectCostCoefficientController::class, 'update'])->middleware('permission:road_indirect_cost.manage');
    Route::delete('bill-financials/indirect-expenses/{coefficient}', [RoadIndirectCostCoefficientController::class, 'destroy'])->middleware('permission:road_indirect_cost.manage');
    Route::get('trips/{trip}/odometer-photo', [TripExpenseController::class, 'odometerPhoto'])->middleware('permission:road_expenses.read');
    Route::get('expenses', [TripExpenseController::class, 'index'])->middleware('permission:road_expenses.read');
    Route::post('expenses', [TripExpenseController::class, 'store'])->middleware('permission:road_expenses.create');
    Route::get('routes', [RoadRouteController::class, 'index'])->middleware('permission:road_expenses.manage_categories');
    Route::put('routes/{route}', [RoadRouteController::class, 'update'])->middleware('permission:road_expenses.manage_categories');
    Route::patch('routes/{route}', [RoadRouteController::class, 'update'])->middleware('permission:road_expenses.manage_categories');
    Route::apiResource('expense-catalog', ExpenseCategoryController::class)->except(['show'])
        ->parameters(['expense-catalog' => 'category'])
        ->middleware('permission:road_expenses.manage_categories');
});

