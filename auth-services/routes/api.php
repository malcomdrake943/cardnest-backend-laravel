<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\InternalController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//LOGIN AND OTP ROUTES
Route::post('signup', [AuthController::class, 'signup']);
Route::post('login', [AuthController::class, 'login']);
Route::post('check-user-exists', [AuthController::class, 'checkUserExists']);

//BUSINESS PROFILE ROUTES
Route::prefix('business-profile')->group(function () {
    Route::post('/', [BusinessProfileController::class, 'store']);
    Route::get('/', [BusinessProfileController::class, 'index']); // List only profile which are card_scan
    Route::get('/approved', [BusinessProfileController::class, 'business_verified_approved']); // List documents for Approved Business
    Route::post('/decision', [BusinessProfileController::class, 'updateStatus']); // Approve/Reject
    Route::get('/business-verification-status', [BusinessProfileController::class, 'getStatus']);
});

// INTERNAL SERVICE ROUTES
Route::get('/internal/verify-user', [InternalController::class, 'verifyUser']);
Route::post('/internal/update-profile', [InternalController::class, 'updateProfile']);
Route::post('/internal/update-role', [InternalController::class, 'updateUserRole']);
Route::get('/internal/business-names', [InternalController::class, 'getBusinessNames']);
Route::post('/internal/superadmin/store-business-profile', [InternalController::class, 'storeBusinessProfile']);
Route::get('/internal/superadmin/show-business-profile', [InternalController::class, 'showBusinessProfile']);
Route::post('/internal/superadmin/sub-business-store', [InternalController::class, 'storeSubBusinesses']);
Route::get('/internal/superadmin/get-sub-businesses', [InternalController::class, 'getSubBusinesses']);
Route::get('/internal/superadmin/get-enterprise-users', [InternalController::class, 'getEnterpriseUsersWithSubBusinesses']);
