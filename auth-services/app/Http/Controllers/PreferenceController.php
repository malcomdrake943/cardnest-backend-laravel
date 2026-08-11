<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users as User;

class PreferenceController extends Controller
{
    public function saveScreenDetection(Request $request)
    {
        $request->validate([
            'screen_detection' => 'required|boolean',
        ]);

        // When the request is proxied via the Gateway, it sets X-User-ID header
        $userId = $request->header('X-User-ID');
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized or missing User ID.',
            ], 401);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $user->update([
            'screen_detection' => $request->boolean('screen_detection'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Screen detection preference updated successfully.',
        ]);
    }
}
