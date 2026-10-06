<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\MerchantBlockedIp;

class MerchantBlockedIpController extends Controller
{
    public function index($merchantId)
    {
        $blockedIps = MerchantBlockedIp::where('merchant_id', $merchantId)->get();

        return response()->json([
            'status' => 'success',
            'data' => $blockedIps,
        ]);
    }

    public function store(Request $request, $merchantId)
    {
        $request->validate([
            'ip_address' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $blockedIp = MerchantBlockedIp::firstOrCreate(
            ['merchant_id' => $merchantId, 'ip_address' => $request->ip_address],
            ['reason' => $request->reason]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'IP address blocked successfully',
            'data' => $blockedIp,
        ]);
    }

    public function destroy($merchantId, $ipAddress)
    {
        MerchantBlockedIp::where('merchant_id', $merchantId)
            ->where('ip_address', $ipAddress)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'IP address unblocked successfully',
        ]);
    }
}
