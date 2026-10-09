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
     * The packages in the order of the official list: Al-Sabr, Al-Nur, Iman,
     * Raha, Special Ramadan, Hajj - then anything else, oldest first.
     *
     * The set of packages is fixed, and its stored name - in any of the app's
     * three languages - is the only thing that says which package a row is,
     * so the place is read from the name. The app sorts the same way
     * (Mobile: lib/core/util/package_names.dart); keep the two in step.
     *
     * Hajj is tested first, as in the app, so "የሀጅ" is never taken for
     * another package. This replaces newest-first, which put Hajj at the top
     * of the list.
     */
    private const LIST_ORDER = <<<'SQL'
        CASE
            WHEN name LIKE '%ሀጅ%' OR name LIKE '%ሐጅ%' OR name LIKE '%ሃጅ%' OR name LIKE '%hajj%' THEN 6
            WHEN name LIKE '%ሰብር%' OR name LIKE '%ሶብር%' OR name LIKE '%sabr%' OR name LIKE '%sabir%' THEN 1
            WHEN name LIKE '%ኑር%' OR name LIKE '%nur%' OR name LIKE '%nuur%' OR name LIKE '%noor%' THEN 2
            WHEN name LIKE '%ኢማን%' OR name LIKE '%እማን%' OR name LIKE '%ኤማን%' OR name LIKE '%iman%' OR name LIKE '%eman%' OR name LIKE '%imaana%' THEN 3
            WHEN name LIKE '%ረመዳን%' OR name LIKE '%ረመዷን%' OR name LIKE '%ረማዳን%' OR name LIKE '%ramadan%' OR name LIKE '%ramadhan%' OR name LIKE '%ramadaana%' THEN 5
            WHEN name LIKE '%ረሀ%' OR name LIKE '%ራሀ%' OR name LIKE '%ረሐ%' OR name LIKE '%ራሐ%' OR name LIKE '%ረሃ%' OR name LIKE '%ራሃ%' OR name LIKE '%raha%' THEN 4
            ELSE 7
        END
        SQL;

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

        $packages = $query
            ->orderByRaw(self::LIST_ORDER)
            ->orderBy('id')
            ->paginate($request->input('per_page', 15));

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
