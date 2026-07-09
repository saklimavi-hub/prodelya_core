<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TenantRootController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Marketing\PublicSiteController;
use App\Http\Controllers\CustomerPortal\CustomerPortalAuthController;
use App\Http\Controllers\CustomerPortal\CustomerPortalDashboardController;
use App\Http\Controllers\CustomerPortal\CustomerPortalQuoteController;
use App\Http\Controllers\CustomerPortal\CustomerPortalOrderController;
use App\Http\Controllers\CustomerPortal\CustomerPortalFileController;
use App\Http\Controllers\PublicQuoteApprovalController;
use App\Http\Controllers\PublicGraphicApprovalController;
use App\Http\Controllers\PublicWorkFormAttachmentController;
use App\Http\Controllers\PublicWorkFormTrackingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\CatalogSearchController;
use App\Http\Controllers\Admin\ProductHubLiveProductInfoController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TenantPackageOverviewController;
use App\Http\Controllers\Admin\TenantUpgradeRequestController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\TenantPackageRequestController;
use App\Http\Controllers\Admin\TenantPrintSettingController;
use App\Http\Controllers\Admin\TenantDeliveryTypeController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanyAddressController;
use App\Http\Controllers\Admin\CompanyContactController;
use App\Http\Controllers\Admin\CustomerPortalUserController;
use App\Http\Controllers\Admin\CompanyImportController;
use App\Http\Controllers\Admin\CurrentAccountController;
use App\Http\Controllers\Admin\CurrentAccountTransactionController;
use App\Http\Controllers\Admin\PromotionQuoteController;
use App\Http\Controllers\Admin\PrintServiceQuoteController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\GraphicController;
use App\Http\Controllers\Admin\GraphicCustomerApprovalController;
use App\Http\Controllers\Admin\ProcurementController;
use App\Http\Controllers\Admin\SupplierProcurementRequestController;
use App\Http\Controllers\Admin\ProductionController;
use App\Http\Controllers\Admin\PrintSetupRequirementController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\OrderPaymentController;
use App\Http\Controllers\Admin\WorkFormController;
use App\Http\Controllers\Admin\WorkFormAttachmentController;
use App\Http\Controllers\Admin\ProductDataHubController;
use App\Http\Controllers\Admin\TenantCatalogController;
use App\Http\Controllers\Admin\StandardProductBuildController;
use App\Http\Controllers\Admin\SupplierFieldMappingController;
use App\Http\Controllers\Admin\SupplierSourceController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\TenantController;
use App\Http\Controllers\SuperAdmin\TenantSignupRequestController;
use App\Http\Controllers\SuperAdmin\ModuleController;
use App\Http\Controllers\SuperAdmin\PackageController;
use App\Http\Controllers\SuperAdmin\SuperAdminProductDataHubController;
use App\Http\Controllers\SuperAdmin\SuperAdminFieldMappingController;
use App\Http\Controllers\SuperAdmin\SuperAdminCategoryMappingController;
use App\Http\Controllers\SuperAdmin\SuperAdminRawProductController;
use App\Http\Controllers\SuperAdmin\SuperAdminStandardProductController;
use App\Http\Controllers\SuperAdmin\SuperAdminStandardProductBuildController;
use App\Http\Controllers\SuperAdmin\SuperAdminSupplierSourceController;
use App\Http\Controllers\SuperAdmin\CategoryReviewBatchController;
use App\Http\Controllers\SuperAdmin\CategoryCleanupController;
use App\Http\Controllers\SuperAdmin\StandardCategoryController;
use App\Http\Controllers\SuperAdmin\TenantBillingController;
use App\Http\Controllers\SuperAdmin\TenantPackageUpgradeRequestController;
use App\Http\Controllers\SuperAdmin\TenantUpgradeRequestController as SuperAdminTenantUpgradeRequestController;
use App\Http\Controllers\SuperAdmin\TenantServiceController;
use App\Http\Controllers\SuperAdmin\TenantSupplierAccessController;
use App\Http\Controllers\SuperAdmin\PaymentProviderController;
use App\Http\Controllers\SuperAdmin\PaymentCheckoutSessionController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use App\Http\Controllers\Payments\PaymentCheckoutCallbackController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Public / Landing
Route::get('/', TenantRootController::class)->middleware('resolve.tenant')->name('marketing.home');
Route::middleware('central.public')->group(function () {
    Route::get('/register-interest', [PublicSiteController::class, 'registerInterest'])->name('marketing.register-interest');
    Route::post('/register-interest', [PublicSiteController::class, 'storeRegisterInterest'])
        ->middleware('throttle:5,1')
        ->name('marketing.register-interest.store');
    Route::get('/demo-talep', [PublicSiteController::class, 'demoRequest'])->name('marketing.demo-request');
    Route::post('/demo-talep', [PublicSiteController::class, 'storeDemoRequest'])
        ->middleware('throttle:5,1')
        ->name('marketing.demo-request.store');
});

Route::get('/takip/is-formu/{token}', [PublicWorkFormTrackingController::class, 'show'])
    ->name('public.work-forms.track');
Route::get('/takip/is-formu/{token}/dosya/{attachment}', [PublicWorkFormAttachmentController::class, 'show'])
    ->name('public.work-forms.attachments.show')
    ->whereNumber('attachment');
Route::prefix('/teklif/onay/{token}')->name('public.quotes.approval.')->group(function () {
    Route::get('/', [PublicQuoteApprovalController::class, 'show'])->name('show');
    Route::post('/onayla', [PublicQuoteApprovalController::class, 'approve'])->name('approve');
    Route::post('/revize-iste', [PublicQuoteApprovalController::class, 'requestRevision'])->name('revision');
    Route::post('/reddet', [PublicQuoteApprovalController::class, 'reject'])->name('reject');
});
Route::prefix('/grafik/onay/{token}')->name('public.graphics.approval.')->group(function () {
    Route::get('/', [PublicGraphicApprovalController::class, 'show'])->name('show');
    Route::post('/onayla', [PublicGraphicApprovalController::class, 'approve'])->name('approve');
    Route::post('/revize-iste', [PublicGraphicApprovalController::class, 'requestRevision'])->name('revision');
});

