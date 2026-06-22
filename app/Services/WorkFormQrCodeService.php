<?php

namespace App\Services;

use App\Models\OrderItemWorkForm;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class WorkFormQrCodeService
{
    public function trackingUrl(OrderItemWorkForm $workForm): string
    {
        return route('public.work-forms.track', $workForm->public_tracking_token);
    }

    public function qrSvg(OrderItemWorkForm $workForm, int $size = 132, int $margin = 1): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($this->trackingUrl($workForm));
    }

    public function qrDataUri(OrderItemWorkForm $workForm, int $size = 132, int $margin = 1): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->qrSvg($workForm, $size, $margin));
    }
}
