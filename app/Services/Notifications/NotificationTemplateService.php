<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\TenantAccount;

class NotificationTemplateService
{
    public function __construct(
        protected NotificationEventCatalogService $eventCatalogService
    ) {
    }

    public function findTemplate(?TenantAccount $tenant, string $notificationKey, string $channel, string $audienceType): ?NotificationTemplate
    {
        $originalKey = trim($notificationKey);
        $notificationKey = $this->eventCatalogService->normalizeEventKey($notificationKey);
        $audienceCandidates = $this->audienceLookupCandidates($audienceType);
        $candidateKeys = array_values(array_unique([$notificationKey, $originalKey]));

        if ($tenant) {
            $template = NotificationTemplate::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('notification_key', $candidateKeys)
                ->where('channel', $channel)
                ->whereIn('audience_type', $audienceCandidates)
                ->orderByRaw('case when notification_key = ? then 0 else 1 end', [$notificationKey])
                ->orderByRaw($this->audiencePrioritySql($audienceCandidates), $audienceCandidates)
                ->first();

            if ($template) {
                return $template;
            }
        }

        $template = NotificationTemplate::query()
            ->whereNull('tenant_account_id')
            ->whereIn('notification_key', $candidateKeys)
            ->where('channel', $channel)
            ->whereIn('audience_type', $audienceCandidates)
            ->orderByRaw('case when notification_key = ? then 0 else 1 end', [$notificationKey])
            ->orderByRaw($this->audiencePrioritySql($audienceCandidates), $audienceCandidates)
            ->first();

        if ($template) {
            return $template;
        }

        return $this->buildFallbackTemplate($notificationKey, $channel, $audienceType);
    }

    public function render(NotificationTemplate $template, array $variables, string $audienceType): array
    {
        $variables = $this->expandVariableAliases($variables);
        $usedVariables = $this->extractVariables($template);
        $forbidden = $this->forbiddenVariablesForAudience($audienceType);
        $blocked = array_values(array_intersect($usedVariables, $forbidden));
        $missing = array_values(array_diff(
            array_diff($usedVariables, $blocked),
            array_keys($variables)
        ));

        return [
            'subject' => $this->replaceVariables((string) ($template->subject ?? ''), $variables, $blocked, $missing),
            'body' => $this->replaceVariables((string) $template->body, $variables, $blocked, $missing),
            'blocked_variables' => $blocked,
            'missing_variables' => $missing,
        ];
    }

    public function validateTemplateVariables(NotificationTemplate $template): array
    {
        $usedVariables = $this->extractVariables($template);
        $blocked = array_values(array_intersect(
            $usedVariables,
            $this->forbiddenVariablesForAudience($template->audience_type)
        ));

        return [
            'used_variables' => $usedVariables,
            'allowed_variables' => $this->allowedVariablesForAudience($template->audience_type),
            'blocked_variables' => $blocked,
        ];
    }

    public function allowedVariablesForAudience(string $audienceType): array
    {
        return match ($this->normalizeAudienceForVariables($audienceType)) {
            NotificationTemplate::AUDIENCE_CUSTOMER => [
                'customer_name',
                'company_name',
                'order_number',
                'quote_number',
                'work_form_number',
                'product_summary',
                'product_name',
                'print_label',
                'graphic_status',
                'production_status',
                'production_type_label',
                'delivery_method',
                'tracking_number',
                'recipient_name',
                'product_code',
                'supplier_name',
                'procurement_number',
                'requested_quantity',
                'received_quantity',
                'remaining_quantity',
                'planned_quantity',
                'completed_quantity',
                'package_count',
                'units_per_package',
                'delivered_quantity',
                'status_label',
                'public_tracking_url',
                'public_quote_approval_url',
                'public_quote_url',
                'public_graphic_approval_url',
                'delivery_status',
                'delivery_date',
            ],
            NotificationTemplate::AUDIENCE_FINANCE => [
                'customer_name',
                'company_name',
                'order_number',
                'quote_number',
                'status_label',
                'payment_warning_label',
                'payment_type_label',
                'payment_method',
                'payment_method_label',
                'payment_amount',
                'payment_currency',
                'payment_reference',
                'paid_at',
                'due_date',
                'subtotal',
                'vat_total',
                'grand_total',
                'paid_total',
                'balance_due',
            ],
            default => [
                'customer_name',
                'company_name',
                'order_number',
                'quote_number',
                'work_form_number',
                'product_summary',
                'product_name',
                'print_label',
                'graphic_status',
                'production_status',
                'production_type_label',
                'delivery_method',
                'tracking_number',
                'recipient_name',
                'product_code',
                'supplier_name',
                'procurement_number',
                'requested_quantity',
                'received_quantity',
                'remaining_quantity',
                'planned_quantity',
                'completed_quantity',
                'package_count',
                'units_per_package',
                'delivered_quantity',
                'status_label',
                'internal_note',
                'assigned_user_name',
            ],
        };
    }

    public function forbiddenVariablesForAudience(string $audienceType): array
    {
        $alwaysForbidden = [
            'Product_Data_Hub',
            'pdh_raw',
            'group_code',
            'file_path',
            'physical_path',
            'storage_path',
            'storage_app',
            'smtp_password',
            'api_key',
            'token',
            'raw_xml',
            'raw_json',
        ];

        $normalizedAudience = $this->normalizeAudienceForVariables($audienceType);

        if ($normalizedAudience === NotificationTemplate::AUDIENCE_CUSTOMER) {
            return array_merge($alwaysForbidden, [
                'unit_price',
                'list_price',
                'discount_rate',
                'line_total',
                'print_unit_price',
                'print_total',
                'subtotal',
                'vat_total',
                'grand_total',
                'paid_total',
                'balance_due',
                'payment_amount',
                'total_amount',
                'balance',
                'profit',
                'cost',
                'supplier_cost',
                'subcontractor_cost',
                'maliyet',
                'margin',
                'kar',
            ]);
        }

        if ($normalizedAudience === NotificationTemplate::AUDIENCE_FINANCE) {
            return $alwaysForbidden;
        }

        return array_merge($alwaysForbidden, [
            'unit_price',
            'list_price',
            'discount_rate',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'paid_total',
            'balance_due',
            'payment_amount',
            'total_amount',
            'balance',
            'profit',
            'cost',
            'supplier_cost',
            'subcontractor_cost',
        ]);
    }

