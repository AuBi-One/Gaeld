<?php

use App\Domains\ImpactAccounting\Controllers\ImpactAccountingController;
use Illuminate\Support\Facades\Route;

Route::get('/impact-accounting', [ImpactAccountingController::class, 'index'])
    ->name('impact-accounting.index');
Route::post('/impact-accounting/capitals', [ImpactAccountingController::class, 'store'])
    ->name('impact-accounting.capitals.store');
Route::post('/impact-accounting/activities', [ImpactAccountingController::class, 'storeActivity'])
    ->name('impact-accounting.activities.store');
Route::post('/impact-accounting/capitals/{capital}/observations', [ImpactAccountingController::class, 'storeObservation'])
    ->name('impact-accounting.observations.store');
Route::post('/impact-accounting/capitals/{capital}/impacts', [ImpactAccountingController::class, 'storeImpact'])
    ->name('impact-accounting.impacts.store');
Route::post('/impact-accounting/capitals/{capital}/actions', [ImpactAccountingController::class, 'storeAction'])
    ->name('impact-accounting.actions.store');
Route::put('/impact-accounting/observations/{observation}', [ImpactAccountingController::class, 'updateObservation'])
    ->name('impact-accounting.observations.update');
Route::put('/impact-accounting/actions/{action}', [ImpactAccountingController::class, 'updateAction'])
    ->name('impact-accounting.actions.update');
