<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\FeatureController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//PACKAGES ENDPOINTS
Route::get('/Packages', [PackageController::class, 'index']);
Route::post('/Packages/Update/{id}', [PackageController::class, 'update'])->where('id', '[0-9]+');
Route::get('/Packages/Show/{id}', [PackageController::class, 'show']);

Route::get('/feature/get', [FeatureController::class, 'getFeatures']);

Route::post('/merchantscan/generateToken', [ScanController::class, 'generateToken']);
Route::post('/UpdateCardScan', [ScanController::class, 'UpdateCardScan']);
Route::post('/scan/submitEncryptedData', [ScanController::class, 'submitEncryptedData']);
Route::post('/scan/token', [ScanController::class, 'createScanToken']);
Route::get('/getmerchantscanInfo', [MerchantController::class, 'getmerchantscanInfo']);
Route::get('/getmerchantDisplayInfo', [MerchantController::class, 'getmerchantDisplayInfo']);
Route::post('/updateMerchantScanInfo', [MerchantController::class, 'updateMerchantScanInfo']);


//SUBSCRIPTION HANDLING ENDPOINTS
Route::middleware(['verify.user'])->group(function () {
    //FEATURE ENDPOINTS
    Route::post('/scan/storeFeature', [FeatureController::class, 'storeFeature']);
    Route::post('/feature/store', [FeatureController::class, 'store']);

    //SUBSCRIPTIONS ENDPOINT
    Route::get('/Subscriptions/GetByUserIDorMerchantID', [SubscriptionController::class, 'GetByUserIDorMerchantID']);
    Route::post('/Subscriptions/customPackagePricing', [SubscriptionController::class, 'customPackagePricing']);
    Route::post('/Subscriptions/setup-renewal', [SubscriptionController::class, 'setupRenewal']);
    Route::get('/merchant/getOldSubscriptions', [SubscriptionController::class, 'getOldSubscriptions']);

    // MERCHANT ENDPOINTS 
    Route::post('/updateMerchantDisplayInfo', [MerchantController::class, 'updateMerchantDisplayInfo']);
    Route::get('/getDocumentation', [SuperAdminController::class, 'getDocumentation']);


    //SCAN ENDPOINTS 
    Route::post('/scan/getEncryptedData', [ScanController::class, 'getEncryptedData']);
    Route::post('/scan/decodeToken', [ScanController::class, 'decodeToken']);
    Route::get('/merchant/getCardScans', [ScanController::class, 'getCardScans']);


    //SUPER ADMIN ENDPOINTS
    Route::post('/superadmin/uploadDocumentation', [SuperAdminController::class, 'uploadDocumentation']);
    Route::post('/superadmin/grant-subadmin-access', [SuperAdminController::class, 'grantSubadminAccess']);
    Route::get('/superadmin/access-all-scans', [SuperAdminController::class, 'accessAllScans']);  //list of merchants(merchant_id, business_name, total scans, success_rate(card_scan status), number_of_merchants)
    Route::get('/superadmin/access-all-scans/{id?}', [SuperAdminController::class, 'scanDetail']); //jiski id uske scans, encrypted_data + encrypted_key, on card_scans table based on status, total number of success and failure 
    Route::get('/superadmin/access-all-old-subscriptions', [SuperAdminController::class, 'accessAllOldSubscriptions']);
    Route::get('/superadmin/getDocumentation', [SuperAdminController::class, 'getSuperAdminDocumentation']);
    Route::post('/superadmin/store', [SuperAdminController::class, 'store']);
    Route::get('/superadmin/show', [SuperAdminController::class, 'show']);
    Route::post('/superadmin/sub-business-store', [SuperAdminController::class, 'sub_business_store']);
    Route::get('/superadmin/get-sub-business', [SuperAdminController::class, 'get_sub_businesses']);
    Route::get('/superadmin/get-enterprise-sub-business', [SuperAdminController::class, 'getEnterpriseUsersWithSubBusinesses']);
});
