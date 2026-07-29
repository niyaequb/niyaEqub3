<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EqubPackageResource;
use App\Models\EqubPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubPackageController extends Controller
{
    /**
     * List active Equb packages (for mobile browse/join).
     */
    public function index(Request $request): JsonResponse
    {
        $query = EqubPackage::query()->with('groups')->where('is_active', true);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $packages = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubPackageResource::collection($packages),
            'meta' => [
                'current_page' => $packages->currentPage(),
                'last_page' => $packages->lastPage(),
                'per_page' => $packages->perPage(),
                'total' => $packages->total(),
            ],
        ]);
    }

    /**
     * Show a single package.
     */
    public function show(EqubPackage $equbPackage): JsonResponse
    {
        if (! $equbPackage->is_active) {
            return response()->json(['status' => 'error', 'message' => 'Package not available.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new EqubPackageResource($equbPackage),
        ]);
    }
}
