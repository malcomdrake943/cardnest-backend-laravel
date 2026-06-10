<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users as User;
use App\Models\Location;

class MerchantLocationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'merchantId' => 'required|string',
            'lat' => 'required|numeric',
            'lon' => 'required|numeric',
            'address' => 'nullable|string',
        ]);

        $user = User::where('merchant_id', $data['merchantId'])->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $location = Location::create([
            'user_id' => $user->id,
            'merchant_id' => $user->merchant_id,
            'lat' => $data['lat'],
            'lon' => $data['lon'],
            'address' => $data['address'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant location stored successfully',
            'data' => $location
        ]);
    }

    public function getByMerchant(Request $request, $merchantId)
    {
        // Validate the input parameters
        $request->validate([
            'year'  => 'required|integer',
            'month' => 'required|integer|min:0|max:12', // 0 = ignore
            'date'  => 'required|integer|min:0|max:31', // 0 = ignore
        ]);

        // Fetch user based on merchant_id
        $user = User::where('merchant_id', $merchantId)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Load locations with filters
        $user->load(['locations' => function ($query) use ($request) {
            $query->whereYear('updated_at', $request->year);

            if ($request->month > 0) {
                $query->whereMonth('updated_at', $request->month);
            }

            if ($request->date > 0) {
                $query->whereDay('updated_at', $request->date);
            }

            $query->whereColumn('merchant_id', 'users.merchant_id');

            $query->orderBy('updated_at', 'asc')->select([
                'id', 'merchant_id', 'address', 'lat', 'lon', 'created_at', 'updated_at',
            ]);
        }]);

        $locations = $user->locations->map(function ($loc) {
            return [
                'id' => $loc->id,
                'address' => $loc->address,
                'lat' => $loc->lat,
                'lon' => $loc->lon,
                'created_at' => $loc->created_at,
                'updated_at' => $loc->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'filters' => [
                'merchant_id' => $merchantId,
                'year'  => $request->year,
                'month' => $request->month,
                'date'  => $request->date,
            ],
            'count' => $locations->count(),
            'data'  => [
                'user_id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'locations' => $locations,
            ]
        ]);
    }

    public function getAll(Request $request)
    {
        // Validate the input parameters
        $request->validate([
            'year'  => 'required|integer',
            'month' => 'required|integer|min:0|max:12',  // month 0 is valid for "just year"
            'date'  => 'required|integer|min:0|max:31',  // date 0 is valid for "year + month"
        ]);

        // Start by fetching all users based on 'merchant_id'
        $users = User::select('id', 'merchant_id', 'created_at')->get();

        // Eager load locations with filtering based on the passed year, month, and date
        $users->load(['locations' => function ($query) use ($request) {
            $query->whereYear('updated_at', $request->year);

            // Check if we need to filter by month
            if ($request->month > 0) {
                $query->whereMonth('updated_at', $request->month);
            }

            // Check if we need to filter by date
            if ($request->date > 0) {
                $query->whereDay('updated_at', $request->date);
            }

            // Make sure to filter locations by merchant_id, based on the relationship
            $query->whereColumn('merchant_id', 'users.merchant_id');

            // Ordering locations by the updated_at field
            $query->orderBy('updated_at', 'asc')->select([
                'id', 'merchant_id', 'address', 'lat', 'lon', 'created_at', 'updated_at',
            ]);
        }]);

        // Map and transform the data structure
        $data = $users->transform(function ($user) {
            return [
                'user_id' => $user->id,
                'merchant_id' => $user->merchant_id,
                'locations' => $user->locations->transform(function ($loc) {
                    return [
                        'id' => $loc->id,
                        'address' => $loc->address,
                        'lat' => $loc->lat,
                        'lon' => $loc->lon,
                        'created_at' => $loc->created_at,
                        'updated_at' => $loc->updated_at,
                    ];
                }),
            ];
        });

        // Return the response with success, count, and the data
        return response()->json([
            'success' => true,
            'filters' => [
                'year'  => $request->year,
                'month' => $request->month,
                'date'  => $request->date,
            ],
            'count' => $data->count(),
            'data'  => $data,
        ]);
    }
}
