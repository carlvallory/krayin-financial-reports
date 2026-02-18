<?php

use Illuminate\Support\Facades\Route;
use CarlVallory\KrayinFinancialReports\Http\Controllers\FinancialReportController;

Route::group(['middleware' => ['web', 'admin_locale', 'user'], 'prefix' => 'admin/financial-reports'], function () {
    Route::get('', [FinancialReportController::class, 'index'])->name('krayin.financial-reports.index');
    Route::get('configure', [FinancialReportController::class, 'configure'])->name('krayin.financial-reports.configure');
    Route::post('configure', [FinancialReportController::class, 'storeConfiguration'])->name('krayin.financial-reports.configure.store');
});
