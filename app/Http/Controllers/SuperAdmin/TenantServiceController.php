<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\TenantServiceDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantServiceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search'));
        $status = trim((string) $request->input('status'));

        $services = TenantServiceDefinition::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('service_code', 'like', '%' . $search . '%')
                        ->orWhere('service_name', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when($status !== '', function ($query) use ($status): void {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->orderBy('service_name')
            ->get();

        return view('super-admin.services.index', [
            'services' => $services,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function create(): View
    {
        return view('super-admin.services.create', [
            'service' => new TenantServiceDefinition([
                'default_direction' => 'debit',
                'currency' => 'TRY',
                'is_active' => true,
                'sort_order' => 0,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $service = TenantServiceDefinition::query()->create($this->validateService($request));

        return redirect()
            ->route('admin.super.services.edit', $service)
            ->with('success', 'Tenant hizmet kalemi oluşturuldu.');
    }

    public function edit(TenantServiceDefinition $service): View
    {
        return view('super-admin.services.edit', [
            'service' => $service,
        ]);
    }

    public function update(Request $request, TenantServiceDefinition $service): RedirectResponse
    {
        $service->update($this->validateService($request, $service));

        return redirect()
            ->route('admin.super.services.edit', $service)
            ->with('success', 'Tenant hizmet kalemi güncellendi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateService(Request $request, ?TenantServiceDefinition $service = null): array
    {
        $validated = $request->validate([
            'service_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('tenant_service_definitions', 'service_code')->ignore($service?->id),
            ],
            'service_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'default_direction' => ['required', Rule::in(['debit', 'credit'])],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        return $validated;
    }
}
