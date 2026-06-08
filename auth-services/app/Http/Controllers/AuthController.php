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
            'trial_ends_at' => null,
            'trial_calls_remaining' => null,
            'on_trial' => false,
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

        $customClaims = ['merchant_id' => $user->merchant_id];
        $token = auth('api')->claims($customClaims)->login($user);

        return response()->json([
            'status' => true,
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'JWT_token' => $token,
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
        $field = $isEmail ? 'email' : 'phone_number';

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
        $token = auth('api')->claims($customClaims)->login($user);

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'user_id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'JWT_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ], 200);
    }

    public function checkUserExists(Request $request)
    {
        // Validate the request
        $request->validate([
            'email' => 'required_without:phone_no|nullable|email',
            'phone_no' => 'required_without:email|nullable|string',
        ]);

        $email = $request->input('email');
        $phone = $request->input('phone_no');

        $exists = false;
        $field = null;
        $message = null;

        // Check if both email and phone are provided
        if ($email && $phone) {
            $userByEmail = Users::where('email', $email)->first();
            $userByPhone = Users::where('phone_number', $phone)->first();

            if ($userByEmail && $userByPhone) {
                return response()->json([
                    'exists' => true,
                    'field' => 'both',
                    'message' => 'User already exists with this email and phone number'
                ]);
            } elseif ($userByEmail) {
                return response()->json([
                    'exists' => true,
                    'field' => 'email',
                    'message' => 'User already exists with this email'
                ]);
            } elseif ($userByPhone) {
                return response()->json([
                    'exists' => true,
                    'field' => 'phone_no',
                    'message' => 'User already exists with this phone number'
                ]);
            }
        } elseif ($email) {
            $user = Users::where('email', $email)->first();
            if ($user) {
                return response()->json([
                    'exists' => true,
                    'field' => 'email',
                    'message' => 'User already exists with this email'
                ]);
            }
        } elseif ($phone) {
            $user = Users::where('phone_number', $phone)->first();
            if ($user) {
                return response()->json([
                    'exists' => true,
                    'field' => 'phone_no',
                    'message' => 'User already exists with this phone number'
                ]);
            }
        }

        return response()->json([
            'exists' => false,
            'message' => 'No user found with these credentials'
        ]);
    }
}
