<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;

class NotificationTemplateDefaultSeederService
{
    public function __construct(
        private readonly NotificationEventCatalogService $eventCatalogService,
        private readonly NotificationTemplateService $notificationTemplateService,
    ) {
    }

    public function syncTenantDefaultTemplates(TenantAccount $tenant): array
    {
        return $this->createMissingTenantTemplates($tenant);
    }

    public function createMissingTenantTemplates(TenantAccount $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->activeDefaultTemplateSlots() as $slot) {
            $eventKey = $slot['event_key'];
            $channel = $slot['channel'];
            $content = $slot['content'];
            $audienceType = $slot['audience_type'];

            $exists = NotificationTemplate::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('notification_key', $eventKey)
                ->where('channel', $channel)
                ->where('audience_type', $audienceType)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $event = $this->eventCatalogService->getEvent($eventKey);

            $template = new NotificationTemplate([
                'tenant_account_id' => $tenant->id,
                'notification_key' => $eventKey,
                'channel' => $channel,
                'audience_type' => $audienceType,
                'title' => $this->titleForChannel($event['label'] ?? $eventKey, $channel),
                'subject' => $content['subject'] ?? null,
                'body' => $content['body'],
                'is_active' => true,
            ]);

            $variableInfo = $this->notificationTemplateService->validateTemplateVariables($template);

            $template->variables_json = $variableInfo['used_variables'];
            $template->created_by = null;
            $template->updated_by = null;
            $template->save();

            $created++;
        }

