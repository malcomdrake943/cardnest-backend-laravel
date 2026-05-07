<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessProfileController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//LOGIN AND OTP ROUTES
Route::post('signup', [AuthController::class, 'signup']);
Route::post('login', [AuthController::class, 'login']);

//BUSINESS PROFILE ROUTES
Route::prefix('business-profile')->group(function () {
    Route::post('/', [BusinessProfileController::class, 'store']);
    Route::get('/', [BusinessProfileController::class, 'index']); // List only profile which are card_scan
    Route::get('/approved', [BusinessProfileController::class, 'business_verified_approved']); // List documents for Approved Business
    Route::post('/decision', [BusinessProfileController::class, 'updateStatus']); // Approve/Reject
    Route::get('/business-verification-status', [BusinessProfileController::class, 'getStatus']);
});