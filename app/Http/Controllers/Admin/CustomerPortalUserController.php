<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerPortalUser;
use App\Services\CustomerPortalAuthWorkflowService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPortalUserController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAuthWorkflowService $workflowService,
    ) {
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ((int) $company->tenant_account_id !== (int) $tenant->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('customer_portal_users', 'email')
                    ->where(fn ($query) => $query->where('tenant_account_id', $tenant->id)),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_contact_id' => [
                'nullable',
                Rule::exists('company_contacts', 'id')->where(fn ($query) => $query
                    ->where('tenant_account_id', $tenant->id)
                    ->where('company_id', $company->id)),
            ],
        ], [
            'name.required' => 'Ad Soyad alanı zorunludur.',
            'email.required' => 'E-posta alanı zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi giriniz.',
            'email.unique' => 'Bu e-posta adresi için portal kullanıcısı zaten kayıtlı.',
            'company_contact_id.exists' => 'Seçilen firma yetkilisi bu kayıt için geçerli değil.',
        ]);

        $result = $this->workflowService->createPortalUser(
            tenant: $tenant,
            company: $company,
            data: $validated,
            actor: $request->user(),
            currentHost: (string) $request->getHost(),
        );

        $message = match ($result['mail_status']) {
            'sent' => 'Portal kullanıcısı oluşturuldu ve davet maili gönderildi.',
            'skipped' => 'Portal kullanıcısı oluşturuldu. SMTP kapalı olduğu için davet linkini altta paylaşabilirsiniz.',
            default => 'Portal kullanıcısı oluşturuldu. Mail gönderimi başarısız olduğu için davet linkini altta paylaşabilirsiniz.',
        };

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', $message)
            ->with('portal_invite_link', $result['mail_status'] === 'sent' ? null : $result['invite_link']);
    }

    public function resendInvite(Request $request, Company $company, CustomerPortalUser $portalUser): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ((int) $company->tenant_account_id !== (int) $tenant->id || ! $portalUser->belongsToCompany($company) || ! $portalUser->belongsToTenant($tenant)) {
            abort(404);
        }

        $result = $this->workflowService->issueInvite($tenant, $portalUser, $request->user(), (string) $request->getHost());

        $message = match ($result['mail_status']) {
            'sent' => 'Portal daveti tekrar gönderildi.',
            'skipped' => 'Portal daveti yenilendi. SMTP kapalı olduğu için bağlantı altta gösteriliyor.',
            default => 'Portal daveti yenilendi ancak mail gönderilemedi. Bağlantı altta gösteriliyor.',
        };

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', $message)
            ->with('portal_invite_link', $result['mail_status'] === 'sent' ? null : $result['invite_link']);
    }

    public function toggleStatus(Request $request, Company $company, CustomerPortalUser $portalUser): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ((int) $company->tenant_account_id !== (int) $tenant->id || ! $portalUser->belongsToCompany($company) || ! $portalUser->belongsToTenant($tenant)) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                CustomerPortalUser::STATUS_ACTIVE,
                CustomerPortalUser::STATUS_PASSIVE,
                CustomerPortalUser::STATUS_SUSPENDED,
            ])],
        ], [
            'status.required' => 'Durum alanı zorunludur.',
            'status.in' => 'Seçilen durum geçersiz.',
        ]);

        $updatedUser = $this->workflowService->markStatus($tenant, $company, $portalUser, (string) $validated['status'], $request->user());

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('success', 'Portal kullanıcısı durumu güncellendi: ' . $updatedUser->safeStatusLabel());
    }
}
