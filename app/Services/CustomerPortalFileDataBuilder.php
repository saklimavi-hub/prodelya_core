<?php

namespace App\Services;

use App\Models\OrderItemWorkFormAttachment;
use Illuminate\Support\Str;

class CustomerPortalFileDataBuilder
{
    public function buildListRow(OrderItemWorkFormAttachment $attachment, ?string $showUrl = null): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name ?: 'Dosya',
            'attachment_type_label' => $this->attachmentTypeLabel((string) $attachment->attachment_type),
            'order_number' => $attachment->order?->document_number ?: data_get($attachment->workForm?->order_snapshot, 'document_number', '-'),
            'work_form_number' => $attachment->workForm?->work_form_number ?: '-',
            'product_name' => data_get($attachment->workForm?->product_snapshot, 'product_name', '-'),
            'created_at' => optional($attachment->created_at)->format('d.m.Y H:i') ?: '-',
            'show_url' => $showUrl,
        ];
    }

    public function buildOrderAttachmentRow(OrderItemWorkFormAttachment $attachment, ?string $showUrl = null): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name ?: 'Dosya',
            'attachment_type_label' => $this->attachmentTypeLabel((string) $attachment->attachment_type),
            'created_at' => optional($attachment->created_at)->format('d.m.Y H:i') ?: '-',
            'show_url' => $showUrl,
        ];
    }

    private function attachmentTypeLabel(string $attachmentType): string
    {
        return match ($attachmentType) {
            'graphic_visual' => 'Grafik Görseli',
            'customer_approval' => 'Müşteri Onay Dosyası',
            'delivery_photo' => 'Teslimat Fotoğrafı',
            'delivery_document' => 'Teslimat Belgesi',
            'production_photo' => 'Üretim Fotoğrafı',
            default => Str::headline(str_replace('_', ' ', $attachmentType)),
        };
    }
}
