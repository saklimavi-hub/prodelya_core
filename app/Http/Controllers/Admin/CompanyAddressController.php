<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAddress;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyAddressController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
    ) {
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ((int) $company->tenant_account_id !== (int) $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        $validated = $request->validate([
            'address_title' => ['nullable', 'string', 'max:255'],
            'address_type' => ['nullable', 'in:invoice,delivery,billing,shipping,other'],
            'address_body' => ['required', 'string'],
            'address_district' => ['nullable', 'string', 'max:100'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_country' => ['nullable', 'string', 'max:100'],
            'address_postal_code' => ['nullable', 'string', 'max:50'],
            'address_is_default' => ['nullable', 'boolean'],
        ], [
            'address_body.required' => 'Açık adres alanı zorunludur.',
            'address_type.in' => 'Adres tipi geçersiz.',
        ]);

        if ($request->boolean('address_is_default')) {
            $company->addresses()->update(['is_default' => false]);
        }

        CompanyAddress::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'address_type' => (string) ($validated['address_type'] ?? 'other'),
            'title' => filled($validated['address_title'] ?? null) ? trim((string) $validated['address_title']) : null,
            'country' => filled($validated['address_country'] ?? null) ? trim((string) $validated['address_country']) : 'Türkiye',
            'city' => filled($validated['address_city'] ?? null) ? trim((string) $validated['address_city']) : null,
            'district' => filled($validated['address_district'] ?? null) ? trim((string) $validated['address_district']) : null,
            'address' => trim((string) $validated['address_body']),
            'postal_code' => filled($validated['address_postal_code'] ?? null) ? trim((string) $validated['address_postal_code']) : null,
            'is_default' => $request->boolean('address_is_default'),
        ]);

        return redirect()->to(route('admin.companies.show', $company) . '#company-addresses')
            ->with('success', 'Adres eklendi.');
    }
}