        return [
            'created_count' => $created,
            'skipped_count' => $skipped,
            'total_default_slots' => $created + $skipped,
        ];
    }

    /**
     * @return array<int, array{event_key:string,channel:string,audience_type:string,content:array<string, string>}>
     */
    public function activeDefaultTemplateSlots(): array
    {
        $slots = [];

        foreach ($this->defaultTemplateDefinitions() as $eventKey => $channels) {
            $event = $this->eventCatalogService->getEvent($eventKey);

            if (!$event || ($event['status'] ?? 'passive') !== 'active') {
                continue;
            }

            $audienceType = (string) ($event['default_audience'] ?? NotificationTemplate::AUDIENCE_INTERNAL);
            $allowedChannels = array_values(array_filter(
                (array) ($event['allowed_channels'] ?? []),
                fn (string $channel) => $channel !== NotificationTemplate::CHANNEL_SMS
            ));

            foreach ($channels as $channel => $content) {
                if (!in_array($channel, $allowedChannels, true)) {
                    continue;
                }

                $slots[] = [
                    'event_key' => $eventKey,
                    'channel' => $channel,
                    'audience_type' => $audienceType,
                    'content' => $content,
                ];
            }
        }

        return $slots;
    }

    private function titleForChannel(string $eventLabel, string $channel): string
    {
        return match ($channel) {
            NotificationTemplate::CHANNEL_EMAIL => $eventLabel . ' / E-posta',
            NotificationTemplate::CHANNEL_WHATSAPP_LINK => $eventLabel . ' / WhatsApp Hazır Mesaj',
            NotificationTemplate::CHANNEL_INTERNAL => $eventLabel . ' / İç Bildirim',
            default => $eventLabel,
        };
    }

    private function defaultTemplateDefinitions(): array
    {
        return [
            'quote_sent_to_customer' => [
                'email' => [
                    'subject' => '{{quote_number}} numaralı teklifiniz hazır',
                    'body' => "Merhaba {{customer_name}},\n{{quote_number}} numaralı teklifinizi inceleyebilirsiniz.\n\nTeklifi görüntülemek ve onaylamak için:\n{{public_quote_approval_url}}\n\nTeşekkürler.",
                ],
                'whatsapp_link' => [
                    'body' => 'Merhaba {{customer_name}}, {{quote_number}} numaralı teklifinizi inceleyip onaylayabilirsiniz: {{public_quote_approval_url}}',
                ],
                'internal' => [
                    'body' => '{{quote_number}} numaralı teklif müşteriye gönderildi.',
                ],
            ],
            'quote_customer_viewed' => [
                'internal' => [
                    'body' => 'Müşteri {{quote_number}} numaralı teklifi görüntüledi.',
                ],
            ],
            'quote_customer_approved' => [
                'internal' => [
                    'body' => '{{quote_number}} numaralı teklif müşteri tarafından onaylandı.',
                ],
            ],
            'quote_revision_requested' => [
                'internal' => [
                    'body' => 'Müşteri {{quote_number}} numaralı teklif için revize talep etti.',
                ],
            ],
            'quote_rejected' => [
                'internal' => [
                    'body' => '{{quote_number}} numaralı teklif müşteri tarafından reddedildi.',
                ],
            ],
            'quote_converted_to_order' => [
                'internal' => [
                    'body' => '{{quote_number}} numaralı teklif siparişe dönüştürüldü. Sipariş: {{order_number}}.',
                ],
            ],
            'graphic_visual_uploaded' => [
                'email' => [
                    'subject' => 'Grafik görseli yüklendi: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için yeni grafik görseli yüklendi.\nÜrün: {{product_summary}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için grafik görseli yüklendi.',
                ],
            ],
            'graphic_customer_approval_requested' => [
                'email' => [
                    'subject' => 'Grafik onayınız bekleniyor: {{work_form_number}}',
                    'body' => "Merhaba {{customer_name}},\n{{work_form_number}} iş formu için grafik onayınızı bekliyoruz.\n\nOnay bağlantısı:\n{{public_graphic_approval_url}}",
                ],
                'whatsapp_link' => [
                    'body' => 'Merhaba {{customer_name}}, {{work_form_number}} iş formu için grafik onay bağlantınız hazır: {{public_graphic_approval_url}}',
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için müşteri grafik onayı istendi.',
                ],
            ],
            'graphic_customer_approved' => [
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için grafik müşteri tarafından onaylandı.',
                ],
            ],
            'graphic_revision_requested' => [
                'email' => [
                    'subject' => 'Grafik revize talebi: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için grafik revize talebi geldi.\nÜrün: {{product_summary}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için grafik revize talebi geldi.',
                ],
            ],
            'graphic_production_ready' => [
                'email' => [
                    'subject' => 'Grafik üretime hazır: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için grafik üretime hazır.\nÜrün: {{product_summary}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için grafik üretime hazır.',
                ],
            ],
            'procurement_request_created' => [
                'email' => [
                    'subject' => 'Tedarik talebi oluşturuldu: {{procurement_number}}',
                    'body' => "{{procurement_number}} numaralı tedarik talebi oluşturuldu.\nİş formu: {{work_form_number}}\nÜrün: {{product_summary}}",
                ],
                'internal' => [
                    'body' => '{{procurement_number}} numaralı tedarik talebi oluşturuldu.',
                ],
            ],
            'procurement_ordered' => [
                'email' => [
                    'subject' => 'Tedarik siparişi verildi: {{procurement_number}}',
                    'body' => "{{procurement_number}} numaralı tedarik siparişi verildi.\nTedarikçi: {{supplier_name}}",
                ],
                'internal' => [
                    'body' => '{{procurement_number}} numaralı tedarik siparişi verildi.',
                ],
            ],
            'procurement_partially_received' => [
                'email' => [
                    'subject' => 'Tedarik kısmen teslim alındı: {{procurement_number}}',
                    'body' => "{{procurement_number}} numaralı tedarikte kısmi teslim alındı.\nGelen: {{received_quantity}}\nKalan: {{remaining_quantity}}",
                ],
                'internal' => [
                    'body' => '{{procurement_number}} numaralı tedarikte kısmi teslim alındı.',
                ],
            ],
            'procurement_received' => [
                'email' => [
                    'subject' => 'Tedarik teslim alındı: {{procurement_number}}',
                    'body' => "{{procurement_number}} numaralı tedarik tamamen teslim alındı.\nİş formu: {{work_form_number}}",
                ],
                'internal' => [
                    'body' => '{{procurement_number}} numaralı tedarik tamamen teslim alındı.',
                ],
            ],
            'procurement_cancelled' => [
                'email' => [
                    'subject' => 'Tedarik iptal edildi: {{procurement_number}}',
                    'body' => "{{procurement_number}} numaralı tedarik kaydı iptal edildi.\nİş formu: {{work_form_number}}",
                ],
                'internal' => [
                    'body' => '{{procurement_number}} numaralı tedarik kaydı iptal edildi.',
                ],
            ],
            'production_started' => [
                'email' => [
                    'subject' => 'Üretim başladı: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için üretim başladı.\nBaskı: {{print_label}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için üretim başladı.',
                ],
            ],
            'production_partially_completed' => [
                'email' => [
                    'subject' => 'Üretim kısmen tamamlandı: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için üretim kısmen tamamlandı.\nTamamlanan: {{completed_quantity}}\nKalan: {{remaining_quantity}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için üretim kısmen tamamlandı.',
                ],
            ],
            'production_completed' => [
                'email' => [
                    'subject' => 'Üretim tamamlandı: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için üretim tamamlandı.\nÜrün: {{product_summary}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için üretim tamamlandı.',
                ],
            ],
            'production_problem_reported' => [
                'email' => [
                    'subject' => 'Üretim sorunu bildirildi: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için üretim sorunu bildirildi.\nBaskı: {{print_label}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için üretim sorunu bildirildi.',
                ],
            ],
            'delivery_ready' => [
                'email' => [
                    'subject' => 'Teslimat hazır: {{work_form_number}}',
                    'body' => "{{work_form_number}} iş formu için teslimat hazır.\nTeslimat yöntemi: {{delivery_method}}",
                ],
                'internal' => [
                    'body' => '{{work_form_number}} iş formu için teslimat hazır.',
                ],
            ],
            'delivery_partially_delivered' => [
                'email' => [
                    'subject' => 'Kısmi teslimat yapıldı: {{order_number}}',
                    'body' => "Merhaba {{customer_name}},\n{{order_number}} siparişiniz için kısmi teslimat yapıldı.\nTeslim edilen miktar: {{delivered_quantity}}\nKalan miktar: {{remaining_quantity}}\nTakip: {{public_tracking_url}}",
                ],
                'whatsapp_link' => [
                    'body' => 'Merhaba {{customer_name}}, {{order_number}} siparişiniz için kısmi teslimat yapıldı. Takip: {{public_tracking_url}}',
                ],
                'internal' => [
                    'body' => '{{order_number}} siparişi için kısmi teslimat yapıldı.',
                ],
            ],
            'delivery_completed' => [
                'email' => [
                    'subject' => '{{order_number}} siparişiniz teslim edildi',
                    'body' => "Merhaba {{customer_name}},\n{{order_number}} numaralı siparişiniz teslim edildi.\nTakip ekranı: {{public_tracking_url}}",
                ],
                'whatsapp_link' => [
                    'body' => 'Merhaba {{customer_name}}, {{order_number}} numaralı siparişiniz teslim edildi. Takip: {{public_tracking_url}}',
                ],
                'internal' => [
                    'body' => '{{order_number}} numaralı siparişin teslimatı tamamlandı.',
                ],
            ],
            'delivery_problem_reported' => [
                'email' => [
                    'subject' => 'Teslimat sorunu bildirildi: {{order_number}}',
                    'body' => "{{order_number}} siparişi için teslimat sorunu bildirildi.\nDurum: {{delivery_status}}",
                ],
                'internal' => [
                    'body' => '{{order_number}} siparişi için teslimat sorunu bildirildi.',
                ],
            ],
            'delivery_document_uploaded' => [
                'email' => [
                    'subject' => 'Teslimat belgesi yüklendi: {{order_number}}',
                    'body' => "{{order_number}} siparişi için teslimat belgesi yüklendi.\nTakip: {{public_tracking_url}}",
                ],
                'whatsapp_link' => [
                    'body' => '{{order_number}} siparişi için teslimat belgesi yüklendi. Takip: {{public_tracking_url}}',
                ],
                'internal' => [
                    'body' => '{{order_number}} siparişi için teslimat belgesi yüklendi.',
                ],
            ],
            'payment_received' => [
                'email' => [
                    'subject' => 'Ödeme alındı: {{order_number}}',
                    'body' => "{{order_number}} siparişi için ödeme alındı.\nTutar: {{payment_amount}} {{payment_currency}}\nKalan bakiye: {{balance_due}}",
                ],
                'internal' => [
                    'body' => '{{order_number}} siparişi için {{payment_amount}} {{payment_currency}} ödeme alındı.',
                ],
            ],
            'payment_cancelled' => [
                'email' => [
                    'subject' => 'Ödeme iptal edildi: {{order_number}}',
                    'body' => "{{order_number}} siparişi için ödeme iptal edildi.\nReferans: {{payment_reference}}",
                ],
                'internal' => [
                    'body' => '{{order_number}} siparişi için ödeme iptal edildi.',
                ],
            ],
        ];
    }
}
