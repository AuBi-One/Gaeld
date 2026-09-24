<div class="page-footer">
    <span class="brand-mark">{{ \App\Support\Pdf\PdfFooter::text($organization ?? null) }}</span>
    <span class="footer-meta">{{ __('exports.common.generated_on') }} {{ now()->format('d.m.Y H:i') }} · {{ __('exports.common.page') }} <span class="page-num"></span></span>
</div>
