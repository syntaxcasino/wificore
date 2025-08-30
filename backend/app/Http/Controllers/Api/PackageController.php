<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Package;
use Illuminate\Support\Facades\Validator;

class PackageController extends Controller
{
    public function index()
    {
        return Package::all();
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'duration' => 'required|string|max:50',
            'upload_speed' => 'required|string|max:50',
            'download_speed' => 'required|string|max:50',
            'price' => 'required|numeric|min:0',
            'devices' => 'required|integer|min:1',
            'enable_burst' => 'boolean',
            'enable_schedule' => 'boolean',
            'hide_from_client' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $package = Package::create([
            'type' => $request->type,
            'name' => $request->name,
            'duration' => $request->duration,
            'upload_speed' => $request->upload_speed,
            'download_speed' => $request->download_speed,
            'price' => $request->price,
            'devices' => $request->devices,
            'enable_burst' => $request->enable_burst ?? false,
            'enable_schedule' => $request->enable_schedule ?? false,
            'hide_from_client' => $request->hide_from_client ?? false,
        ]);

        return response()->json($package, 201);
    }

    public function update(Request $request, $id)
    {
        $package = Package::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'duration' => 'required|string|max:50',
            'upload_speed' => 'required|string|max:50',
            'download_speed' => 'required|string|max:50',
            'price' => 'required|numeric|min:0',
            'devices' => 'required|integer|min:1',
            'enable_burst' => 'boolean',
            'enable_schedule' => 'boolean',
            'hide_from_client' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $package->update([
            'type' => $request->type,
            'name' => $request->name,
            'duration' => $request->duration,
            'upload_speed' => $request->upload_speed,
            'download_speed' => $request->download_speed,
            'price' => $request->price,
            'devices' => $request->devices,
            'enable_burst' => $request->enable_burst ?? false,
            'enable_schedule' => $request->enable_schedule ?? false,
            'hide_from_client' => $request->hide_from_client ?? false,
        ]);

        return response()->json($package, 200);
    }
}