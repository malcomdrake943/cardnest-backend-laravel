<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GatewayController;

// Public Auth routes (proxied to Auth Service without verification)
Route::post('auth/signup', [GatewayController::class, 'proxyToAuthService']);
Route::post('auth/login', [GatewayController::class, 'proxyToAuthService']);

//Features routes
Route::any('feature/get', [GatewayController::class, 'proxyToSubscriptionsService']);

//Packages routes
Route::get('Packages', [GatewayController::class, 'proxyToSubscriptionsService']);

Route::post('device-info', [GatewayController::class, 'proxyToAuthService']);

Route::get('getmerchantDisplayInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::any('merchantscan/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
Route::any('UpdateCardScan', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::post('scan/token', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::post('scan/submitEncryptedData', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::post('scan/getEncryptedData', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::any('getmerchantscanInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
Route::any('updateMerchantScanInfo', [GatewayController::class, 'proxyToSubscriptionsService']);


// Protected routes (require JWT verification)
Route::middleware(['auth.jwt'])->group(function () {

    // Gateway-level authenticated user details
    Route::get('auth/user', [GatewayController::class, 'getAuthenticatedUser']);

    Route::get('device/merchant/{merchantId}', [GatewayController::class, 'proxyToAuthService']);
    Route::post('user-device-info', [GatewayController::class, 'proxyToAuthService']);
    Route::post('merchant-location', [GatewayController::class, 'proxyToAuthService']);
    Route::get('user/{merchantId}/details', [GatewayController::class, 'proxyToAuthService']);
    Route::get('location/merchant/{merchantId}', [GatewayController::class, 'proxyToAuthService']);

    Route::any('updateMerchantDisplayInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
    Route::any('getDocumentation', [GatewayController::class, 'proxyToSubscriptionsService']);

    // Admin-only business profile decision route
    Route::middleware(['admin'])->post('business-profile/decision', [GatewayController::class, 'proxyToAuthService']);

    Route::any('feature/store', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::post('scan/storeFeature', [GatewayController::class, 'proxyToSubscriptionsService']);

    // Standard business profile routes
    Route::any('business-profile/{any?}', [GatewayController::class, 'proxyToAuthService'])->where('any', '.*');

    // Device and Location routes (proxied to Auth Service)
    Route::get('devices', [GatewayController::class, 'proxyToAuthService']);
    Route::get('locations', [GatewayController::class, 'proxyToAuthService']);

    // Subscriptions, Packages, Merchant, Scan, and Card Scan routes (proxied to Subscriptions Service)
    Route::any('Subscriptions/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('Packages/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('merchant/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('scan/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');


    // Admin-only Superadmin routes (restricted to SUPER_ADMIN role)
    Route::middleware(['admin'])->group(function () {
        Route::any('superadmin/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
        Route::any('internal/{any?}', [GatewayController::class, 'proxyToAuthService'])->where('any', '.*');
    });
});
