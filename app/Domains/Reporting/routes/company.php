<?php

use App\Domains\Reporting\Http\Controllers\Company\BasReportController;
use App\Domains\Reporting\Http\Controllers\Company\CustomerStatementController;
use App\Domains\Reporting\Http\Controllers\Company\DashboardController;
use App\Domains\Reporting\Http\Controllers\Company\ProjectReportController;
use App\Domains\Reporting\Http\Controllers\Company\SearchController;
use App\Domains\Reporting\Http\Controllers\Company\SendCustomerStatementController;
use App\Domains\Reporting\Http\Controllers\Company\TaxDeductionsReportController;
use Illuminate\Support\Facades\Route;

// The aggregate figures behind the admin landing page.
Route::get('dashboard', DashboardController::class);
Route::get('/search', SearchController::class);
Route::get('/search/user', [SearchController::class, 'users']);
Route::get('customers/{customer}/statement', CustomerStatementController::class);
Route::post('customers/{customer}/statement/send', SendCustomerStatementController::class);

// The Australian BAS worksheet, for the report page.
Route::get('reports/bas', BasReportController::class);

// A financial year's deductions: deductible expenses and those recorded by hand.
Route::get('reports/tax-deductions', TaxDeductionsReportController::class);

// Income and costs per project, matched by a custom field.
Route::get('reports/projects', ProjectReportController::class);
