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
                'social' => $this->socialLinks($settings),
                'exchange_rates' => ExchangeRateResource::collection($exchangeRates),
                // 'banners' => $banners,
                // 'company_facts' => $companyFacts,
            ],
        ]);
    }

    /**
     * Social links, always as full URLs that open.
     *
     * Twitter and LinkedIn were replaced by WhatsApp and Facebook. Before
     * that change the WhatsApp/Facebook links had been typed into the
     * Twitter, LinkedIn and YouTube boxes, so those old values are used as a
     * fallback until the new boxes are filled in.
     */
    private function socialLinks($settings): array
    {
        $legacy = array_filter([
            $settings->get('social_twitter'),
            $settings->get('social_linkedin'),
            $settings->get('social_youtube'),
        ]);

        $find = fn (array $needles): ?string => collect($legacy)
            ->first(fn ($url) => \Illuminate\Support\Str::contains(strtolower((string) $url), $needles));

        $whatsapp = $settings->get('social_whatsapp') ?: $find(['whatsapp', 'wa.me']);
        $facebook = $settings->get('social_facebook') ?: $find(['facebook.com', 'fb.com', 'fb.me']);

        $youtube = $settings->get('social_youtube');
        if ($youtube && ! \Illuminate\Support\Str::contains(strtolower($youtube), ['youtube', 'youtu.be'])) {
            $youtube = null; // a non-YouTube link was saved in the YouTube box
        }

        return [
            'telegram' => $this->normalizeUrl($settings->get('social_telegram'), 'https://t.me/'),
            'tiktok' => $this->normalizeUrl($settings->get('social_tiktok'), 'https://www.tiktok.com/@'),
            'instagram' => $this->normalizeUrl($settings->get('social_instagram'), 'https://www.instagram.com/'),
            'youtube' => $this->normalizeUrl($youtube, 'https://www.youtube.com/@'),
            'whatsapp' => $this->whatsappUrl($whatsapp),
            'facebook' => $this->normalizeUrl($facebook, 'https://www.facebook.com/'),
        ];
    }

    /** Make sure a link has https:// (or turn a bare handle into a link). */
    private function normalizeUrl(?string $value, string $handlePrefix): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        if (str_contains($value, '.') && ! str_starts_with($value, '@')) {
            return 'https://'.ltrim($value, '/');
        }

        return $handlePrefix.ltrim($value, '@/');
    }

    /** A WhatsApp link from a full URL or a bare phone number. */
    private function whatsappUrl(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value) || str_contains($value, '.')) {
            return $this->normalizeUrl($value, 'https://wa.me/');
        }

        $digits = ltrim(preg_replace('/\D/', '', $value), '0');
        if ($digits !== '' && ! str_starts_with($digits, '251')) {
            $digits = '251'.$digits;
        }

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
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
