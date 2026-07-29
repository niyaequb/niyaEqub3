<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEqubPackageRequest;
use App\Http\Requests\Admin\UpdateEqubPackageRequest;
use App\Http\Resources\Api\EqubPackageResource;
use App\Models\EqubPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EqubPackage::query();
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        $packages = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubPackageResource::collection($packages),
            'meta' => ['current_page' => $packages->currentPage(), 'last_page' => $packages->lastPage(), 'per_page' => $packages->perPage(), 'total' => $packages->total()],
        ]);
    }

    public function show(EqubPackage $equbPackage): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => new EqubPackageResource($equbPackage)]);
    }

    public function store(StoreEqubPackageRequest $request): JsonResponse
    {
        $package = EqubPackage::create($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Equb package created successfully.',
            'data' => new EqubPackageResource($package),
        ], 201);
    }

    public function update(UpdateEqubPackageRequest $request, EqubPackage $equbPackage): JsonResponse
    {
        $equbPackage->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Equb package updated successfully.',
            'data' => new EqubPackageResource($equbPackage->fresh()),
        ]);
    }

    public function destroy(EqubPackage $equbPackage): JsonResponse
    {
        $equbPackage->delete();

        return response()->json(['status' => 'success', 'message' => 'Equb package deleted successfully.']);
    }
}
