<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyContactController extends Controller
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
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_title' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_mobile' => ['nullable', 'string', 'max:50'],
            'contact_is_primary' => ['nullable', 'boolean'],
        ], [
            'contact_name.required' => 'Ad Soyad alanı zorunludur.',
            'contact_email.email' => 'Geçerli bir e-posta adresi giriniz.',
        ]);

        if ($request->boolean('contact_is_primary')) {
            $company->contacts()->update(['is_primary' => false]);
        }

        CompanyContact::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => trim((string) $validated['contact_name']),
            'title' => filled($validated['contact_title'] ?? null) ? trim((string) $validated['contact_title']) : null,
            'email' => filled($validated['contact_email'] ?? null) ? trim((string) $validated['contact_email']) : null,
            'phone' => filled($validated['contact_phone'] ?? null) ? trim((string) $validated['contact_phone']) : null,
            'mobile' => filled($validated['contact_mobile'] ?? null) ? trim((string) $validated['contact_mobile']) : null,
            'is_primary' => $request->boolean('contact_is_primary'),
        ]);

        return redirect()->to(route('admin.companies.show', $company) . '#company-contacts')
            ->with('success', 'Yetkili kişi eklendi.');
    }
}
