<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users as User;

class UserWithLocationController extends Controller
{
    public function getUserWithLocations($merchantId)
    {
        $user = User::where('merchant_id', $merchantId)
                    ->with('locations') // eager load locations
                    ->first();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'device_id' => $user->device_id,
                'session_id' => $user->session_id,
                'device' => $user->device,
                'network' => $user->network,
                'sims' => $user->sims,
                'locations' => $user->locations,
            ]
        ]);
    }
}
