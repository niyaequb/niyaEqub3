<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BannerResource;
use App\Http\Resources\Api\CompanyFactsResource;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    //

    public function index()
    {
        $banners = \App\Models\Banner::where('is_active', true)->get();
        $companyFacts = \App\Models\CompanyFact::where('is_active', true)->get();

        return response()->json([
            'banners' => BannerResource::collection($banners),
            'company_facts' => CompanyFactsResource::collection($companyFacts),
        ]);
    }
}
