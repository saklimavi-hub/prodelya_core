<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerPortalUser;
use App\Models\OrderItemWorkFormAttachment;
use App\Services\CustomerPortalAccessService;
use App\Services\CustomerPortalFileDataBuilder;
use App\Services\TenantResolver;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerPortalFileController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CustomerPortalAccessService $portalAccessService,
        protected CustomerPortalFileDataBuilder $fileDataBuilder,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $attachments = OrderItemWorkFormAttachment::query()
            ->with([
                'order:id,document_number,customer_company_id,tenant_account_id',
                'workForm:id,work_form_number,product_snapshot,order_snapshot',
            ])
            ->where('tenant_account_id', $portalUser->scopeTenantId())
            ->where('visibility', 'customer_visible')
            ->whereHas('order', function ($query) use ($portalUser) {
                $query->where('customer_company_id', $portalUser->scopeCompanyId())
                    ->where('tenant_account_id', $portalUser->scopeTenantId());
            })
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('customer-portal.files.index', [
            'portalUser' => $portalUser,
            'company' => $portalUser->company,
            'tenant' => $tenant,
            'pageTitle' => 'Dosyalarım',
            'pageHeading' => 'Dosyalarım',
            'portalNav' => $this->portalNav($tenant),
            'attachments' => $attachments->through(fn (OrderItemWorkFormAttachment $attachment) => $this->fileDataBuilder->buildListRow(
                $attachment,
                route('customer.portal.files.show', $attachment->id)
            )),
        ]);
    }

    public function show(Request $request, OrderItemWorkFormAttachment $attachment): Response
    {
        /** @var CustomerPortalUser $portalUser */
        $portalUser = Auth::guard('customer_portal')->user();

        $attachment->loadMissing(['order', 'workForm.order']);

        if (! $attachment->isCustomerVisible()) {
            abort(404);
        }

        $workForm = $attachment->workForm;
        $order = $attachment->order ?: $workForm?->order;

        if (
            ! $workForm
            || ! $order
            || ! $portalUser->canSeeOrder($order)
            || ! $portalUser->canSeeWorkForm($workForm)
            || (int) $attachment->tenant_account_id !== $portalUser->scopeTenantId()
            || (int) $attachment->order_id !== (int) $order->id
            || (int) $attachment->work_form_id !== (int) $workForm->id
        ) {
            abort(404);
        }

        $filePath = (string) $attachment->file_path;
        $diskCandidates = collect([
            $attachment->disk,
            'public',
            config('filesystems.default'),
        ])->filter()->unique()->values();

        [$disk, $resolvedPath] = $this->resolveAttachmentStorageLocation(
            $workForm,
            $attachment,
            $diskCandidates->all(),
            $filePath
        );

        if (! $disk || $resolvedPath === null) {
            abort(404);
        }

        $mimeType = $attachment->mime_type ?: Storage::disk($disk)->mimeType($resolvedPath) ?: 'application/octet-stream';
        $fileName = $attachment->file_name ?: basename($resolvedPath);
        $disposition = $attachment->isImage() || Str::contains($mimeType, ['pdf'])
            ? sprintf('inline; filename="%s"', addslashes($fileName))
            : sprintf('attachment; filename="%s"', addslashes($fileName));

        return response(Storage::disk($disk)->get($resolvedPath), 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function portalNav($tenant): array
    {
        $quotesEnabled = $tenant ? $this->portalAccessService->portalQuotesEnabled($tenant) : false;
        $ordersEnabled = $tenant ? $this->portalAccessService->portalOrdersEnabled($tenant) : false;
        $filesEnabled = $tenant ? $this->portalAccessService->portalVisibleFilesEnabled($tenant) : false;

        return [
            'quotes_enabled' => $quotesEnabled,
            'orders_enabled' => $ordersEnabled,
            'files_enabled' => $filesEnabled,
            'active' => 'files',
        ];
    }

    private function resolveAttachmentStorageLocation($workForm, OrderItemWorkFormAttachment $attachment, array $diskCandidates, string $filePath): array
    {
        $normalizedFilePath = str_replace('\\', '/', $filePath);

        foreach ($diskCandidates as $disk) {
            foreach (array_values(array_unique(array_filter([$filePath, $normalizedFilePath]))) as $candidatePath) {
                if ($candidatePath !== '' && Storage::disk($disk)->exists($candidatePath)) {
                    return [$disk, $candidatePath];
                }
            }
        }

        $fileName = trim((string) $attachment->file_name);

        if ($fileName === '') {
            return [null, null];
        }

        $directory = str_replace('\\', '/', sprintf(
            'work-forms/%d/%d/%d',
            $workForm->tenant_account_id,
            $workForm->order_id,
            $workForm->id
        ));

        $originalBaseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9\-_ ]/', '')
            ->replace(' ', '-')
            ->trim('-_')
            ->value();

        foreach ($diskCandidates as $disk) {
            $storage = Storage::disk($disk);

            $matchedPath = $this->safeAllFiles($storage, $directory)
                ->first(function (string $candidatePath) use ($fileName, $originalBaseName): bool {
                    $candidateFileName = basename($candidatePath);

                    if ($candidateFileName === $fileName) {
                        return true;
                    }

                    return $originalBaseName !== ''
                        && str_starts_with(
                            Str::of(pathinfo($candidateFileName, PATHINFO_FILENAME))->value(),
                            $originalBaseName
                        );
                });

            if ($matchedPath) {
                return [$disk, $matchedPath];
            }

            $fallbackPath = $this->safeAllFiles($storage, 'work-forms')
                ->first(function (string $candidatePath) use ($fileName, $originalBaseName): bool {
                    $candidateFileName = basename($candidatePath);

                    if ($candidateFileName === $fileName) {
                        return true;
                    }

                    return $originalBaseName !== ''
                        && str_starts_with(
                            Str::of(pathinfo($candidateFileName, PATHINFO_FILENAME))->value(),
                            $originalBaseName
                        );
                });

            if ($fallbackPath) {
                return [$disk, $fallbackPath];
            }
        }

        return [null, null];
    }

    private function safeAllFiles($storage, string $directory)
    {
        try {
            return collect($storage->allFiles($directory));
        } catch (\Throwable) {
            return collect();
        }
    }
}
