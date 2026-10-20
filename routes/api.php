<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\InvoiceController;

Route::middleware('auth:sanctum')
->prefix('v1')
->group(function () {

    Route::get('/companies', [CompanyController::class, 'index']);

    Route::middleware('company')
    ->scopeBindings()
    ->prefix('/companies/{company}')
    ->group(function () {
        Route::get('/',[CompanyController::class, 'show']);
        Route::apiResource('clients', ClientController::class)->except(['destroy',]);
        Route::apiResource('products',ProductController::class)->except(['destroy',]);
        Route::apiResource('invoices',InvoiceController::class)->only(['index','store','show',]);
        Route::post('invoices/{invoice}/ready',[InvoiceController::class, 'ready']);
    });
});
