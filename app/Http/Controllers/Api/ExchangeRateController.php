<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ExchangeRateResource;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index(Request $request)
    {
        $query = \App\Models\ExchangeRate::where('is_active', true);

        if ($request->filled('from') && $request->filled('to')) {
            $query->where('currency_from', strtoupper($request->from))
                ->where('currency_to', strtoupper($request->to));
        }

        $exchangeRates = $query->get();

        return response()->json([
            'success' => true,
            'data' => ExchangeRateResource::collection($exchangeRates),
        ]);
    }
}