    private function extractVariables(NotificationTemplate $template): array
    {
        $subject = (string) ($template->subject ?? '');
        $body = (string) $template->body;
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $subject . "\n" . $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function replaceVariables(string $content, array $variables, array $blocked, array $missing): string
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $content, $matches);

        foreach (array_unique($matches[1] ?? []) as $variable) {
            $replacement = '';

            if (!in_array($variable, $blocked, true) && !in_array($variable, $missing, true)) {
                $replacement = (string) ($variables[$variable] ?? '');
            }

            $content = preg_replace('/\{\{\s*' . preg_quote($variable, '/') . '\s*\}\}/', $replacement, $content) ?? $content;
        }

        return $content;
    }

    private function buildFallbackTemplate(string $notificationKey, string $channel, string $audienceType): ?NotificationTemplate
    {
        $event = $this->eventCatalogService->getEvent($notificationKey);

        if (!$event || !in_array($channel, $event['allowed_channels'] ?? [], true)) {
            return null;
        }

        $template = new NotificationTemplate();
        $template->tenant_account_id = null;
        $template->notification_key = $notificationKey;
        $template->channel = $channel;
        $template->audience_type = $audienceType;
        $template->title = $event['label'] ?? null;
        $template->subject = $event['default_template_subject'] ?? null;
        $template->body = $event['default_template_body'] ?? '';
        $template->is_active = true;
        $template->variables_json = [];

        return $template;
    }

    public function variableHelpForAudience(string $audienceType): array
    {
        $allowed = array_flip($this->allowedVariablesForAudience($audienceType));
        $catalog = [
            'Müşteri' => [
                'customer_name' => 'Müşteri adı',
                'company_name' => 'Firma adı',
            ],
            'Teklif' => [
                'quote_number' => 'Teklif no',
                'public_quote_approval_url' => 'Teklif onay bağlantısı',
            ],
            'Sipariş' => [
                'order_number' => 'Sipariş no',
                'work_form_number' => 'İş formu no',
                'public_tracking_url' => 'Müşteri takip ekranı',
            ],
            'Ürün' => [
                'product_summary' => 'Ürün özeti',
            ],
            'Durum' => [
                'status_label' => 'Durum etiketi',
            ],
            'Finans' => [
                'payment_amount' => 'Tahsil edilen tutar',
                'balance_due' => 'Kalan bakiye',
                'paid_total' => 'Toplam tahsilat',
            ],
        ];

        $groups = [];

        foreach ($catalog as $groupLabel => $variables) {
            $items = [];

            foreach ($variables as $key => $description) {
                if (!array_key_exists($key, $allowed)) {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'placeholder' => '{{' . $key . '}}',
                    'description' => $description,
                ];
            }

            if (!empty($items)) {
                $groups[] = [
                    'label' => $groupLabel,
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    private function normalizeAudienceForVariables(string $audienceType): string
    {
        return match ($audienceType) {
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
            'tenant_admin',
            'graphic_team',
            'production_team',
            'procurement_team',
            'delivery_team' => NotificationTemplate::AUDIENCE_INTERNAL,
            'finance_team' => NotificationTemplate::AUDIENCE_FINANCE,
            default => $audienceType,
        };
    }

    private function expandVariableAliases(array $variables): array
    {
        $aliases = [
            'public_quote_approval_url' => 'public_quote_url',
            'public_quote_url' => 'public_quote_approval_url',
        ];

        foreach ($aliases as $alias => $sourceKey) {
            if (!array_key_exists($alias, $variables) && array_key_exists($sourceKey, $variables)) {
                $variables[$alias] = $variables[$sourceKey];
            }
        }

        if (!array_key_exists('company_name', $variables) && array_key_exists('customer_name', $variables)) {
            $variables['company_name'] = $variables['customer_name'];
        }

        return $variables;
    }

    private function audienceLookupCandidates(string $audienceType): array
    {
        $candidates = [$audienceType];

        $normalizedAudience = $this->normalizeAudienceForVariables($audienceType);
        if (!in_array($normalizedAudience, $candidates, true)) {
            $candidates[] = $normalizedAudience;
        }

        if ($normalizedAudience === NotificationTemplate::AUDIENCE_INTERNAL) {
            foreach ([NotificationTemplate::AUDIENCE_ADMIN, NotificationTemplate::AUDIENCE_SALES_OWNER] as $audience) {
                if (!in_array($audience, $candidates, true)) {
                    $candidates[] = $audience;
                }
            }
        }

        if ($normalizedAudience === NotificationTemplate::AUDIENCE_FINANCE && !in_array('finance_team', $candidates, true)) {
            $candidates[] = 'finance_team';
        }

        return $candidates;
    }

    private function audiencePrioritySql(array $audiences): string
    {
        $cases = [];

        foreach (array_values($audiences) as $index => $audience) {
            $cases[] = "when audience_type = ? then {$index}";
        }

        return 'case ' . implode(' ', $cases) . ' else ' . count($audiences) . ' end';
    }
}
