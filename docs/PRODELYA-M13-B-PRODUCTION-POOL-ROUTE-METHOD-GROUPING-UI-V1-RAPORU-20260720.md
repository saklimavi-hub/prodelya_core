# PRODELYA M13-B — Production Pool Route + Method Grouping UI v1 Report

Date: 2026-07-20
Status: READY — PRODUCTION POOL ROUTE AND METHOD GROUPING UI V1 — MANUAL SMOKE REQUIRED

## Scope Applied

This phase updated only the production pool/list surface and scoped UI support:

- `app/Http/Controllers/Admin/ProductionController.php`
- `resources/views/admin/productions/index.blade.php`
- `public/css/prodelya-admin.css`
- `tests/Feature/ProductionPoolRouteMethodGroupingTest.php`
- `docs/PRODELYA-M13-B-PRODUCTION-POOL-ROUTE-METHOD-GROUPING-UI-V1-RAPORU-20260720.md`
- `docs/PRODELYA-M13-A1-PRODUCTION-ROUTE-PRINT-METHOD-ARCHITECTURE-AUDIT-20260720.md` addendum

No schema, global CSS primitive, graphic detail/public/mail, procurement, Product Hub, pricing, route/menu, staging or commit work was performed.

## Query / Paginator

`ProductionController@index` now uses a tenant-scoped `OrderItemPrintProduction` DB query with real pagination.

- Canonical row: exact `order_item_print_productions` record.
- Tenant filter: `order_item_print_productions.tenant_account_id = current tenant`.
- Pagination: DB `paginate($perPage)->withQueryString()`.
- Per-page whitelist: `10`, `20`, `50`; invalid values fall back to `10`.
- Pagination labels in the production-scoped renderer: `Geri` / `İleri`.
- Filters preserved through query string: route, method, status, q, operator, subcontractor, graphic/procurement filters, per_page.

## Route Bucket Mapping

Route decisions are not derived from print method names.

- `internal`: `order_item_print_productions.production_type = internal`.
- `outsourced`: `production_type IN (external, outsourced)`.
- `supplier_printed`: intentionally empty-safe until a canonical classifier exists.
- `completed`: `production_status = tamamlandi` or `remaining_quantity <= 0`, excluding cancelled.
- `all`: all tenant production rows.

Compatibility fallback was added for historical test/legacy records with missing production route fields:

- If production `production_type` is null and print row route is null/internal legacy text, it can appear in the internal route.
- This fallback does not inspect UV/Laser/Pad/Hot-stamping method names.

Default `/admin/productions` now lands on `all` to preserve the previous operational smoke behavior while explicit route tabs remain canonical.

## Method Group Key

Within a route, rows are grouped by method in this order:

1. `order_item_prints.standard_print_type_id`
2. `order_item_prints.tenant_print_setting_id` / tenant setting display fallback
3. normalized legacy `order_item_prints.print_type`

Same visible method labels in different route buckets are not merged by route logic.

## Exact Production Row

Each row shows the exact print-production unit:

- print sequence such as `1a` / `1b` / `1c`
- order number and work form number
- customer
- product name and exact SKU/code
- print method and option
- graphic readiness and final graphic filename when available
- procurement readiness
- production status
- completed/planned/remaining quantity and progress
- one primary next-action CTA
- secondary links only for work form/detail and non-primary helper labels

Sensitive data is not included in the list payload or output:

- no price/cost/current-account balance
- no `subcontractor_cost`
- no `group_code` / `raw_mapping`
- no physical file path or public token

## Next-Action Mapping

The row next action is produced in the controller presenter using existing readiness/workflow signals.

- graphic not production-ready: `Grafiği Gör`
- procurement not ready: `Tedarik Durumunu Aç`
- internal pending and ready: `Üretimi Aç`
- internal in progress: `İşe Devam Et`
- partial internal: `Kalanı Tamamla`
- outsourced without company: `Fason Ata`
- outsourced pending with company: `Fasona Gönder`
- outsourced sent: `Fason Takip`
- returned/partial external: `Gelen İşi Kontrol Et`
- problematic: `Sorunu Aç`
- quality control: `Kalite Kontrolü Aç`
- completed/cancelled fallback: `Kaydı Aç`

`approved` graphic state is not treated as production-ready; readiness continues to use the existing resolver.

## Completed Archive

Completed rows are excluded from active internal/outsourced tabs and shown through `Tamamlananlar`.

Sort order:

- `completed_at DESC`
- fallback `updated_at DESC`

Completed rows use `Kaydı Aç` as the primary action.

## Supplier-Printed Empty-Safe

`Tedarikçiden Baskılı` remains visible but does not infer data from external/fason records.

