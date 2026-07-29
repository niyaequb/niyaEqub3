<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\Request;

class LegalController extends Controller
{
    protected $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function privacy()
    {
        $privacyPolicy = $this->settingsService->get('privacy_policy', 'Privacy Policy coming soon...');
        
        return view('legal.privacy', [
            'content' => $privacyPolicy,
            'title' => 'Privacy Policy'
        ]);
    }

    public function terms()
    {
        $termsConditions = $this->settingsService->get('terms_conditions', 'Terms & Conditions coming soon...');
        
        return view('legal.terms', [
            'content' => $termsConditions,
            'title' => 'Terms & Conditions'
        ]);
    }
}
