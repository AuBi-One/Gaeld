<?php

namespace Plugins\Offers\Services;

use App\Domains\Organizations\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugins\Offers\Models\Offer;

/**
 * The offer document: a Blade view (Swiss window-envelope layout) rendered by dompdf.
 */
class OfferPdf
{
    public function render(Offer $offer): string
    {
        // Subsetting keeps the embedded font small; only data: URIs (the logo) may be loaded,
        // so no text of the offer can make dompdf read a local file or a URL.
        return Pdf::loadHTML($this->html($offer))->setPaper('A4', 'portrait')
            ->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false, 'allowedProtocols' => ['data://' => []]])
            ->output();
    }

    public function html(Offer $offer): string
    {
        $offer->loadMissing('lines');
        $organization = Organization::query()->findOrFail($offer->organization_id);
        $lang = in_array($offer->language, ['fr', 'de', 'it', 'en'], true) ? $offer->language : 'fr';
        $recipient = $offer->recipient ?? [];

        $placeholders = array_map(e(...), [
            '{company}' => (string) ($recipient['company'] ?? ''),
            '{attention}' => (string) ($recipient['attention'] ?? ''),
            '{number}' => $offer->number,
            '{date}' => $offer->offer_date->format('d.m.Y'),
            '{valid_until}' => $offer->valid_until?->format('d.m.Y') ?? '',
            '{total}' => $offer->currency.' '.self::money((string) $offer->total),
        ]);

        return view('offers::pdf', [
            'offer' => $offer,
            'organization' => $organization,
            'recipient' => $recipient,
            'logo' => $this->logo($organization),
            'intro' => $this->markdown($offer->intro, $placeholders),
            'closing' => $this->markdown($offer->closing, $placeholders),
            't' => fn (string $key, array $replace = []): string => (string) trans('offers::of.'.$key, $replace, $lang),
            'money' => fn (string $value): string => self::money($value),
        ])->render();
    }

    /** Swiss format: 1'234.50 */
    public static function money(string $value): string
    {
        return number_format((float) $value, 2, '.', "'");
    }

    public static function quantity(string $value): string
    {
        $formatted = number_format((float) $value, 2, '.', "'");

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function markdown(?string $text, array $placeholders): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Placeholders become inert tokens for the markdown pass, then their HTML-escaped
        // values, so a company named "A_B*" is text, not markdown.
        $tokens = [];
        foreach (array_keys($placeholders) as $i => $key) {
            $tokens[$key] = "\u{E000}{$i}\u{E001}";
        }
        $html = Str::markdown(strtr($text, $tokens), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = strtr($html, array_combine(array_values($tokens), array_values($placeholders)));

        return (string) preg_replace('/<img\b[^>]*>/i', '', $html);
    }

    /** The logo as a data URI, so dompdf never reads files or URLs on its own. */
    private function logo(Organization $organization): ?string
    {
        $path = $organization->logo_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('local')->mimeType($path) ?: '';
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('local')->get($path));
    }
}
