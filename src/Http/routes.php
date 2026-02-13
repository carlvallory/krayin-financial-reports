<?php

use Illuminate\Support\Facades\Route;
use CarlVallory\KrayinFinancialReports\Http\Controllers\FinancialReportController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/financial-reports'], function () {
    Route::get('/', [FinancialReportController::class, 'index'])->name('admin.financial_reports.index');
});
