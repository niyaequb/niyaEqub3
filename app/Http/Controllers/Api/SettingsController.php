<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ExchangeRateResource;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Tiptap\Editor;

class SettingsController extends Controller
{
    /**
     * Get structured global settings.
     */

    public function index(): JsonResponse
    {
        $settings = GlobalSetting::all()->pluck('value', 'key');

        $editor = new Editor();
        $exchangeRates = \App\Models\ExchangeRate::where('is_active', true)->get();
        // $banners = \App\Models\Banner::where('is_active', true)->get();
        // $companyFacts = \App\Models\CompanyFact::where('is_active', true)->orderBy('order')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'legal' => [
                    'privacy_policy' => $this->renderHtml($settings->get('privacy_policy'), $editor),
                    'terms_conditions' => $this->renderHtml($settings->get('terms_conditions'), $editor),
                ],
                'support' => [
                    'phone' => $settings->get('support_phone'),
                    'email' => $settings->get('support_email'),
                    'website' => $settings->get('support_website'),
                    'whatsapp' => $settings->get('support_whatsapp'),
                    'address' => $settings->get('support_address'),
                ],
                'social' => [
                    'telegram' => $settings->get('social_telegram'),
                    'tiktok' => $settings->get('social_tiktok'),
                    'instagram' => $settings->get('social_instagram'),
                    'youtube' => $settings->get('social_youtube'),
                    'twitter' => $settings->get('social_twitter'),
                    'linkedin' => $settings->get('social_linkedin'),
                ],
                'exchange_rates' => ExchangeRateResource::collection($exchangeRates),
                // 'banners' => $banners,
                // 'company_facts' => $companyFacts,
            ],
        ]);
    }

    private function renderHtml($content, $editor)
    {
        if (!$content) {
            return null;
        }

        $json = json_decode($content, true);

        if (!$json) {
            return $content;
        } // fallback if already HTML

        return $editor->setContent($json)->getHTML();
    }

    // public function index(): JsonResponse
    // {
    //     $settings = GlobalSetting::all()->pluck('value', 'key');

    //     return response()->json([
    //         'status' => 'success',
    //         'data' => [
    //             'legal' => [
    //                 'privacy_policy' => $settings->get('privacy_policy'),
    //                 'terms_conditions' => $settings->get('terms_conditions'),
    //             ],
    //             'support' => [
    //                 'phone' => $settings->get('support_phone'),
    //                 'email' => $settings->get('support_email'),
    //                 'website' => $settings->get('support_website'),
    //                 'whatsapp' => $settings->get('support_whatsapp'),
    //                 'address' => $settings->get('support_address'),
    //             ],
    //             'social' => [
    //                 'telegram' => $settings->get('social_telegram'),
    //                 'tiktok' => $settings->get('social_tiktok'),
    //                 'instagram' => $settings->get('social_instagram'),
    //                 'youtube' => $settings->get('social_youtube'),
    //                 'twitter' => $settings->get('social_twitter'),
    //                 'linkedin' => $settings->get('social_linkedin'),
    //             ],
    //         ]
    //     ]);
    // }
}