Empty text:

> Henüz bu üretim yolu için ayrı bir canonical sınıflandırma bulunmuyor.

No fake supplier-printed data or enum/schema change was introduced.

## Tenant Default Alignment

SAKLImavi desired defaults from M13-A1:

- `UV_PRINT = internal`
- `LASER_PRINT = internal`
- `PAD_PRINT = outsourced`
- `HOT_STAMPING = outsourced`

Status: TENANT DEFAULT ALIGNMENT PENDING

Reason: this phase did not perform tenant setting mutation through browser/admin settings. Production rows were not rewritten, and no direct SQL was used. Alignment should be completed only through the existing tenant print-setting update UI/service path under the correct SAKLImavi tenant context.

## UI / CSS

Production pool now uses scoped UI v1 classes only:

- wrapper: `.pd-production-pool.pd-ui-v1-production`
- route tabs: `.pd-production-route-tabs`, `.pd-production-route-tab`
- method groups: `.pd-production-method-group*`
- exact jobs: `.pd-production-job-row*`
- summary: `.pd-production-summary`
- pagination: `.pd-production-pagination`

No global `body`, `:root`, `.pd-btn`, `.pd-card`, `.pd-summary`, `.pd-modal`, sidebar or input primitive was redefined.

## Tests

PASS:

- `php -l app\Http\Controllers\Admin\ProductionController.php`
- `php -l tests\Feature\ProductionPoolRouteMethodGroupingTest.php`
- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 tests, 33 assertions
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 tests, 144 assertions
- `php artisan test --filter=ProductionReadinessPerPrintGraphicTest --stop-on-failure` — 4 tests, 33 assertions
- `php artisan test --filter=TenantPrintSettingProductionModeIntegrationTest --stop-on-failure` — 4 tests, 42 assertions
- `php artisan test --filter=NoPrintOrderSkipsGraphicProductionTest --stop-on-failure` — 1 test, 23 assertions
- `php artisan test --filter=ProductionFinancePermissionTest --stop-on-failure` — 1 test, 7 assertions
- `php artisan test --filter=OperationsFastActionUxTest --stop-on-failure` — 3 tests, 46 assertions
- `php artisan test --filter=Procurement --stop-on-failure` — 131 tests, 1836 assertions
- `php artisan test --filter=Order --stop-on-failure` — 263 tests, 2371 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 tests, 214 assertions

Broad drift / not fixed in this phase:

- `php artisan test --filter=Production --stop-on-failure` reaches `ProductionEndToEndSmokeTest` and fails later on an order-detail label expectation: expected `İç Üretimde`, rendered order detail uses `Devam Ediyor`. This is outside the production pool index implementation scope.
- `php artisan test --filter=Graphic --stop-on-failure` reaches `GraphicWorkFolderEndToEndSmokeTest` and returns 404 on a graphic work-folder smoke route. Accepted graphic detail/public/mail/preview code was not touched in this phase.

## Manual Smoke Checklist

Manual smoke is still required for `/admin/productions`:

- `Tümü` default shows active tenant production rows without cost/path leaks.
- `İç Baskı` shows only internal route jobs.
- `Dış Baskı / Fason` shows external/outsourced route jobs.
- `Tedarikçiden Baskılı` shows empty-safe message.
- `Tamamlananlar` shows terminal jobs only.
- 10/20/50 per-page selector works and keeps filters.
- Pagination labels are `Geri` / `İleri`.
- Each exact row has one primary next-action CTA.
- Finance/cost data is not visible on the pool.

## Worktree / Staging / Commit

- Staging: none performed.
- Commit: none performed.
- Existing unrelated dirty worktree was preserved.

Final status:

READY — PRODUCTION POOL ROUTE AND METHOD GROUPING UI V1 — MANUAL SMOKE REQUIRED

---

## M13-B1 SAKLImavi Defaults And Acceptance Addendum — 2026-07-21

Final status: MANUAL PASS — PRODUCTION POOL ROUTE AND METHOD GROUPING UI V1

### Tenant Context And Alignment

SAKLImavi live tenant was resolved as:

- `tenant_accounts.id = 2`
- `panel_subdomain = saklimavi`
- `status = active`

The existing tenant-scoped print-setting update controller path was used. No raw SQL, unrestricted DB update, tinker mutation or historical production rewrite was performed.

Aligned defaults:

- `UV_PRINT = internal`
- `LASER_PRINT = internal`
- `PAD_PRINT = outsourced`
- `HOT_STAMPING = outsourced`

`default_subcontractor_company_id` values were preserved.

### Historical Record Safety

Before/after `order_item_print_productions` counts for tenant 2 remained unchanged:

