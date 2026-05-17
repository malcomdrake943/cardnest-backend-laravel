<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $packages = Package::select('id', 'package_name', 'monthly_limit', 'overage_rate', 'package_price', 'package_period', 'package_description')->get();

        return response()->json([
            'status' => true,
            'message' => 'Packages retrieved successfully',
            'data' => $packages
        ], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        try {
            $package = Package::select(
                'id',
                'package_name',
                'monthly_limit',
                'overage_rate',
                'package_price',
                'package_period',
                'package_description'
            )->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Package retrieved successfully',
                'data' => $package
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve package',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'monthly_limit' => 'sometimes|required|integer|min:0',
            'overage_rate' => 'sometimes|required|numeric|min:0',
            'package_price' => 'nullable|numeric|min:0',
            'package_period' => 'nullable|string|max:50',
            'package_description' => 'nullable|string|max:255',
        ]);

        try {
            $package = Package::findOrFail($id);

            $package->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Package updated successfully',
                'data' => $package
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update package',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

}
