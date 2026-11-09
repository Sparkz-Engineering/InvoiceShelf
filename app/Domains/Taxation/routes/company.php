<?php

use App\Domains\Taxation\Http\Controllers\BasLodgementsController;
use App\Domains\Taxation\Http\Controllers\TaxDeductionsController;
use App\Domains\Taxation\Http\Controllers\TaxTypesController;
use Illuminate\Support\Facades\Route;

Route::controller(TaxTypesController::class)
    ->name('tax-types.')
    ->group(function (): void {
        Route::get('tax-types', 'index')->name('index');
        Route::post('tax-types', 'store')->name('store');
        Route::get('tax-types/{tax_type}', 'show')->name('show');
        Route::match(['PUT', 'PATCH'], 'tax-types/{tax_type}', 'update')->name('update');
        Route::delete('tax-types/{tax_type}', 'destroy')->name('destroy');
    });

// Business Activity Statements marked as lodged, with their figures
Route::get('bas-lodgements', [BasLodgementsController::class, 'index']);
Route::post('bas-lodgements', [BasLodgementsController::class, 'store']);
Route::delete('bas-lodgements/{basLodgement}', [BasLodgementsController::class, 'destroy']);

// Deductions recorded by hand: home office, vehicle, depreciation and the like
Route::apiResource('tax-deductions', TaxDeductionsController::class)
    ->parameters(['tax-deductions' => 'taxDeduction']);