Route::middleware(['resolve.tenant'])->group(function () {
    Route::get('/musteri-giris', [CustomerPortalAuthController::class, 'showLoginForm'])->name('customer.login');
    Route::post('/musteri-giris', [CustomerPortalAuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('customer.login.submit');
    Route::get('/musteri-davet/{token}', [CustomerPortalAuthController::class, 'showInviteForm'])->name('customer.invite.accept');
    Route::post('/musteri-davet/{token}', [CustomerPortalAuthController::class, 'acceptInvite'])->name('customer.invite.accept.submit');
    Route::get('/musteri-sifre-sifirla', [CustomerPortalAuthController::class, 'showForgotPasswordForm'])->name('customer.password.request');
    Route::post('/musteri-sifre-sifirla', [CustomerPortalAuthController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('customer.password.email');
    Route::get('/musteri-sifre-yenile/{token}', [CustomerPortalAuthController::class, 'showResetPasswordForm'])->name('customer.password.reset');
    Route::post('/musteri-sifre-yenile/{token}', [CustomerPortalAuthController::class, 'resetPassword'])->name('customer.password.update');
    Route::post('/musteri-cikis', [CustomerPortalAuthController::class, 'logout'])->name('customer.logout');
});

Route::post('/payment-webhooks/{paymentProvider}', [PaymentWebhookController::class, 'receive'])
    ->name('payment-webhooks.receive')
    ->whereNumber('paymentProvider');
Route::get('/payment-checkouts/{paymentCheckout}/success', [PaymentCheckoutCallbackController::class, 'success'])
    ->name('payment-checkouts.callbacks.success')
    ->whereNumber('paymentCheckout');
Route::get('/payment-checkouts/{paymentCheckout}/failure', [PaymentCheckoutCallbackController::class, 'failure'])
    ->name('payment-checkouts.callbacks.failure')
    ->whereNumber('paymentCheckout');
Route::get('/payment-checkouts/{paymentCheckout}/cancel', [PaymentCheckoutCallbackController::class, 'cancel'])
    ->name('payment-checkouts.callbacks.cancel')
    ->whereNumber('paymentCheckout');

Route::middleware(['resolve.tenant', 'tenant.active'])->group(function () {
    Route::get('/musteri-portal', [CustomerPortalDashboardController::class, 'index'])
        ->middleware('customer.portal.auth')
        ->name('customer.portal.home');
    Route::prefix('/musteri-portal/teklifler')->name('customer.portal.quotes.')->middleware(['customer.portal.auth', 'feature.enabled:customer_portal,portal_quotes'])->group(function () {
        Route::get('/', [CustomerPortalQuoteController::class, 'index'])->name('index');
        Route::get('/{quote}/onay-linki', [CustomerPortalQuoteController::class, 'openApproval'])->name('approval.open')->whereNumber('quote');
        Route::get('/{quote}', [CustomerPortalQuoteController::class, 'show'])->name('show')->whereNumber('quote');
    });
    Route::prefix('/musteri-portal/siparisler')->name('customer.portal.orders.')->middleware(['customer.portal.auth', 'feature.enabled:customer_portal,portal_orders'])->group(function () {
        Route::get('/', [CustomerPortalOrderController::class, 'index'])->name('index');
        Route::get('/{order}/takip/{workForm}', [CustomerPortalOrderController::class, 'openTracking'])->name('tracking.open')
            ->whereNumber('order')
            ->whereNumber('workForm');
        Route::get('/{order}', [CustomerPortalOrderController::class, 'show'])->name('show')->whereNumber('order');
    });
    Route::prefix('/musteri-portal/dosyalar')->name('customer.portal.files.')->middleware(['customer.portal.auth', 'feature.enabled:graphics,customer_visible_files'])->group(function () {
        Route::get('/', [CustomerPortalFileController::class, 'index'])->name('index');
        Route::get('/{attachment}', [CustomerPortalFileController::class, 'show'])->name('show')->whereNumber('attachment');
    });
});

// Authentication
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.post');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Super Admin Routes (Central Domain Only)
Route::prefix('admin/super-admin')->name('admin.super.')->middleware(['auth:web', 'central.access', 'super.admin'])->group(function () {
    Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
    Route::prefix('signup-requests')->name('signup-requests.')->group(function () {
        Route::get('/', [TenantSignupRequestController::class, 'index'])->name('index');
        Route::get('/{signupRequest}', [TenantSignupRequestController::class, 'show'])->name('show')->whereNumber('signupRequest');
        Route::get('/{signupRequest}/conversion-preview', [TenantSignupRequestController::class, 'conversionPreview'])->name('conversion-preview')->whereNumber('signupRequest');
        Route::get('/{signupRequest}/conversion-success', [TenantSignupRequestController::class, 'conversionSuccess'])->name('conversion-success')->whereNumber('signupRequest');
        Route::patch('/{signupRequest}/status', [TenantSignupRequestController::class, 'updateStatus'])->name('status.update')->whereNumber('signupRequest');
        Route::post('/{signupRequest}/notes', [TenantSignupRequestController::class, 'storeNote'])->name('notes.store')->whereNumber('signupRequest');
    });
    Route::prefix('package-requests')->name('package-requests.')->group(function () {
        Route::get('/', [TenantPackageUpgradeRequestController::class, 'index'])->name('index');
        Route::get('/{packageRequest}', [TenantPackageUpgradeRequestController::class, 'show'])->name('show')->whereNumber('packageRequest');
        Route::patch('/{packageRequest}/status', [TenantPackageUpgradeRequestController::class, 'updateStatus'])->name('status.update')->whereNumber('packageRequest');
        Route::post('/{packageRequest}/apply', [TenantPackageUpgradeRequestController::class, 'apply'])->name('apply')->whereNumber('packageRequest');
    });
    Route::prefix('upgrade-requests')->name('upgrade-requests.')->group(function () {
        Route::get('/', [SuperAdminTenantUpgradeRequestController::class, 'index'])->name('index');
        Route::get('/{tenantUpgradeRequest}', [SuperAdminTenantUpgradeRequestController::class, 'show'])->name('show')->whereNumber('tenantUpgradeRequest');
        Route::post('/{tenantUpgradeRequest}/in-review', [SuperAdminTenantUpgradeRequestController::class, 'inReview'])->name('in-review')->whereNumber('tenantUpgradeRequest');
        Route::post('/{tenantUpgradeRequest}/approve', [SuperAdminTenantUpgradeRequestController::class, 'approve'])->name('approve')->whereNumber('tenantUpgradeRequest');
        Route::post('/{tenantUpgradeRequest}/reject', [SuperAdminTenantUpgradeRequestController::class, 'reject'])->name('reject')->whereNumber('tenantUpgradeRequest');
        Route::post('/{tenantUpgradeRequest}/cancel', [SuperAdminTenantUpgradeRequestController::class, 'cancel'])->name('cancel')->whereNumber('tenantUpgradeRequest');
        Route::post('/{tenantUpgradeRequest}/apply', [SuperAdminTenantUpgradeRequestController::class, 'apply'])->name('apply')->whereNumber('tenantUpgradeRequest');
    });
    Route::prefix('tenants')->name('tenants.')->group(function () {
        Route::get('/', [TenantController::class, 'index'])->name('index');
        Route::get('/create', [TenantController::class, 'create'])->name('create');
        Route::post('/', [TenantController::class, 'store'])->name('store');
        Route::get('/{tenant}', [TenantController::class, 'show'])->name('show')->whereNumber('tenant');
        Route::get('/{tenant}/billing', [TenantBillingController::class, 'index'])->name('billing.index')->whereNumber('tenant');
        Route::get('/{tenant}/billing/create', [TenantBillingController::class, 'create'])->name('billing.create')->whereNumber('tenant');
        Route::post('/{tenant}/billing', [TenantBillingController::class, 'store'])->name('billing.store')->whereNumber('tenant');
        Route::get('/{tenant}/billing/export/csv', [TenantBillingController::class, 'exportCsv'])->name('billing.export.csv')->whereNumber('tenant');
        Route::get('/{tenant}/billing/export/pdf', [TenantBillingController::class, 'exportPdf'])->name('billing.export.pdf')->whereNumber('tenant');
        Route::post('/{tenant}/billing/package-fee', [TenantBillingController::class, 'chargePackageFee'])->name('billing.package-fee')->whereNumber('tenant');
        Route::get('/{tenant}/billing/payment-checkouts/create', [PaymentCheckoutSessionController::class, 'create'])->name('billing.payment-checkouts.create')->whereNumber('tenant');
        Route::post('/{tenant}/billing/payment-checkouts', [PaymentCheckoutSessionController::class, 'store'])->name('payment-checkouts.store')->whereNumber('tenant');
        Route::get('/{tenant}/billing/{entry}/edit', [TenantBillingController::class, 'edit'])->name('billing.edit')->whereNumber('tenant')->whereNumber('entry');
        Route::put('/{tenant}/billing/{entry}', [TenantBillingController::class, 'update'])->name('billing.update')->whereNumber('tenant')->whereNumber('entry');
        Route::delete('/{tenant}/billing/{entry}', [TenantBillingController::class, 'destroy'])->name('billing.destroy')->whereNumber('tenant')->whereNumber('entry');
        Route::get('/{tenant}/edit', [TenantController::class, 'edit'])->name('edit')->whereNumber('tenant');
        Route::get('/{tenant}/owner/create', [TenantController::class, 'createOwner'])->name('owner.create')->whereNumber('tenant');
        Route::post('/{tenant}/owner', [TenantController::class, 'storeOwner'])->name('owner.store')->whereNumber('tenant');
        Route::post('/{tenant}/prepare-defaults', [TenantController::class, 'prepareDefaults'])->name('prepare-defaults')->whereNumber('tenant');
        Route::put('/{tenant}', [TenantController::class, 'update'])->name('update')->whereNumber('tenant');
        Route::put('/{tenant}/modules', [TenantController::class, 'updateModules'])->name('modules.update')->whereNumber('tenant');
        Route::put('/{tenant}/features', [TenantController::class, 'updateFeatures'])->name('features.update')->whereNumber('tenant');
        Route::put('/{tenant}/limits', [TenantController::class, 'updateLimits'])->name('limits.update')->whereNumber('tenant');
    });
    Route::prefix('packages')->name('packages.')->group(function () {
        Route::get('/', [PackageController::class, 'index'])->name('index');
        Route::get('/create', [PackageController::class, 'create'])->name('create');
        Route::post('/', [PackageController::class, 'store'])->name('store');
        Route::get('/{package}', [PackageController::class, 'show'])->name('show')->whereNumber('package');
        Route::get('/{package}/edit', [PackageController::class, 'edit'])->name('edit')->whereNumber('package');
        Route::put('/{package}', [PackageController::class, 'update'])->name('update')->whereNumber('package');
        Route::put('/{package}/modules', [PackageController::class, 'updateModules'])->name('modules.update')->whereNumber('package');
        Route::put('/{package}/features', [PackageController::class, 'updateFeatures'])->name('features.update')->whereNumber('package');
        Route::put('/{package}/limits', [PackageController::class, 'updateLimits'])->name('limits.update')->whereNumber('package');
    });
    Route::prefix('services')->name('services.')->group(function () {
        Route::get('/', [TenantServiceController::class, 'index'])->name('index');
        Route::get('/create', [TenantServiceController::class, 'create'])->name('create');
        Route::post('/', [TenantServiceController::class, 'store'])->name('store');
        Route::get('/{service}/edit', [TenantServiceController::class, 'edit'])->name('edit')->whereNumber('service');
        Route::put('/{service}', [TenantServiceController::class, 'update'])->name('update')->whereNumber('service');
    });
    Route::prefix('payment-providers')->name('payment-providers.')->group(function () {
        Route::get('/', [PaymentProviderController::class, 'index'])->name('index');
        Route::get('/create', [PaymentProviderController::class, 'create'])->name('create');
        Route::post('/', [PaymentProviderController::class, 'store'])->name('store');
        Route::get('/{paymentProvider}/edit', [PaymentProviderController::class, 'edit'])->name('edit')->whereNumber('paymentProvider');
        Route::put('/{paymentProvider}', [PaymentProviderController::class, 'update'])->name('update')->whereNumber('paymentProvider');
    });
    Route::prefix('payment-checkouts')->name('payment-checkouts.')->group(function () {
        Route::get('/', [PaymentCheckoutSessionController::class, 'index'])->name('index');
        Route::get('/{paymentCheckout}', [PaymentCheckoutSessionController::class, 'show'])->name('show')->whereNumber('paymentCheckout');
        Route::post('/{paymentCheckout}/cancel', [PaymentCheckoutSessionController::class, 'cancel'])->name('cancel')->whereNumber('paymentCheckout');
        Route::post('/{paymentCheckout}/expire', [PaymentCheckoutSessionController::class, 'expire'])->name('expire')->whereNumber('paymentCheckout');
        Route::post('/{paymentCheckout}/retry', [PaymentCheckoutSessionController::class, 'retry'])->name('retry')->whereNumber('paymentCheckout');
    });
    Route::get('/modules', [ModuleController::class, 'index'])->name('modules');
    Route::get('/settings', [ModuleController::class, 'settings'])->name('settings');
    Route::get('/product-data-hub', [SuperAdminProductDataHubController::class, 'index'])->name('product-data-hub.index');
    Route::get('/product-data-hub/pipeline', [SuperAdminProductDataHubController::class, 'pipeline'])->name('product-data-hub.pipeline');
    Route::get('/product-data-hub/catalog-output', [SuperAdminProductDataHubController::class, 'catalogOutput'])->name('product-data-hub.catalog-output');
    Route::post('/product-data-hub/catalog-output/project-missing', [SuperAdminProductDataHubController::class, 'catalogOutputProjectMissing'])->name('product-data-hub.catalog-output.project-missing');
    Route::post('/product-data-hub/catalog-output/project-refresh', [SuperAdminProductDataHubController::class, 'catalogOutputProjectRefresh'])->name('product-data-hub.catalog-output.project-refresh');
    Route::get('/product-data-hub/common-products', [SuperAdminProductDataHubController::class, 'commonProducts'])->name('product-data-hub.common-products');
    Route::get('/product-data-hub/product-panel', [SuperAdminProductDataHubController::class, 'productPanel'])->name('product-data-hub.product-panel');
    Route::post('/product-data-hub/product-panel/category-mappings/{mapping}', [SuperAdminProductDataHubController::class, 'saveProductPanelCategoryMapping'])->name('product-data-hub.product-panel.category-mappings.store')->whereNumber('mapping');
    Route::get('/product-data-hub/category-cleanup', [CategoryCleanupController::class, 'index'])->name('product-data-hub.category-cleanup.index');
    Route::get('/product-data-hub/category-feature-templates', [CategoryCleanupController::class, 'featureTemplates'])->name('product-data-hub.category-feature-templates.index');
    Route::get('/product-data-hub/category-cleanup/export/{format}', [CategoryCleanupController::class, 'exportDecisions'])->name('product-data-hub.category-cleanup.export')->whereIn('format', ['csv', 'json']);
    Route::post('/product-data-hub/category-cleanup/draft-items/{item}/reorder', [CategoryCleanupController::class, 'reorderDraftItem'])->name('product-data-hub.category-cleanup.draft-items.reorder')->whereNumber('item');
    Route::get('/product-data-hub/category-review-batches/{batch}', [CategoryReviewBatchController::class, 'show'])->name('product-data-hub.category-review-batches.show');
    Route::post('/product-data-hub/category-review-batches/{batch}/decisions', [CategoryReviewBatchController::class, 'storeDecision'])->name('product-data-hub.category-review-batches.decisions.store');
    Route::get('/product-data-hub/category-review-batches/{batch}/export/{format}', [CategoryReviewBatchController::class, 'export'])->name('product-data-hub.category-review-batches.export')->whereIn('format', ['csv', 'json']);
    Route::get('/product-data-hub/categories/search', [SuperAdminCategoryMappingController::class, 'categorySearch'])->name('product-data-hub.categories.search');
    Route::get('/product-data-hub/supplier-products', [SuperAdminProductDataHubController::class, 'supplierProducts'])->name('product-data-hub.supplier-products');
    Route::post('/product-data-hub/supplier-products/{rawProduct}/override-category', [SuperAdminProductDataHubController::class, 'saveSupplierProductOverride'])->name('product-data-hub.supplier-products.override-category')->whereNumber('rawProduct');
    Route::get('/product-data-hub/profile-comparison', [SuperAdminProductDataHubController::class, 'profileComparison'])->name('product-data-hub.profile-comparison');
    Route::prefix('product-data-hub/sources')->name('product-data-hub.sources.')->group(function () {
        Route::get('/', [SuperAdminSupplierSourceController::class, 'index'])->name('index');
        Route::get('/suppliers/{supplier}', [SuperAdminSupplierSourceController::class, 'showSupplier'])->name('suppliers.show')->whereNumber('supplier');
        Route::get('/create', [SuperAdminSupplierSourceController::class, 'create'])->name('create');
        Route::post('/', [SuperAdminSupplierSourceController::class, 'store'])->name('store');
        Route::get('/sync-reports', [SuperAdminSupplierSourceController::class, 'syncReports'])->name('sync-reports');
        Route::get('/{source}/edit', [SuperAdminSupplierSourceController::class, 'edit'])->name('edit')->whereNumber('source');
        Route::put('/{source}', [SuperAdminSupplierSourceController::class, 'update'])->name('update')->whereNumber('source');
        Route::post('/{source}/deactivate', [SuperAdminSupplierSourceController::class, 'deactivate'])->name('deactivate')->whereNumber('source');
        Route::post('/{source}/archive', [SuperAdminSupplierSourceController::class, 'archive'])->name('archive')->whereNumber('source');
        Route::delete('/{source}', [SuperAdminSupplierSourceController::class, 'destroy'])->name('destroy')->whereNumber('source');
        Route::get('/{source}/preview', [SuperAdminSupplierSourceController::class, 'preview'])->name('preview')->whereNumber('source');
        Route::post('/{source}/test', [SuperAdminSupplierSourceController::class, 'testConnection'])->name('test')->whereNumber('source');
        Route::post('/{source}/sync', [SuperAdminSupplierSourceController::class, 'syncNow'])->name('sync')->whereNumber('source');
        Route::post('/{source}/delta-dry-run', [SuperAdminSupplierSourceController::class, 'deltaDryRun'])->name('delta-dry-run')->whereNumber('source');
        Route::post('/{source}/apply-price-stock', [SuperAdminSupplierSourceController::class, 'applyPriceStock'])->name('apply-price-stock')->whereNumber('source');
        Route::post('/{source}/apply-price-stock-project-dirty', [SuperAdminSupplierSourceController::class, 'applyPriceStockAndProjectDirty'])->name('apply-price-stock-project-dirty')->whereNumber('source');
        Route::post('/{source}/stage-preview', [SuperAdminSupplierSourceController::class, 'stagePreview'])->name('stage-preview')->whereNumber('source');
        Route::post('/{source}/build-standard-products', [SuperAdminStandardProductBuildController::class, 'buildSource'])->name('build-standard-products')->whereNumber('source');
    });
    Route::prefix('product-data-hub/field-mappings')->name('product-data-hub.field-mappings.')->group(function () {
        Route::get('/', [SuperAdminFieldMappingController::class, 'index'])->name('index');
        Route::get('/source/{source}', [SuperAdminFieldMappingController::class, 'show'])->name('source')->whereNumber('source');
        Route::post('/source/{source}', [SuperAdminFieldMappingController::class, 'storeOrUpdate'])->name('source.update')->whereNumber('source');
    });
    Route::prefix('product-data-hub/category-mappings')->name('product-data-hub.category-mappings.')->group(function () {
        Route::get('/', [SuperAdminCategoryMappingController::class, 'index'])->name('index');
        Route::post('/scan', [SuperAdminCategoryMappingController::class, 'scan'])->name('scan');
        Route::post('/auto-approve', [SuperAdminCategoryMappingController::class, 'autoApprove'])->name('auto-approve');
        Route::get('/review-export/{format}', [SuperAdminCategoryMappingController::class, 'exportReview'])->name('review-export')->whereIn('format', ['csv', 'json']);
        Route::post('/bulk-update', [SuperAdminCategoryMappingController::class, 'bulkUpdate'])->name('bulk-update');
        Route::post('/bulk-apply', [SuperAdminCategoryMappingController::class, 'bulkApply'])->name('bulk-apply');
        Route::post('/{mapping}/accept', [SuperAdminCategoryMappingController::class, 'accept'])->name('accept')->whereNumber('mapping');
        Route::post('/{mapping}/cancel', [SuperAdminCategoryMappingController::class, 'cancel'])->name('cancel')->whereNumber('mapping');
        Route::put('/{mapping}', [SuperAdminCategoryMappingController::class, 'update'])->name('update')->whereNumber('mapping');
    });
    Route::get('/product-data-hub/category-mapping-center', [SuperAdminCategoryMappingController::class, 'index'])
        ->name('product-data-hub.category-mapping-center');
    Route::prefix('product-data-hub/raw-products')->name('product-data-hub.raw-products.')->group(function () {
        Route::get('/', [SuperAdminRawProductController::class, 'index'])->name('index');
        Route::post('/{rawProduct}/build-standard', [SuperAdminStandardProductBuildController::class, 'buildFromRaw'])->name('build-standard')->whereNumber('rawProduct');
    });
    Route::prefix('product-data-hub/standard-products')->name('product-data-hub.standard-products.')->group(function () {
        Route::get('/', [SuperAdminStandardProductController::class, 'index'])->name('index');
    });
    Route::prefix('standard-categories')->name('standard-categories.')->group(function () {
        Route::get('/', [StandardCategoryController::class, 'index'])->name('index');
        Route::get('/create', [StandardCategoryController::class, 'create'])->name('create');
        Route::post('/', [StandardCategoryController::class, 'store'])->name('store');
        Route::get('/bulk-paste', [StandardCategoryController::class, 'bulkPaste'])->name('bulk-paste');
        Route::post('/bulk-paste/preview', [StandardCategoryController::class, 'bulkPastePreview'])->name('bulk-paste.preview');
        Route::post('/bulk-paste/store', [StandardCategoryController::class, 'bulkPasteStore'])->name('bulk-paste.store');
        Route::get('/import', [StandardCategoryController::class, 'import'])->name('import');
        Route::get('/template', [StandardCategoryController::class, 'template'])->name('template');
        Route::post('/bulk-action', [StandardCategoryController::class, 'bulkAction'])->name('bulk-action');
        Route::post('/update-order', [StandardCategoryController::class, 'updateOrder'])->name('update-order');
        Route::post('/cleanup-unused', [StandardCategoryController::class, 'cleanupUnused'])->name('cleanup-unused');
        Route::post('/{category}/move', [StandardCategoryController::class, 'move'])->name('move')->whereNumber('category');
        Route::get('/{category}/attributes', [StandardCategoryController::class, 'showAttributes'])->name('attributes')->whereNumber('category');
        Route::post('/{category}/attributes', [StandardCategoryController::class, 'updateAttributes'])->name('attributes.update')->whereNumber('category');
        Route::post('/{category}/attributes/apply-template', [StandardCategoryController::class, 'applyAttributeTemplate'])->name('attributes.apply-template')->whereNumber('category');
        Route::post('/{category}/toggle-active', [StandardCategoryController::class, 'toggleActive'])->name('toggle-active')->whereNumber('category');
        Route::post('/{category}/toggle-catalog', [StandardCategoryController::class, 'toggleCatalog'])->name('toggle-catalog')->whereNumber('category');
        Route::get('/{category}/edit', [StandardCategoryController::class, 'edit'])->name('edit')->whereNumber('category');
        Route::put('/{category}', [StandardCategoryController::class, 'update'])->name('update')->whereNumber('category');
        Route::delete('/{category}', [StandardCategoryController::class, 'destroy'])->name('destroy')->whereNumber('category');
    });
    Route::get('/tenant-supplier-access', [TenantSupplierAccessController::class, 'index'])->name('tenant-supplier-access.index');
    Route::get('/tenant-supplier-access/{tenant}/edit', [TenantSupplierAccessController::class, 'edit'])->name('tenant-supplier-access.edit')->whereNumber('tenant');
    Route::put('/tenant-supplier-access/{tenant}', [TenantSupplierAccessController::class, 'update'])->name('tenant-supplier-access.update')->whereNumber('tenant');
});

// Tenant Admin Routes
Route::prefix('admin')->name('admin.')->middleware(['auth:web', 'resolve.tenant', 'tenant.active', 'tenant.membership'])->group(function () {
    Route::redirect('/', '/admin/dashboard')->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('module.enabled:core')->name('dashboard');
    // Promotion Quotes Routes
    Route::prefix('promotion-quotes')->name('promotion-quotes.')->middleware('module.enabled:order_flow')->group(function () {
        Route::get('/', [PromotionQuoteController::class, 'index'])->name('index');
        Route::get('/create', [PromotionQuoteController::class, 'create'])->name('create');
        Route::post('/', [PromotionQuoteController::class, 'store'])->name('store');
        Route::get('/customer-search', [PromotionQuoteController::class, 'customerSearch'])
            ->middleware('module.enabled:current_accounts')
            ->name('customer-search');
        Route::post('/quick-customer', [PromotionQuoteController::class, 'quickStoreCustomer'])
            ->middleware('module.enabled:current_accounts')
            ->name('quick-customer.store');
        Route::post('/{quote}/mark-approved', [PromotionQuoteController::class, 'markApproved'])->name('mark-approved')->whereNumber('quote');
        Route::post('/{quote}/send-to-customer', [PromotionQuoteController::class, 'sendToCustomer'])->middleware('feature.enabled:quote_customer_approval,public_quote_approval')->name('send-to-customer')->whereNumber('quote');
        Route::get('/{quote}/customer-approval/open', [PromotionQuoteController::class, 'openCustomerApproval'])
            ->middleware('feature.enabled:quote_customer_approval,public_quote_approval')
            ->name('customer-approval.open')
            ->whereNumber('quote');
        Route::post('/{quote}/whatsapp/open', [PromotionQuoteController::class, 'openWhatsappLink'])
            ->name('whatsapp.open')
            ->whereNumber('quote');
        Route::get('/{quote}/pdf', [PromotionQuoteController::class, 'pdf'])
            ->name('pdf')
            ->whereNumber('quote');
        Route::get('/{quote}', [PromotionQuoteController::class, 'show'])->name('show')->whereNumber('quote');
        Route::get('/{quote}/edit', [PromotionQuoteController::class, 'edit'])->name('edit')->whereNumber('quote');
        Route::put('/{quote}', [PromotionQuoteController::class, 'update'])->name('update')->whereNumber('quote');
        Route::delete('/{quote}', [PromotionQuoteController::class, 'destroy'])->name('destroy')->whereNumber('quote');
    });

    Route::get('/orders', [DashboardController::class, 'orders'])->middleware('module.enabled:order_flow')->name('orders');
    Route::get('/products', [DashboardController::class, 'products'])->name('products');
    Route::get('/catalog', [TenantCatalogController::class, 'index'])->name('catalog.index');
    Route::get('/catalog/product-panel', [TenantCatalogController::class, 'productPanel'])->name('catalog.product-panel');
    Route::get('/catalog/supplier-products', [TenantCatalogController::class, 'supplierProducts'])->name('catalog.supplier-products');
    Route::get('/catalog/local-products', [TenantCatalogController::class, 'localProducts'])->name('catalog.local-products');
    Route::get('/catalog/local-products/import', [TenantCatalogController::class, 'localProductsImport'])->name('catalog.local-products.import');
    Route::get('/catalog/local-products/import/template', [TenantCatalogController::class, 'localProductsImportTemplate'])->name('catalog.local-products.import.template');
    Route::post('/catalog/local-products/import/preview', [TenantCatalogController::class, 'previewLocalProductsImport'])->name('catalog.local-products.import.preview');
    Route::post('/catalog/local-products/import', [TenantCatalogController::class, 'storeLocalProductsImport'])->name('catalog.local-products.import.store');
    Route::post('/catalog/local-products', [TenantCatalogController::class, 'storeLocalProduct'])->name('catalog.local-products.store');
    Route::put('/catalog/local-products/{product}', [TenantCatalogController::class, 'updateLocalProduct'])->name('catalog.local-products.update')->whereNumber('product');
    Route::post('/catalog/local-products/{product}/deactivate', [TenantCatalogController::class, 'deactivateLocalProduct'])->name('catalog.local-products.deactivate')->whereNumber('product');
    Route::delete('/catalog/local-products/{product}', [TenantCatalogController::class, 'destroyLocalProduct'])->name('catalog.local-products.destroy')->whereNumber('product');
    Route::get('/catalog/visibility', [TenantCatalogController::class, 'visibility'])->name('catalog.visibility');
    Route::post('/catalog/visibility/bulk-update', [TenantCatalogController::class, 'bulkUpdateVisibility'])->name('catalog.visibility.bulk-update');
    Route::get('/catalog/warnings', [TenantCatalogController::class, 'warnings'])->name('catalog.warnings');
    
    Route::get('/product-hub/live-product-info', [ProductHubLiveProductInfoController::class, 'show'])->name('product-hub.live-product-info');
    Route::post('/catalog/project', [TenantCatalogController::class, 'project'])->name('catalog.project');
    Route::post('/catalog/{product}/toggle-quote-visibility', [TenantCatalogController::class, 'toggleQuoteVisibility'])->name('catalog.toggle-quote-visibility')->whereNumber('product');
    Route::post('/catalog/{product}/local-stock', [TenantCatalogController::class, 'updateLocalStock'])->name('catalog.local-stock')->whereNumber('product');
    Route::post('/catalog/{product}/local-stock-entry', [TenantCatalogController::class, 'storeLocalStockEntry'])->name('catalog.local-stock-entry')->whereNumber('product');
    Route::post('/catalog/{product}/warnings/review', [TenantCatalogController::class, 'markWarningReviewed'])->name('catalog.warnings.review')->whereNumber('product');
    Route::post('/catalog/{product}/warnings/action', [TenantCatalogController::class, 'quickWarningAction'])->name('catalog.warnings.action')->whereNumber('product');
    Route::get('/catalog/{product}', [TenantCatalogController::class, 'show'])->name('catalog.show')->whereNumber('product');
    Route::post('/catalog/{product}/toggle-visibility', [TenantCatalogController::class, 'toggleVisibility'])->name('catalog.toggle-visibility')->whereNumber('product');
    Route::get('/companies', [CompanyController::class, 'index'])->middleware('module.enabled:current_accounts')->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->middleware('module.enabled:current_accounts')->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->middleware('module.enabled:current_accounts')->name('companies.store');

    Route::prefix('current-accounts')->name('current-accounts.')->middleware('module.enabled:current_accounts')->group(function () {
        Route::get('/', [CurrentAccountController::class, 'index'])->name('index');
        Route::get('/create', [CurrentAccountController::class, 'create'])->name('create');
        Route::post('/', [CurrentAccountController::class, 'store'])->name('store');
        Route::get('/{currentAccount}', [CurrentAccountController::class, 'show'])->name('show')->whereNumber('currentAccount');
        Route::get('/{currentAccount}/edit', [CurrentAccountController::class, 'edit'])->name('edit')->whereNumber('currentAccount');
        Route::put('/{currentAccount}', [CurrentAccountController::class, 'update'])->name('update')->whereNumber('currentAccount');
        Route::patch('/{currentAccount}/status', [CurrentAccountController::class, 'updateStatus'])->name('update-status')->whereNumber('currentAccount');
        Route::post('/{currentAccount}/supplier-link', [CurrentAccountController::class, 'attachSupplier'])->name('supplier-link.store')->whereNumber('currentAccount');
        Route::delete('/{currentAccount}/supplier-link', [CurrentAccountController::class, 'detachSupplier'])->name('supplier-link.destroy')->whereNumber('currentAccount');
        Route::get('/{currentAccount}/transactions', [CurrentAccountTransactionController::class, 'accountTransactions'])->name('transactions.index')->whereNumber('currentAccount');
        Route::get('/{currentAccount}/transactions/export/pdf', [CurrentAccountTransactionController::class, 'exportPdf'])->name('transactions.export.pdf')->whereNumber('currentAccount');
        Route::get('/{currentAccount}/transactions/export/excel', [CurrentAccountTransactionController::class, 'exportExcel'])->name('transactions.export.excel')->whereNumber('currentAccount');
        Route::post('/{currentAccount}/transactions', [CurrentAccountTransactionController::class, 'store'])->name('transactions.store')->whereNumber('currentAccount');
    });

    Route::prefix('current-account-transactions')->name('current-account-transactions.')->middleware('module.enabled:current_accounts')->group(function () {
        Route::get('/', [CurrentAccountTransactionController::class, 'index'])->name('index');
        Route::post('/{transaction}/cancel', [CurrentAccountTransactionController::class, 'cancel'])->name('cancel')->whereNumber('transaction');
    });
    
    // Company Import Routes - Must come before dynamic routes
    Route::get('/companies/import', [CompanyImportController::class, 'index'])->middleware('module.enabled:current_accounts')->name('companies.import.index');
    Route::post('/companies/import/preview', [CompanyImportController::class, 'preview'])->middleware('module.enabled:current_accounts')->name('companies.import.preview');
    Route::post('/companies/import', [CompanyImportController::class, 'store'])->middleware('module.enabled:current_accounts')->name('companies.import.store');
    Route::get('/companies/import/template', [CompanyImportController::class, 'template'])->middleware('module.enabled:current_accounts')->name('companies.import.template');
    
    // Dynamic company routes - Must come after specific routes
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->middleware('module.enabled:current_accounts')->name('companies.show')->whereNumber('company');
    Route::post('/companies/{company}/contacts', [CompanyContactController::class, 'store'])
        ->middleware('module.enabled:current_accounts')
        ->name('companies.contacts.store')
        ->whereNumber('company');
    Route::post('/companies/{company}/addresses', [CompanyAddressController::class, 'store'])
        ->middleware('module.enabled:current_accounts')
        ->name('companies.addresses.store')
        ->whereNumber('company');
    Route::post('/companies/{company}/portal-users', [CustomerPortalUserController::class, 'store'])
        ->middleware(['module.enabled:current_accounts', 'module.enabled:customer_portal'])
        ->name('companies.portal-users.store')
        ->whereNumber('company');
    Route::post('/companies/{company}/portal-users/{portalUser}/resend-invite', [CustomerPortalUserController::class, 'resendInvite'])
        ->middleware(['module.enabled:current_accounts', 'module.enabled:customer_portal'])
        ->name('companies.portal-users.resend-invite')
        ->whereNumber('company')
        ->whereNumber('portalUser');
    Route::post('/companies/{company}/portal-users/{portalUser}/status', [CustomerPortalUserController::class, 'toggleStatus'])
        ->middleware(['module.enabled:current_accounts', 'module.enabled:customer_portal'])
        ->name('companies.portal-users.toggle-status')
        ->whereNumber('company')
        ->whereNumber('portalUser');
    Route::post('/companies/{company}/archive-duplicate', [CompanyController::class, 'archiveDuplicate'])
        ->middleware('module.enabled:current_accounts')
        ->name('companies.archive-duplicate')
        ->whereNumber('company');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->middleware('module.enabled:current_accounts')->name('companies.edit')->whereNumber('company');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->middleware('module.enabled:current_accounts')->name('companies.update')->whereNumber('company');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->middleware('module.enabled:current_accounts')->name('companies.destroy')->whereNumber('company');
    
    // Product Data Hub Routes
    Route::prefix('product-data-hub')->name('product-data-hub.')->middleware('module.enabled:product_data_hub')->group(function () {
        Route::get('/', [ProductDataHubController::class, 'index'])->name('index');
        Route::get('/sources', [SupplierSourceController::class, 'index'])->name('sources');
        Route::get('/sources/create', [SupplierSourceController::class, 'create'])->name('sources.create');
        Route::post('/sources', [SupplierSourceController::class, 'store'])->name('sources.store');
        Route::get('/sources/{source}/edit', [SupplierSourceController::class, 'edit'])->name('sources.edit')->whereNumber('source');
        Route::put('/sources/{source}', [SupplierSourceController::class, 'update'])->name('sources.update')->whereNumber('source');
        Route::delete('/sources/{source}', [SupplierSourceController::class, 'destroy'])->name('sources.destroy')->whereNumber('source');
        Route::get('/sources/{source}/preview', [SupplierSourceController::class, 'preview'])->name('sources.preview')->whereNumber('source');
        Route::post('/sources/{source}/stage-preview', [SupplierSourceController::class, 'stagePreview'])->name('sources.stage-preview')->whereNumber('source');
        Route::post('/sources/{source}/test', [SupplierSourceController::class, 'testConnection'])->name('sources.test')->whereNumber('source');
        
        Route::get('/field-mappings', [SupplierFieldMappingController::class, 'index'])->name('field-mappings');
        Route::get('/field-mappings/source/{source}', [SupplierFieldMappingController::class, 'show'])->name('field-mappings.source')->whereNumber('source');
        Route::post('/field-mappings/source/{source}', [SupplierFieldMappingController::class, 'storeOrUpdate'])->name('field-mappings.source.update')->whereNumber('source');
        Route::post('/field-mappings/source/{source}/suggest', [SupplierFieldMappingController::class, 'suggest'])->name('field-mappings.source.suggest')->whereNumber('source');
        Route::post('/field-mappings/source/{source}/reset', [SupplierFieldMappingController::class, 'reset'])->name('field-mappings.source.reset')->whereNumber('source');
        Route::get('/category-mappings', [ProductDataHubController::class, 'categoryMappings'])->name('category-mappings');
        Route::post('/category-mappings/bulk-update', [ProductDataHubController::class, 'bulkUpdateCategoryMappings'])->name('category-mappings.bulk-update');
        Route::put('/category-mappings/{mapping}', [ProductDataHubController::class, 'updateCategoryMapping'])->name('category-mappings.update')->whereNumber('mapping');
        Route::get('/product-mappings', [ProductDataHubController::class, 'productMappings'])->name('product-mappings');
        Route::get('/raw-products', [ProductDataHubController::class, 'rawProducts'])->name('raw-products');
        Route::post('/raw-products/{rawProduct}/build-standard', [StandardProductBuildController::class, 'buildFromRaw'])->name('raw-products.build-standard')->whereNumber('rawProduct');
        Route::post('/sources/{source}/build-standard-products', [StandardProductBuildController::class, 'buildSource'])->name('sources.build-standard-products')->whereNumber('source');
        Route::get('/standard-products', [ProductDataHubController::class, 'standardProducts'])->name('standard-products');
        Route::get('/tenant-access', [ProductDataHubController::class, 'tenantAccess'])->name('tenant-access');
        Route::get('/exports', [ProductDataHubController::class, 'exports'])->middleware('feature.enabled:xml_import_export,feed_outputs')->name('exports');
        Route::get('/logs', [ProductDataHubController::class, 'logs'])->name('logs');
        Route::post('/sync', [ProductDataHubController::class, 'sync'])->name('sync');
    });

    // Print Service Quotes Routes
    Route::prefix('print-service-quotes')->name('print-service-quotes.')->middleware('module.enabled:matbaa')->group(function () {
        Route::get('/', [PrintServiceQuoteController::class, 'index'])->name('index');
        Route::get('/create', [PrintServiceQuoteController::class, 'create'])->name('create');
        Route::post('/', [PrintServiceQuoteController::class, 'store'])->name('store');
        Route::get('/{quote}', [PrintServiceQuoteController::class, 'show'])->name('show')->whereNumber('quote');
        Route::get('/{quote}/edit', [PrintServiceQuoteController::class, 'edit'])->name('edit')->whereNumber('quote');
        Route::put('/{quote}', [PrintServiceQuoteController::class, 'update'])->name('update')->whereNumber('quote');
        Route::delete('/{quote}', [PrintServiceQuoteController::class, 'destroy'])->name('destroy')->whereNumber('quote');
        Route::post('/{quote}/convert', [PrintServiceQuoteController::class, 'convertToOrder'])->name('convert')->whereNumber('quote');
        Route::post('/{quote}/send', [PrintServiceQuoteController::class, 'sendToCustomer'])->name('send')->whereNumber('quote');
    });

    // Orders Routes
    Route::prefix('orders')->name('orders.')->middleware('module.enabled:order_flow')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/create', [OrderController::class, 'create'])->name('create');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/{order}/tracking/{workForm}', [OrderController::class, 'openTracking'])->name('tracking.open')
            ->whereNumber('order')
            ->whereNumber('workForm');
        Route::post('/{order}/delivery-packages', [OrderController::class, 'storeDeliveryPackages'])->name('delivery-packages.store')->whereNumber('order');
        Route::post('/{order}/delivery-labels', [OrderController::class, 'storeDeliveryLabels'])->name('delivery-labels.store')->whereNumber('order');
        Route::post('/{order}/delivery-info', [OrderController::class, 'updateDeliveryInfo'])->name('delivery-info.update')->whereNumber('order');
        Route::post('/{order}/delivery-complete', [OrderController::class, 'completeDelivery'])->name('delivery.complete')->whereNumber('order');
        Route::post('/{order}/revision-draft', [OrderController::class, 'createRevisionDraft'])->name('revision-draft.store')->whereNumber('order');
        Route::post('/{order}/repeat-order-draft', [OrderController::class, 'createRepeatOrderDraft'])->name('repeat-order-draft.store')->whereNumber('order');
        Route::get('/{order}/delivery-labels/print', [OrderController::class, 'printDeliveryLabels'])->name('delivery-labels.print')->whereNumber('order');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show')->whereNumber('order');
        Route::get('/{order}/edit', [OrderController::class, 'edit'])->name('edit')->whereNumber('order');
        Route::put('/{order}', [OrderController::class, 'update'])->name('update')->whereNumber('order');
        Route::delete('/{order}', [OrderController::class, 'destroy'])->name('destroy')->whereNumber('order');
        Route::post('/{order}/status', [OrderController::class, 'updateStatus'])->name('status.update')->whereNumber('order');
        Route::post('/convert/{quote}', [OrderController::class, 'convertFromQuote'])->name('convert.from.quote')->whereNumber('quote');
    });

    Route::prefix('graphics')->name('graphics.')->middleware('module.enabled:graphics')->group(function () {
        Route::get('/', [GraphicController::class, 'index'])->name('index');
        Route::get('/{workForm}', [GraphicController::class, 'show'])->name('show')->whereNumber('workForm');
        Route::post('/{graphic}/customer-approval/send', [GraphicCustomerApprovalController::class, 'send'])
            ->middleware(['module.enabled:graphic_customer_approval', 'feature.enabled:graphic_customer_approval,public_graphic_approval'])
            ->name('customer-approval.send')
            ->whereNumber('graphic');
        Route::get('/customer-approval/{approvalRequest}/open', [GraphicCustomerApprovalController::class, 'open'])
            ->middleware(['module.enabled:graphic_customer_approval', 'feature.enabled:graphic_customer_approval,public_graphic_approval'])
            ->name('customer-approval.open')
            ->whereNumber('approvalRequest');
        Route::patch('/{workForm}/status', [GraphicController::class, 'updateStatus'])->name('update-status')->whereNumber('workForm');
        Route::patch('/{workForm}/operations/{graphic}/status', [GraphicController::class, 'updateOperationStatus'])
            ->name('operations.update-status')
            ->whereNumber('workForm')
            ->whereNumber('graphic');
    });

    Route::prefix('procurements')->name('procurements.')->middleware('module.enabled:procurement')->group(function () {
        Route::get('/', [ProcurementController::class, 'index'])->name('index');
        Route::get('/supplier-requests/create', [SupplierProcurementRequestController::class, 'create'])->name('supplier-requests.create');
        Route::post('/supplier-requests', [SupplierProcurementRequestController::class, 'store'])->name('supplier-requests.store');
        Route::get('/supplier-requests/{supplierRequest}/edit', [SupplierProcurementRequestController::class, 'edit'])->name('supplier-requests.edit')->whereNumber('supplierRequest');
        Route::patch('/supplier-requests/{supplierRequest}', [SupplierProcurementRequestController::class, 'update'])->name('supplier-requests.update')->whereNumber('supplierRequest');
        Route::post('/supplier-requests/{supplierRequest}/mark-requested', [SupplierProcurementRequestController::class, 'markRequested'])->name('supplier-requests.mark-requested')->whereNumber('supplierRequest');
        Route::post('/supplier-requests/{supplierRequest}/mark-supplier-ordered', [SupplierProcurementRequestController::class, 'markSupplierOrdered'])->name('supplier-requests.mark-supplier-ordered')->whereNumber('supplierRequest');
        Route::post('/supplier-requests/{supplierRequest}/mark-partially-received', [SupplierProcurementRequestController::class, 'markPartiallyReceived'])->name('supplier-requests.mark-partially-received')->whereNumber('supplierRequest');
        Route::post('/supplier-requests/{supplierRequest}/mark-completed', [SupplierProcurementRequestController::class, 'markCompleted'])->name('supplier-requests.mark-completed')->whereNumber('supplierRequest');
        Route::post('/supplier-requests/{supplierRequest}/cancel', [SupplierProcurementRequestController::class, 'cancel'])->name('supplier-requests.cancel')->whereNumber('supplierRequest');
        Route::get('/supplier-requests/{supplierRequest}/print', [SupplierProcurementRequestController::class, 'print'])->name('supplier-requests.print')->whereNumber('supplierRequest');
        Route::get('/{procurement}', [ProcurementController::class, 'show'])->name('show')->whereNumber('procurement');
        Route::patch('/{procurement}/status', [ProcurementController::class, 'updateStatus'])->name('update-status')->whereNumber('procurement');
    });

    Route::prefix('productions')->name('productions.')->middleware('module.enabled:production')->group(function () {
        Route::get('/', [ProductionController::class, 'index'])->name('index');
        Route::get('/{production}', [ProductionController::class, 'show'])->name('show')->whereNumber('production');
        Route::patch('/{production}/status', [ProductionController::class, 'updateStatus'])->name('update-status')->whereNumber('production');
        Route::patch('/{production}/assignment', [ProductionController::class, 'updateAssignment'])->name('update-assignment')->whereNumber('production');
    });

    Route::prefix('print-setup-requirements')->name('print-setup-requirements.')->middleware('module.enabled:production')->group(function () {
        Route::post('/{requirement}/requested', [PrintSetupRequirementController::class, 'markRequested'])->name('requested')->whereNumber('requirement');
        Route::post('/{requirement}/ready', [PrintSetupRequirementController::class, 'markReady'])->name('ready')->whereNumber('requirement');
        Route::post('/{requirement}/cancel', [PrintSetupRequirementController::class, 'cancel'])->name('cancel')->whereNumber('requirement');
    });

    Route::prefix('deliveries')->name('deliveries.')->middleware('module.enabled:delivery')->group(function () {
        Route::get('/', [DeliveryController::class, 'index'])->name('index');
        Route::get('/{delivery}', [DeliveryController::class, 'show'])->name('show')->whereNumber('delivery');
        Route::patch('/{delivery}/status', [DeliveryController::class, 'updateStatus'])->name('update-status')->whereNumber('delivery');
        Route::patch('/{delivery}/details', [DeliveryController::class, 'updateDetails'])->name('update-details')->whereNumber('delivery');
    });

    Route::prefix('finance')->name('finance.')->middleware(['module.enabled:finance', 'feature.enabled:finance,finance_summary'])->group(function () {
        Route::get('/', [FinanceController::class, 'index'])->name('index');
        Route::get('/{order}', [FinanceController::class, 'show'])->name('show')->whereNumber('order');
        Route::post('/{order}/payments', [OrderPaymentController::class, 'store'])->name('payments.store')->whereNumber('order');
        Route::patch('/{order}/payments/{payment}/cancel', [OrderPaymentController::class, 'cancel'])->name('payments.cancel')->whereNumber('order')->whereNumber('payment');
        Route::post('/{order}/mark-paid', [OrderPaymentController::class, 'markPaid'])->name('mark-paid')->whereNumber('order');
    });

    Route::get('/work-forms/{workForm}', [WorkFormController::class, 'show'])->middleware('module.enabled:work_forms')
        ->name('work-forms.show')
        ->whereNumber('workForm');
    Route::get('/work-forms/{workForm}/pdf', [WorkFormController::class, 'pdf'])->middleware('module.enabled:work_forms')
        ->name('work-forms.pdf')
        ->whereNumber('workForm');
    Route::post('/work-forms/{workForm}/attachments', [WorkFormAttachmentController::class, 'store'])->middleware('module.enabled:work_forms')
        ->name('work-forms.attachments.store')
        ->whereNumber('workForm');
    Route::get('/work-form-attachments/{attachment}/preview', [WorkFormAttachmentController::class, 'preview'])->middleware('module.enabled:work_forms')
        ->name('work-forms.attachments.preview')
        ->whereNumber('attachment');
    Route::delete('/work-forms/{workForm}/attachments/{attachment}', [WorkFormAttachmentController::class, 'destroy'])->middleware('module.enabled:work_forms')
        ->name('work-forms.attachments.destroy')
        ->whereNumber('workForm')
        ->whereNumber('attachment');

    Route::get('/settings', [SettingsController::class, 'index'])->middleware('module.enabled:tenant_settings')->name('settings');
    Route::get('/my-package', [TenantPackageOverviewController::class, 'index'])->middleware(['module.enabled:tenant_settings', 'permission.check:manage_users'])->name('my-package.index');
    Route::prefix('upgrade-requests')->name('upgrade-requests.')->middleware(['module.enabled:tenant_settings', 'permission.check:manage_users'])->group(function () {
        Route::get('/', [TenantUpgradeRequestController::class, 'index'])->name('index');
        Route::post('/', [TenantUpgradeRequestController::class, 'store'])->name('store');
    });
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('module.enabled:tenant_settings')->name('settings.update');
    Route::get('/settings/company-profile', [SettingsController::class, 'editCompanyProfile'])->middleware('module.enabled:tenant_settings')->name('settings.company-profile.edit');
    Route::post('/settings/company-profile', [SettingsController::class, 'updateCompanyProfile'])->middleware('module.enabled:tenant_settings')->name('settings.company-profile.update');
    Route::prefix('/settings/delivery-types')->name('settings.delivery-types.')->middleware('module.enabled:tenant_settings')->group(function () {
        Route::get('/', [TenantDeliveryTypeController::class, 'index'])->name('index');
        Route::post('/', [TenantDeliveryTypeController::class, 'store'])->name('store');
        Route::put('/{tenantDeliveryType}', [TenantDeliveryTypeController::class, 'update'])->name('update')->whereNumber('tenantDeliveryType');
        Route::post('/{tenantDeliveryType}/default', [TenantDeliveryTypeController::class, 'makeDefault'])->name('default')->whereNumber('tenantDeliveryType');
    });
    Route::prefix('package-requests')->name('package-requests.')->middleware(['module.enabled:tenant_settings', 'permission.check:manage_users'])->group(function () {
        Route::get('/', [TenantPackageRequestController::class, 'index'])->name('index');
        Route::post('/', [TenantPackageRequestController::class, 'store'])->name('store');
    });
    Route::prefix('users')->name('users.')->middleware(['module.enabled:user_management', 'permission.check:manage_users'])->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit')->whereNumber('user');
        Route::put('/{user}', [UserController::class, 'update'])->name('update')->whereNumber('user');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy')->whereNumber('user');
    });
    Route::prefix('/settings/notifications')->name('settings.notifications.')->middleware(['module.enabled:notification_center', 'feature.enabled:notification_center,smtp_settings'])->group(function () {
        Route::get('/smtp', [NotificationSettingsController::class, 'smtp'])->name('smtp');
        Route::put('/smtp', [NotificationSettingsController::class, 'updateSmtp'])->name('smtp.update');
        Route::post('/smtp/test', [NotificationSettingsController::class, 'sendTestMail'])->name('smtp.test');
    });
    Route::prefix('/settings/notifications')->name('settings.notifications.')->middleware(['module.enabled:notification_center', 'feature.enabled:notification_center,whatsapp_links'])->group(function () {
        Route::get('/whatsapp', [NotificationSettingsController::class, 'whatsapp'])->name('whatsapp');
        Route::put('/whatsapp', [NotificationSettingsController::class, 'updateWhatsapp'])->name('whatsapp.update');
        Route::post('/whatsapp/preview', [NotificationSettingsController::class, 'previewWhatsapp'])->name('whatsapp.preview');
        Route::post('/whatsapp/create-link', [NotificationSettingsController::class, 'createWhatsappLink'])->name('whatsapp.create-link');
    });
    Route::prefix('/settings/print-settings')->name('settings.print-settings.')->middleware('module.enabled:print_settings')->group(function () {
        Route::get('/', [TenantPrintSettingController::class, 'index'])->name('index');
        Route::post('/sync', [TenantPrintSettingController::class, 'sync'])->name('sync');
        Route::get('/{tenantPrintSetting}/edit', [TenantPrintSettingController::class, 'edit'])->name('edit')->whereNumber('tenantPrintSetting');
        Route::put('/{tenantPrintSetting}', [TenantPrintSettingController::class, 'update'])->name('update')->whereNumber('tenantPrintSetting');
        Route::post('/{tenantPrintSetting}/options', [TenantPrintSettingController::class, 'storeOption'])->name('options.store')->whereNumber('tenantPrintSetting');
        Route::put('/{tenantPrintSetting}/options/{tenantPrintOption}', [TenantPrintSettingController::class, 'updateOption'])->name('options.update')->whereNumber('tenantPrintSetting')->whereNumber('tenantPrintOption');
        Route::post('/{tenantPrintSetting}/options/{tenantPrintOption}/default', [TenantPrintSettingController::class, 'makeDefaultOption'])->name('options.default')->whereNumber('tenantPrintSetting')->whereNumber('tenantPrintOption');
    });

    Route::prefix('notifications')->name('notifications.')->middleware('module.enabled:notification_center')->group(function () {
        Route::get('/', [NotificationCenterController::class, 'index'])->name('index');

        Route::prefix('logs')->name('logs.')->middleware('feature.enabled:notification_center,notification_logs')->group(function () {
            Route::get('/', [NotificationLogController::class, 'index'])->name('index');
            Route::get('/{notificationLog}', [NotificationLogController::class, 'show'])->name('show')->whereNumber('notificationLog');
        });

        Route::prefix('templates')->name('templates.')->middleware('feature.enabled:notification_center,notification_templates')->group(function () {
            Route::get('/', [NotificationTemplateController::class, 'index'])->name('index');
            Route::get('/create', [NotificationTemplateController::class, 'create'])->name('create');
            Route::post('/sync-defaults', [NotificationTemplateController::class, 'syncDefaults'])->name('sync-defaults');
            Route::post('/', [NotificationTemplateController::class, 'store'])->name('store');
            Route::post('/preview', [NotificationTemplateController::class, 'preview'])->name('preview');
            Route::get('/{template}/edit', [NotificationTemplateController::class, 'edit'])->name('edit')->whereNumber('template');
            Route::put('/{template}', [NotificationTemplateController::class, 'update'])->name('update')->whereNumber('template');
        });
    });
});

