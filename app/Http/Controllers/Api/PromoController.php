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
            // Seconds each banner stays on screen. Set from the admin panel
            // (Banners -> Slide duration); the apps fall back to 5 if absent.
            'banner_slide_duration' => \App\Models\Banner::slideDuration(),
            'banners' => BannerResource::collection($banners),
            'company_facts' => CompanyFactsResource::collection($companyFacts),
        ]);
    }
}
