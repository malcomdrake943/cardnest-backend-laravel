<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users as User;

class UserDeviceInfoController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'DeviceId' => 'required|string',
            'merchantId' => 'required|string',
            'sessionId' => 'nullable|string',
            'device' => 'required|array',
            'device.bootCount' => 'nullable|integer',
            'device.brand' => 'nullable|string',
            'device.buildFingerprint' => 'nullable|string',
            'device.buildId' => 'nullable|string',
            'device.device' => 'nullable|string',
            'device.manufacturer' => 'nullable|string',
            'device.model' => 'nullable|string',
            'device.product' => 'nullable|string',
            'device.release' => 'nullable|string',
            'device.sdkInt' => 'nullable|integer',
            'device.securityPatch' => 'nullable|string',

            'network' => 'required|array',
            'network.activeTransports' => 'nullable|array',
            'network.bandwidthKbpsDown' => 'nullable|integer',
            'network.bandwidthKbpsUp' => 'nullable|integer',
            'network.dns' => 'nullable|array',
            'network.hasInternet' => 'nullable|boolean',
            'network.ipv4' => 'nullable|array',
            'network.ipv6' => 'nullable|array',
            'network.isMetered' => 'nullable|boolean',
            'network.isValidated' => 'nullable|boolean',
            'network.wifi.linkSpeedMbps' => 'nullable|integer',
            'network.wifi.rssi' => 'nullable|integer',

            'sims' => 'required|array',
            'sims.*.carrierId' => 'nullable|integer',
            'sims.*.mccmmc' => 'nullable|string',
            'sims.*.sim' => 'nullable|string',
            'sims.*.simType' => 'nullable|string',
            'sims.*.subscriptionId' => 'nullable|integer',
        ]);

        $user = User::where('merchant_id', $data['merchantId'])->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $device = $data['device'];

        $deviceInfo = [
            'bootCount' => $device['bootCount'] ?? null,
            'brand' => $device['brand'] ?? null,
            'buildFingerprint' => $device['buildFingerprint'] ?? null,
            'buildId' => $device['buildId'] ?? null,
            'device' => $device['device'] ?? null,
            'manufacturer' => $device['manufacturer'] ?? null,
            'model' => $device['model'] ?? null,
            'product' => $device['product'] ?? null,
            'release' => $device['release'] ?? null,
            'sdkInt' => $device['sdkInt'] ?? null,
            'securityPatch' => $device['securityPatch'] ?? null,
        ];

        $user->update([
            'device_id' => $data['DeviceId'],
            'session_id' => $data['sessionId'],
            'device_timestamp' => null,
            'location' => null,
            'device' => $deviceInfo,
            'network' => $data['network'],
            'sims' => $data['sims'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Device info stored successfully',
        ]);
    }

    public function getByMerchant(Request $request, $merchantId)
    {
        // 1️⃣ Validation
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:0|max:12', // 0 = ignore
            'date' => 'required|integer|min:0|max:31',  // 0 = ignore
            'per_page' => 'nullable|integer',
        ]);

        $perPage = $request->get('per_page', 10);

        // 2️⃣ Users filtered by merchant + date
        $query = User::query()
            ->where('merchant_id', $merchantId)
            ->whereYear('updated_at', $request->year)

            ->when(
                $request->month > 0,
                fn($q) =>
                $q->whereMonth('updated_at', $request->month)
            )
            ->when(
                $request->date > 0,
                fn($q) =>
                $q->whereDay('updated_at', $request->date)
            )

            ->select(
                'id',
                'merchant_id',
                'role',
                'device_id',
                'session_id',
                'device_timestamp',
                'device',
                'network',
                'sims',
                'created_at',
                'updated_at'
            )

            // 3️⃣ FETCH ALL LOCATIONS BY device_id + SAME DATE
            ->with([
                'locations' => function ($q) use ($request) {

                    $q->whereYear('created_at', $request->year)

                        ->when(
                            $request->month > 0,
                            fn($q2) =>
                            $q2->whereMonth('created_at', $request->month)
                        )

                        ->when(
                            $request->date > 0,
                            fn($q2) =>
                            $q2->whereDay('created_at', $request->date)
                        )

                        // CRITICAL: do NOT limit
                        ->orderBy('created_at', 'asc')

                        ->select(
                            'id',
                            'user_id', // Select user_id for relationship linking
                            'device_id',
                            'address',
                            'lat',
                            'lon',
                            'created_at'
                        );
                }
            ]);

        // 4️⃣ Pagination
        $paginator = $query->paginate($perPage);

        // 5️⃣ Response formatting
        $data = $paginator->getCollection()->map(function ($user) {

            $network = is_array($user->network) ? $user->network : [];

            return [
                'merchant_id' => $user->merchant_id,
                'role' => $user->role,
                'device_id' => $user->device_id,
                'session_id' => $user->session_id,
                'device_timestamp' => $user->device_timestamp,
                'device' => $user->device,
                'sims' => $user->sims,
                'network' => [
                    'dns' => $network['dns'] ?? [],
                    'ipv4' => $network['ipv4'] ?? [],
                    'ipv6' => $network['ipv6'] ?? [],
                ],

                // ✅ ALL locations for device_id + date
                'locations' => $user->locations->map(function ($loc) {
                    return [
                        'address' => $loc->address,
                        'lat' => $loc->lat,
                        'lon' => $loc->lon,
                        'created_at' => $loc->created_at,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'filters' => [
                'merchant_id' => $merchantId,
                'year' => $request->year,
                'month' => $request->month,
                'date' => $request->date,
            ],
            'count' => $paginator->total(),
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ]
        ]);
    }

    public function getAllDevices(Request $request)
    {
        // Validate the input parameters
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:0|max:12', // 0 = ignore
            'date' => 'required|integer|min:0|max:31',  // 0 = ignore
        ]);

        // Fetch all users and their associated locations based on filters
        $users = User::query()
            ->select(
                'id',
                'merchant_id',
                'role',
                'device_id',
                'session_id',
                'device_timestamp',
                'device',
                'network',
                'sims',
                'created_at',
                'updated_at'
            )
            ->with([
                'businessProfile:id,user_id,business_name', // Eager load business profile

                // Filter locations based on date parameters
                'locations' => function ($q) use ($request) {
                    $q->whereYear('created_at', $request->year) // Filter by year

                        // Filter by month if month > 0
                        ->when($request->month > 0, fn($q2) => $q2->whereMonth('created_at', $request->month))

                        // Filter by day if date > 0
                        ->when($request->date > 0, fn($q2) => $q2->whereDay('created_at', $request->date))

                        ->orderBy('created_at', 'asc') // Order by created_at

                        // Select relevant fields from the locations
                        ->select('id', 'user_id', 'device_id', 'address', 'lat', 'lon', 'created_at');
                }
            ])
            ->get();

        // Format the response with the required structure
        $data = $users->map(function ($user) {

            // Handle network data and ensure it's in an array
            $network = is_array($user->network) ? $user->network : [];

            // Return user data along with their filtered locations
            return [
                'merchant_id' => $user->merchant_id,
                'business_name' => optional($user->businessProfile)->business_name,
                'device_id' => $user->device_id,
                'session_id' => $user->session_id,
                'device_timestamp' => $user->device_timestamp,
                'device' => $user->device,
                'sims' => $user->sims,
                'network' => [
                    'dns' => $network['dns'] ?? [],
                    'ipv4' => $network['ipv4'] ?? [],
                    'ipv6' => $network['ipv6'] ?? [],
                ],

                // Return the filtered locations
                'locations' => $user->locations->map(function ($loc) {
                    return [
                        'address' => $loc->address,
                        'lat' => $loc->lat,
                        'lon' => $loc->lon,
                        'created_at' => $loc->created_at,
                    ];
                }),

                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];
        })->toArray();

        // Return the final response
        return response()->json([
            'status' => 'success',
            'filters' => [
                'year' => $request->year,
                'month' => $request->month,
                'date' => $request->date,
            ],
            'count' => count($data),
            'data' => $data,
        ]);
    }
}
