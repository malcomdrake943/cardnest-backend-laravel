<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users as User;

class UserinfoDevince extends Controller
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
}
