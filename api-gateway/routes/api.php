<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GatewayController;

// Public Auth routes (proxied to Auth Service without verification)
Route::post('auth/signup', [GatewayController::class, 'proxyToAuthService']);
Route::post('auth/login', [GatewayController::class, 'proxyToAuthService']);

// Protected routes (require JWT verification)
Route::middleware(['auth.jwt'])->group(function () {
    
    // Gateway-level authenticated user details
    Route::get('auth/user', [GatewayController::class, 'getAuthenticatedUser']);

    // Admin-only business profile decision route
    Route::middleware(['admin'])->post('business-profile/decision', [GatewayController::class, 'proxyToAuthService']);

    // Standard business profile routes
    Route::any('business-profile/{any?}', [GatewayController::class, 'proxyToAuthService'])->where('any', '.*');

    // Subscriptions, Packages, Merchant, Scan, and Card Scan routes (proxied to Subscriptions Service)
    Route::any('Subscriptions/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('Packages/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('merchant/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('scan/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    Route::any('merchantscan/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
    
    Route::any('UpdateCardScan', [GatewayController::class, 'proxyToSubscriptionsService']);
    Route::any('getmerchantscanInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
    Route::any('getmerchantDisplayInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
    Route::any('updateMerchantScanInfo', [GatewayController::class, 'proxyToSubscriptionsService']);
    Route::any('updateMerchantDisplayInfo', [GatewayController::class, 'proxyToSubscriptionsService']);

    // Admin-only Superadmin routes (restricted to SUPER_ADMIN role)
    Route::middleware(['admin'])->group(function () {
        Route::any('superadmin/{any?}', [GatewayController::class, 'proxyToSubscriptionsService'])->where('any', '.*');
        Route::any('internal/{any?}', [GatewayController::class, 'proxyToAuthService'])->where('any', '.*');
    });
});