- `NULL`: 1 -> 1
- `internal`: 21 -> 21
- `outsourced`: 4 -> 4

This confirms the tenant default alignment only affects future production creation defaults and did not rewrite existing production rows.

### Exact Override Proof

`SaklimaviPrintDefaultsAlignmentTest` verifies:

- exact internal print-row override still wins over outsourced tenant default
- exact outsourced/fason print-row override still wins over internal tenant default
- historical production rows remain unchanged after alignment through the route update path

### Production Pool Acceptance

Authenticated SAKLImavi production pool render checks passed for:

- `/admin/productions`
- `/admin/productions?route=internal`
- `/admin/productions?route=outsourced`
- `/admin/productions?route=supplier_printed`
- `/admin/productions?route=completed`

Observed live counts:

- total exact production records: 26
- active internal: 20
- active outsourced/external: 5
- completed archive: 4
- cancelled: 0
- null legacy type: 1

Acceptance results:

- `Tümü`: renders exact rows, Turkish pagination labels present, no finance/path leak
- `İç Baskı`: renders active internal rows through `production_type`
- `Dış Baskı / Fason`: renders active outsourced/external rows through `production_type`
- `Tedarikçiden Baskılı`: empty-safe message shown, no fake data
- `Tamamlananlar`: archive route renders terminal records
- pagination labels: `Geri` / `İleri`
- finance/path/token leak: none detected in rendered pool HTML

### Default Landing Decision

Decision: keep default landing as `Tümü`.

Reason: M13-B was previously adjusted to keep `/admin/productions` compatibility with existing operational smoke tests that expect mixed internal/external rows on the default landing. Switching the default to `İç Baskı` is a low-effort UI change, but it has regression risk while older smoke expectations still exercise the combined view. The safer closure is to keep `Tümü` now and revisit the default tab once production-detail label drift is handled in a separate phase.

### Tests

PASS:

- `php -l tests\Feature\SaklimaviPrintDefaultsAlignmentTest.php`
- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=SaklimaviPrintDefaults --stop-on-failure` — 3 tests, 27 assertions
- `php artisan test --filter=TenantPrintSettingProductionModeIntegrationTest --stop-on-failure` — 4 tests, 42 assertions
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 tests, 33 assertions
- `php artisan test --filter=ProductionReadinessPerPrintGraphicTest --stop-on-failure` — 4 tests, 33 assertions
- `php artisan test --filter=ProductionFinancePermissionTest --stop-on-failure` — 1 test, 7 assertions
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 tests, 144 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 tests, 214 assertions

Known broad drifts remain outside this phase:

- full `Production` broad still has an order-detail label drift outside production-pool index scope
- full `Graphic` broad still has the previously noted work-folder 404 drift outside accepted graphic detail/public/mail/preview scope

### Worktree / Staging / Commit

- Staging: none
- Commit: none
- Global CSS/schema/workflow/detail/graphic/public/mail files were not intentionally changed in M13-B1.

## M13-C2 Addendum — 2026-07-21

Production pool tarafında C2 sonrası broad drifts yeniden ölçüldü. Önceki rapordaki full Production/Graphic broad drift notları artık güncel değildir.

PASS:

- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 test / 33 assertion
- `php artisan test --filter=Production --stop-on-failure` — 139 test / 1989 assertion
- `php artisan test --filter=Graphic --stop-on-failure` — 114 test / 1568 assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 test / 214 assertion

Production pool route/method grouping davranışı korunmuştur; M13-D/operator sonrası fason mobil/public başlatılmadı. Staging/commit yapılmadı.
## M13-D Addendum — 2026-07-21

Dış Baskı / Fason Atama Akışı ayrı exact production route olarak eklendi: `admin.productions.subcontract-assignment`.

- Atama: readiness beklemeden, yalnız eligible tenant fason firmalarıyla yapılır.
- Gönderim: exact graphic + procurement readiness ve atanmış fason firma gerektirir.
- Current-account/fiyat/maliyet alanları assignment yüzeyinde yoktur; assignment cari hareket üretmez.
- Exact production row korunur; yeni production/print row oluşturulmaz.
- M13-D manual browser smoke PENDING.

Automated gates: Production, Order, CurrentAccount, AdminSmoke, target M13-D, view:cache PASS. WorkForm broad içinde iki out-of-scope eski tedarik label assertion drift'i kaldı.
## Addendum — 2026-07-21 M13-C4 Status Truth Consolidation
- Production pool/detail UI was consolidated in `docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md`.
- M13-C3 canonical route map was preserved; no M13-E2 mobile/public subcontract flow was started.
- Production detail now uses `Sıradaki İşlem`, compact status truth and no duplicate right sidebar summary.
