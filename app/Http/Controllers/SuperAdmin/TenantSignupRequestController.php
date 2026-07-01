<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TenantSignupRequest;
use App\Services\SuperAdminOperationAuditService;
use App\Services\SuperAdmin\TenantSignupConversionService;
use App\Services\SuperAdmin\TenantSignupRequestReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantSignupRequestController extends Controller
{
    public function __construct(
        protected SuperAdminOperationAuditService $operationAuditService,
        protected TenantSignupRequestReadinessService $readinessService,
        protected TenantSignupConversionService $conversionService,
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search'));
        $type = trim((string) $request->input('type'));
        $status = trim((string) $request->input('status'));
        $package = trim((string) $request->input('package'));
        $baseQuery = TenantSignupRequest::query();

        $requests = (clone $baseQuery)
            ->with(['requestedPackage', 'convertedTenant'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('company_name', 'like', '%' . $search . '%')
                        ->orWhere('contact_name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->when($type !== '', fn ($query) => $query->where('request_type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($package !== '', fn ($query) => $query->where('requested_package_key', $package))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $readinessById = [];
        foreach ($requests->getCollection() as $item) {
            $readinessById[$item->id] = $this->readinessService->evaluate($item);
        }

        return view('super-admin.signup-requests.index', [
            'requests' => $requests,
            'readinessById' => $readinessById,
            'search' => $search,
            'type' => $type,
            'status' => $status,
            'package' => $package,
            'typeOptions' => TenantSignupRequest::typeOptions(),
            'statusOptions' => TenantSignupRequest::statusOptions(),
            'packages' => Package::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(),
            'summaryCards' => $this->summaryCards(clone $baseQuery),
            'conversionCandidatesCount' => (clone $baseQuery)
                ->whereIn('status', [TenantSignupRequest::STATUS_NEW, TenantSignupRequest::STATUS_CONTACTED])
                ->whereNull('converted_tenant_account_id')
                ->count(),
            'missingContactCount' => (clone $baseQuery)
                ->where(function ($query): void {
                    $query->whereNull('phone')
                        ->orWhere('phone', '')
                        ->orWhereNull('email')
                        ->orWhere('email', '');
                })
                ->count(),
        ]);
    }

    public function show(TenantSignupRequest $signupRequest): View
    {
        $signupRequest->load(['requestedPackage', 'convertedTenant']);
        $readiness = $this->readinessService->evaluate($signupRequest);
        $operatorNotes = collect(data_get($signupRequest->meta_json, 'operator_notes', []))
            ->filter(fn ($note) => is_array($note) && filled($note['note'] ?? null))
            ->sortByDesc('at')
            ->values()
            ->all();

        return view('super-admin.signup-requests.show', [
            'requestItem' => $signupRequest,
            'typeOptions' => TenantSignupRequest::typeOptions(),
            'statusOptions' => TenantSignupRequest::statusOptions(),
            'canConvert' => (bool) ($readiness['can_convert'] ?? false),
            'conversionChecks' => $this->conversionChecks($signupRequest),
            'transferSummary' => $this->transferSummary($signupRequest),
            'prefillFieldMap' => $this->prefillFieldMap(),
            'activityTimeline' => $this->operationAuditService->signupRequestTimeline($signupRequest),
            'readiness' => $readiness,
            'operatorNotes' => $operatorNotes,
        ]);
    }

    public function conversionPreview(Request $request, TenantSignupRequest $signupRequest): View
    {
        $signupRequest->load(['requestedPackage', 'convertedTenant']);
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $context = $this->conversionService->buildPreviewContext($signupRequest);
        $this->conversionService->logPreviewOpened($signupRequest, $actor);

        return view('super-admin.signup-requests.conversion-preview', [
            'requestItem' => $signupRequest,
            'typeOptions' => TenantSignupRequest::typeOptions(),
            'statusOptions' => TenantSignupRequest::statusOptions(),
            'activityTimeline' => $this->operationAuditService->signupRequestTimeline($signupRequest),
        ] + $context);
    }

    public function conversionSuccess(Request $request, TenantSignupRequest $signupRequest): View|RedirectResponse
    {
        $signupRequest->load(['requestedPackage', 'convertedTenant']);
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        if (!$signupRequest->convertedTenant || $signupRequest->status !== TenantSignupRequest::STATUS_CONVERTED) {
            return redirect()
                ->route('admin.super.signup-requests.show', $signupRequest)
                ->with('error', 'Dönüşüm başarı özeti yalnız dönüştürülmüş başvurular için açılabilir.');
        }

        $context = $this->conversionService->buildSuccessContext($signupRequest);
        $this->conversionService->logSuccessViewed($signupRequest, $actor);

        return view('super-admin.signup-requests.conversion-success', [
            'requestItem' => $signupRequest,
            'typeOptions' => TenantSignupRequest::typeOptions(),
            'statusOptions' => TenantSignupRequest::statusOptions(),
            'activityTimeline' => $this->operationAuditService->signupRequestTimeline($signupRequest),
        ] + $context);
    }

    public function updateStatus(Request $request, TenantSignupRequest $signupRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(TenantSignupRequest::statusOptions()))],
        ]);

        if (($signupRequest->status === TenantSignupRequest::STATUS_CONVERTED || filled($signupRequest->converted_tenant_account_id))
            && $validated['status'] !== TenantSignupRequest::STATUS_CONVERTED) {
            return redirect()
                ->route('admin.super.signup-requests.show', $signupRequest)
                ->with('error', 'Abone Firma’ya dönüştürülmüş başvuru farklı bir duruma alınamaz.');
        }

        $from = (string) $signupRequest->status;

        $signupRequest->update([
            'status' => $validated['status'],
        ]);

        $this->operationAuditService->logSignupRequestStatusChanged(
            $signupRequest->fresh(),
            $request->user(),
            $from,
            (string) $validated['status']
        );

        return redirect()
            ->route('admin.super.signup-requests.show', $signupRequest)
            ->with('success', 'Başvuru durumu güncellendi.');
    }

    public function storeNote(Request $request, TenantSignupRequest $signupRequest): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $meta = $signupRequest->meta_json ?? [];
        $notes = collect(data_get($meta, 'operator_notes', []))
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();

        $notes[] = [
            'at' => now()->toDateTimeString(),
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'note' => trim((string) $validated['note']),
        ];

        $meta['operator_notes'] = $notes;

        $signupRequest->update([
            'meta_json' => $meta,
        ]);

        $this->operationAuditService->logSignupRequestNoteAdded($signupRequest->fresh(), $actor, (string) $validated['note']);

        return redirect()
            ->route('admin.super.signup-requests.show', $signupRequest)
            ->with('success', 'Operasyon notu eklendi.');
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    protected function summaryCards($query): array
    {
        $all = $query->get([
            'request_type',
            'status',
            'requested_package_key',
            'requested_modules_json',
            'phone',
            'email',
            'created_at',
        ]);

        return [
            ['label' => 'İletişime Geçilen', 'value' => $all->where('status', TenantSignupRequest::STATUS_CONTACTED)->count(), 'tone' => 'amber'],
            ['label' => 'Abone Firma’ya Dönüşen', 'value' => $all->where('status', TenantSignupRequest::STATUS_CONVERTED)->count(), 'tone' => 'green'],
            ['label' => 'Yeni Başvuru', 'value' => $all->where('status', TenantSignupRequest::STATUS_NEW)->count(), 'tone' => 'blue'],
            ['label' => 'Reddedilen', 'value' => $all->where('status', TenantSignupRequest::STATUS_REJECTED)->count(), 'tone' => 'red'],
            ['label' => 'Arşiv', 'value' => $all->where('status', TenantSignupRequest::STATUS_ARCHIVED)->count(), 'tone' => 'slate'],
        ];
    }

    private function canConvert(TenantSignupRequest $signupRequest): bool
    {
        if (filled($signupRequest->converted_tenant_account_id)) {
            return false;
        }

        return in_array($signupRequest->status, [
            TenantSignupRequest::STATUS_NEW,
            TenantSignupRequest::STATUS_CONTACTED,
        ], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conversionChecks(TenantSignupRequest $signupRequest): array
    {
        $packageIsActive = $signupRequest->requestedPackage?->status === 'active'
            || filled($signupRequest->requested_package_key);
        $ownerEmailReady = filled($signupRequest->email);

        return [
            [
                'label' => 'Başvuru Durumu',
                'status' => $this->canConvert($signupRequest) ? 'Uygun' : 'Kontrol Edilmeli',
                'message' => $this->canConvert($signupRequest)
                    ? 'new / contacted durumunda ve yeniden dönüşmemiş.'
                    : 'Durum veya dönüşüm bilgisi nedeniyle ek kontrol gerekiyor.',
            ],
            [
                'label' => 'converted_tenant_account_id',
                'status' => filled($signupRequest->converted_tenant_account_id) ? 'Dolu' : 'Boş',
                'message' => filled($signupRequest->converted_tenant_account_id)
                    ? 'Başvuru daha önce tenant kaydıyla bağlanmış.'
                    : 'İlk dönüşüm için engel görünmüyor.',
            ],
            [
                'label' => 'Paket Uygunluğu',
                'status' => $packageIsActive ? 'Hazır' : 'Kontrol Edilmeli',
                'message' => $packageIsActive
                    ? 'Başvurudaki paket tercihi create ekranına taşınabilir.'
                    : 'Aktif paket eşleşmesi bulunamazsa create ekranında manuel seçim gerekir.',
            ],
            [
                'label' => 'Owner E-posta',
                'status' => $ownerEmailReady ? 'Hazır' : 'Eksik',
                'message' => $ownerEmailReady
                    ? 'Owner e-posta create akışında duplicate kurallarıyla tekrar kontrol edilir.'
                    : 'Owner kullanıcı oluşturma için e-posta eksik.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function transferSummary(TenantSignupRequest $signupRequest): array
    {
        return [
            ['label' => 'Firma', 'status' => filled($signupRequest->company_name) ? 'Var' : 'Eksik'],
            ['label' => 'Owner', 'status' => filled($signupRequest->contact_name) ? 'Var' : 'Eksik'],
            ['label' => 'E-posta', 'status' => filled($signupRequest->email) ? 'Var' : 'Eksik'],
            ['label' => 'Paket', 'status' => filled($signupRequest->requested_package_key) || filled($signupRequest->requested_package_id) ? 'Var' : 'Eksik'],
            ['label' => 'Modüller', 'status' => !empty($signupRequest->requested_modules_json) ? 'Metadata' : 'Yok'],
            ['label' => 'Not', 'status' => filled($signupRequest->note) ? 'Korunur' : 'Yok'],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function prefillFieldMap(): array
    {
        return [
            ['source' => 'company_name', 'target' => 'tenant_account.name'],
            ['source' => 'contact_name', 'target' => 'owner.name'],
            ['source' => 'email', 'target' => 'owner.email'],
            ['source' => 'requested_package_id / key', 'target' => 'package_id / package_key'],
            ['source' => 'requested_modules_json', 'target' => 'metadata / onboarding note'],
            ['source' => 'note', 'target' => 'tenant onboarding note'],
        ];
    }
}
