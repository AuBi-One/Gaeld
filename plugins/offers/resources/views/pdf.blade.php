@php
    /** @var \Plugins\Offers\Models\Offer $offer */
    $orgAddress = array_filter([
        $organization->address,
        trim(($organization->postal_code ?? '').' '.($organization->city ?? '')),
    ]);
    $senderLines = array_filter([
        ...($layout['from']['address'] ? $orgAddress : []),
        $layout['from']['email'] ? $settings->sender_email : null,
        $layout['from']['phone'] ? $settings->sender_phone : null,
    ]);
    $recipientLines = array_filter([
        ($recipient['attention'] ?? null) ? $t('pdf_attention', ['name' => $recipient['attention']]) : null,
        ...($layout['to']['address'] ? [
            $recipient['address'] ?? null,
            trim(($recipient['postal_code'] ?? '').' '.($recipient['city'] ?? '')),
            ($recipient['country'] ?? 'CH') !== 'CH' ? ($recipient['country'] ?? null) : null,
        ] : []),
        $layout['to']['email'] ? ($recipient['email'] ?? null) : null,
        $layout['to']['phone'] ? ($recipient['phone'] ?? null) : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $offer->language }}">
<head>
<meta charset="utf-8">
<title>{{ $t('pdf_offer') }} {{ $offer->number }}</title>
<style>
    @page { margin: 15mm 15mm 22mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1a1a1a; line-height: 1.35; }
    .header { position: relative; height: 82mm; }
    .sender { position: absolute; top: 0; left: 0; width: 85mm; font-size: 8pt; color: #505050; }
    .sender .name { font-size: 10pt; font-weight: bold; color: #1a1a1a; }
    .logo { max-width: 28mm; max-height: 18mm; margin-bottom: 3mm; }
    .recipient { position: absolute; top: 35mm; left: 100mm; width: 75mm; font-size: 9.5pt; }
    .recipient .company { font-weight: bold; }
    h1 { font-size: 15pt; color: #1f4c35; margin: 0 0 2mm 0; }
    .meta { color: #646464; font-size: 8.5pt; margin-bottom: 5mm; }
    .meta span { margin-right: 6mm; }
    .subject { font-weight: bold; font-size: 10pt; margin: 0 0 3mm 0; }
    .text p { margin: 0 0 2.5mm 0; }
    .text ul, .text ol { margin: 0 0 2.5mm 0; padding-left: 6mm; }
    table.items { width: 100%; border-collapse: collapse; margin: 4mm 0 2mm 0; }
    table.items th { background: #f5f5f5; font-size: 8pt; text-align: left; padding: 1.6mm 1.5mm; }
    table.items td { padding: 1.6mm 1.5mm; vertical-align: top; border-bottom: 0.2mm solid #e3e8e5; }
    table.items tr.text td { font-style: italic; color: #404040; }
    .num { text-align: right; white-space: nowrap; }
    table.totals { width: 75mm; margin-left: auto; border-collapse: collapse; margin-bottom: 6mm; }
    table.totals td { padding: 1mm 1.5mm; }
    table.totals tr.grand td { font-weight: bold; font-size: 10.5pt; color: #1f4c35; border-top: 0.3mm solid #1f4c35; padding-top: 1.8mm; }
    .validity { margin-top: 4mm; color: #505050; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #96a099; border-top: 0.2mm solid #d3dcd6; padding-top: 1.5mm; }
</style>
</head>
<body>
<div class="footer">
    {{ $organization->legal_name ?? $organization->name }}@if($orgAddress) · {{ implode(' · ', $orgAddress) }}@endif @if($organization->vat_number) · {{ $t('pdf_vat_number') }} {{ $organization->vat_number }}@endif
</div>

<div class="header">
    <div class="sender">
        @if($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
        <div class="name">{{ $organization->legal_name ?? $organization->name }}</div>
        @foreach($senderLines as $line)<div>{{ $line }}</div>@endforeach
    </div>
    <div class="recipient">
        <div class="company">{{ $recipient['company'] ?? '' }}</div>
        @foreach($recipientLines as $line)<div>{{ $line }}</div>@endforeach
    </div>
</div>

<h1>{{ $t('pdf_offer') }} {{ $offer->number }}</h1>
<div class="meta">
    <span>{{ $t('pdf_date') }}: {{ $offer->offer_date->format('d.m.Y') }}</span>
    @if($offer->valid_until)<span>{{ $t('pdf_valid_until') }}: {{ $offer->valid_until->format('d.m.Y') }}</span>@endif
    @if($offer->request_date)<span>{{ $t('pdf_your_request') }}: {{ $offer->request_date->format('d.m.Y') }}</span>@endif
</div>

<p class="subject">{{ $t('pdf_subject') }}: {{ $offer->title }}</p>

@if($intro)<div class="text">{!! $intro !!}</div>@endif

<table class="items">
    <thead>
        <tr>
            <th style="width: 10mm">{{ $t('pdf_pos') }}</th>
            <th>{{ $t('pdf_description') }}</th>
            <th class="num" style="width: 14mm">{{ $t('pdf_quantity') }}</th>
            <th style="width: 16mm">{{ $t('pdf_unit') }}</th>
            <th class="num" style="width: 24mm">{{ $t('pdf_unit_price') }}</th>
            <th class="num" style="width: 26mm">{{ $t('pdf_amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($offer->lines as $line)
            @if($line->isItem())
                <tr>
                    <td>{{ $line->label }}</td>
                    <td>{!! nl2br(e($line->description)) !!}</td>
                    <td class="num">{{ \Plugins\Offers\Services\OfferPdf::quantity((string) $line->quantity) }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ $money((string) $line->unit_price) }}</td>
                    <td class="num">{{ $money((string) $line->amount) }}</td>
                </tr>
            @else
                <tr class="text">
                    <td>{{ $line->label }}</td>
                    <td colspan="5">{!! nl2br(e($line->description)) !!}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ $t('pdf_subtotal') }}</td><td class="num">{{ $money((string) $offer->subtotal) }}</td></tr>
    @if($offer->vat_rate !== null)
        <tr><td>{{ $t('pdf_vat', ['rate' => rtrim(rtrim((string) $offer->vat_rate, '0'), '.')]) }}</td><td class="num">{{ $money((string) $offer->vat_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ $t('pdf_total') }} {{ $offer->currency }}</td><td class="num">{{ $money((string) $offer->total) }}</td></tr>
</table>

@if($closing)<div class="text">{!! $closing !!}</div>@endif

@if($offer->valid_until)
    <p class="validity">{{ $t('pdf_validity_note', ['date' => $offer->valid_until->format('d.m.Y')]) }}</p>
@endif
</body>
</html>
