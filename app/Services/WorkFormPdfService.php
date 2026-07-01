<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use App\Services\ProductDataHub\ProductHubSafeImageUrlService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WorkFormPdfService
{
    public function __construct(
        protected WorkFormRenderDataBuilder $renderDataBuilder,
        protected WorkFormQrCodeService $qrCodeService,
        protected ProductHubSafeImageUrlService $safeImageUrlService,
    ) {
    }

    public function buildViewData(OrderItemWorkForm $workForm): array
    {
        $data = $this->renderDataBuilder->build($workForm);

        $data['pdfQrDataUri'] = $this->qrCodeService->qrDataUri($workForm, 144, 1);
        $data['safeProductImageUrlForPdf'] = $this->safeImageUrlService->resolveFromSnapshot(
            is_array($data['productSnapshot'] ?? null) ? $data['productSnapshot'] : [],
            'work_form_pdf'
        );
        $data['graphicAttachments'] = $this->enrichAttachmentsForPdf($data['graphicAttachments'] ?? []);
        $data['productionPhotos'] = $this->enrichAttachmentsForPdf($data['productionPhotos'] ?? []);
        $data['deliveryAttachments'] = $this->enrichAttachmentsForPdf($data['deliveryAttachments'] ?? []);

        if (!empty($data['primaryGraphicAttachment'])) {
            $data['primaryGraphicAttachment'] = $this->enrichAttachmentForPdf($data['primaryGraphicAttachment']);
        }

        return $data;
    }

    public function renderHtml(OrderItemWorkForm $workForm): string
    {
        return view('admin.work-forms.pdf', $this->buildViewData($workForm))->render();
    }

    public function downloadResponse(OrderItemWorkForm $workForm): Response
    {
        $pdf = Pdf::loadHTML($this->renderHtml($workForm))
            ->setPaper('a4');

        return $pdf->download($this->fileName($workForm));
    }

    public function fileName(OrderItemWorkForm $workForm): string
    {
        $workFormNumber = $this->sanitizeSegment($workForm->work_form_number ?: 'work-form');
        $orderNumber = $this->sanitizeSegment(data_get($workForm->order_snapshot, 'document_number', 'siparis'));
        $itemNumber = 'Kalem-' . str_pad((string) $workForm->item_sequence, 2, '0', STR_PAD_LEFT);

        return sprintf('%s_%s_%s.pdf', $workFormNumber, $orderNumber, $this->sanitizeSegment($itemNumber));
    }

    private function enrichAttachmentsForPdf(iterable $attachments): array
    {
        $enriched = [];

        foreach ($attachments as $attachment) {
            $enriched[] = $this->enrichAttachmentForPdf($attachment);
        }

        return $enriched;
    }

    private function enrichAttachmentForPdf(array $attachment): array
    {
        $attachment['inline_src'] = null;

        if (!($attachment['is_image'] ?? false)) {
            return $attachment;
        }

        $path = (string) ($attachment['file_path'] ?? '');

        if ($path === '') {
            return $attachment;
        }

        $disk = $attachment['disk'] ?? config('filesystems.default');

        try {
            if (!Storage::disk($disk)->exists($path)) {
                return $attachment;
            }

            $binary = Storage::disk($disk)->get($path);
            $mimeType = $attachment['mime_type'] ?: Storage::disk($disk)->mimeType($path) ?: 'image/png';

            $attachment['inline_src'] = 'data:' . $mimeType . ';base64,' . base64_encode($binary);
        } catch (\Throwable) {
            $attachment['inline_src'] = null;
        }

        return $attachment;
    }

    private function sanitizeSegment(string $value): string
    {
        $ascii = Str::ascii($value);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii ?? '') ?: 'dosya';

        return trim($ascii, '-');
    }
}
