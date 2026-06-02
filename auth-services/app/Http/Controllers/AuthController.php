<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Users;
use Carbon\Carbon;
use App\Models\TempOtp;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function signup(Request $request)
    {
        // Validate input
        $validateUser = Validator::make(
            $request->all(),
            [
                'email' => 'required|email|unique:users,email',
                'country_code' => 'required',
                'country_name' => 'required|string',
                'phone_no' => 'required|unique:users,phone_number',
                'service_type' => 'required|string',
            ]
        );

        if ($validateUser->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validateUser->errors()->all()
            ], 401);
        }

        // Generate a random 128-bit AES key (16 bytes = 128 bits)
        $aesKey = Str::random(16);

        // Create new user if doesn't exist
        $user = Users::create([
            'email' => $request->email,
            'country_code' => $request->country_code,
            'country_name' => $request->country_name,
            'phone_number' => $request->phone_no,
            'aes_key' => $aesKey,
            'business_verified' => 'INCOMPLETE PROFILE',
            'role' => 'BUSINESS_USER', // Add role with default value
            'service_type' => $request->service_type,
            'trial_ends_at' => Carbon::now()->addDays(7), // 7-day trial
            'trial_calls_remaining' => 20,
            'on_trial' => true,
        ]);

        $merchantId = Str::random(14);

        // Insert 2 special characters at random positions
        $positions = array_rand(range(0, 15), 2);

        // Ensure exactly 16 characters
        $merchantId = substr($merchantId, 0, 16);

        $user->update(['merchant_id' => $merchantId]);

        // Update country name and set business_verified if user exists
        $user->update([
            'country_name' => $request->country_name,
            'business_verified' => 'INCOMPLETE PROFILE',
            'role' => 'BUSINESS_USER', // Ensure role is set even for existing users
            'service_type' => $request->service_type
        ]);

        // Refresh user data
        $user->refresh();

        return response()->json([
            'status' => true,
            'message' => $user->wasRecentlyCreated ? 'User Created Successfully.' : 'User details updated.',
            'user' => $user->makeHidden(['aes_key']), // Don't return the key
        ], 200);
    }

    public function login(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string|max:5',
            'login_input' => 'required|string', // Can be email or phone
            'service_type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Determine if input is email or phone
        $isEmail = filter_var($request->login_input, FILTER_VALIDATE_EMAIL);
        $field = $isEmail ? 'email' : 'phone_no';

        // Find user based on provided credentials
        $query = Users::where('country_code', $request->country_code)
            ->where($field, $request->login_input);
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }

        $user = $query->first();

        // Check if user exists
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
                'errors' => ['auth' => 'The provided credentials are incorrect']
            ], 404);
        }

        // Generate JWT token using only merchant_id
        $customClaims = ['merchant_id' => $user->merchant_id];
        $token = auth()->claims($customClaims)->login($user);

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'user_id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'JWT_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user,
        ], 200);
    }
}
