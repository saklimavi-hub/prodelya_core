<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use App\Services\TenantResolver;
use App\Support\ProcessDepth\ProcessDepth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProcessDepthSettingsController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantProcessDepthResolver $tenantProcessDepthResolver,
    ) {
    }

    public function show(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $effective = $this->tenantProcessDepthResolver->resolve($tenant);
        $packageDefault = $this->tenantProcessDepthResolver->resolvePackageDefault($tenant);
        $rawOverride = TenantSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('key', 'process_depth')
            ->value('value');
        $selectedProcessDepth = is_string($rawOverride) && ProcessDepth::isValid($rawOverride)
            ? ProcessDepth::normalize($rawOverride)
            : 'inherit';

        return view('admin.settings.process-depth', [
            'tenant' => $tenant,
            'effectiveDepth' => $effective,
            'packageDefaultDepth' => $packageDefault,
            'selectedProcessDepth' => $selectedProcessDepth,
            'hasInvalidOverride' => is_string($rawOverride) && trim($rawOverride) !== '' && ! ProcessDepth::isValid($rawOverride),
            'processDepthOptions' => [
                [
                    'key' => ProcessDepth::FAST,
                    'label' => 'Hızlı Akış',
                    'description' => 'Desteklenen operasyon ekranlarında daha kompakt görünüm ve daha az ikincil ayrıntı sunar.',
                ],
                [
                    'key' => ProcessDepth::STANDARD,
                    'label' => 'Standart Akış',
                    'description' => 'Günlük kullanım için dengeli ve önerilen çalışma şeklidir.',
                ],
                [
                    'key' => ProcessDepth::CONTROLLED,
                    'label' => 'Kontrollü Akış',
                    'description' => 'Desteklenen ekranlarda hazırlık, kontrol ve faaliyet ayrıntılarını daha görünür hale getirir.',
                ],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $request->validate([
            'process_depth_selection' => ['required', Rule::in(array_merge(['inherit'], ProcessDepth::values()))],
        ]);

        $selection = (string) $validated['process_depth_selection'];

        if ($selection === 'inherit') {
            TenantSetting::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('key', 'process_depth')
                ->delete();
        } else {
            TenantSetting::setValue($tenant->id, 'process_depth', ProcessDepth::normalize($selection), 'string');
        }

        return redirect()
            ->route('admin.settings.process-depth')
            ->with('success', 'Süreç derinliği ayarı kaydedildi.');
    }
}