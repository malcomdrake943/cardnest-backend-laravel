<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\InternalController;
use App\Http\Controllers\UserDeviceInfoController;
use App\Http\Controllers\UserinfoDevince;
use App\Http\Controllers\MerchantLocationController;
use App\Http\Controllers\UserWithLocationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// DEVICE AND LOCATION ROUTES
Route::post('/device-info', [UserDeviceInfoController::class, 'store']);
Route::post('/user-device-info', [UserinfoDevince::class, 'store']);
Route::post('/merchant-location', [MerchantLocationController::class, 'store']);
Route::get('/user/{merchantId}/details', [UserWithLocationController::class, 'getUserWithLocations']);
Route::get('/device/merchant/{merchantId}', [UserDeviceInfoController::class, 'getByMerchant']);
Route::get('/devices', [UserDeviceInfoController::class, 'getAllDevices']);
Route::get('/location/merchant/{merchantId}', [MerchantLocationController::class, 'getByMerchant']);
Route::get('/locations', [MerchantLocationController::class, 'getAll']);

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



use App\Http\Controllers\PreferenceController;

// PREFERENCES ROUTES
Route::post('preferences/screen-detection', [PreferenceController::class, 'saveScreenDetection']);

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
