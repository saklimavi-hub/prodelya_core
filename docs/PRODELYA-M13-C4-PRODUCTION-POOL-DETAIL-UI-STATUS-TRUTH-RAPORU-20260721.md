# PRODELYA M13-C4 — Production Pool / Detail UI Status Truth Report

Date: 2026-07-21
Status: READY — PRODUCTION POOL AND DETAIL UI CONSOLIDATION WITH STATUS TRUTH — MANUAL SMOKE REQUIRED

## Scope
- M13-E2 was not started.
- Production pool and production detail presentation were consolidated only in the production family surfaces.
- No schema, migration, global CSS, staging or commit was performed.
- M13-C3 canonical routes were preserved: internal operator, outsourced assignment/tracking, terminal show.

## Files Touched
- app/Http/Controllers/Admin/ProductionController.php
- resources/views/admin/productions/index.blade.php
- resources/views/admin/productions/show.blade.php
- resources/views/admin/productions/operator.blade.php
- resources/views/admin/productions/subcontract-assignment.blade.php
- resources/views/admin/productions/subcontract-tracking.blade.php
- public/css/prodelya-admin.css
- tests/Feature/ProductionPoolDetailUiStatusTruthTest.php
- Existing production UI tests were updated for the C4 compact surface and stale tab/right-sidebar terminology.

## Truth Source Audit
| Truth | Source Used | C4 Decision |
| --- | --- | --- |
| production status | OrderItemPrintProduction production_status + live workflow labels | Terminal states override stale waiting labels. |
| planned/completed/remaining | OrderItemPrintProduction planned_quantity/completed_quantity/remaining_quantity | Completed forces remaining 0 and progress 100%. |
| graphic readiness | ProductionReadinessResolver exact graphic relation | Product/sibling image is not used as graphic truth. |
| procurement readiness | ProductionReadinessResolver exact procurement relation | Shows Tedarik Tamamlandı/Bekliyor/Gerekli Değil from live truth. |
| QC required | Existing ProductionController::productionQcUiEnabled + explicit QC state/trace | Disabled/not-required QC is hidden from process bar and shown as Kalite kontrol gerekli değil in summary. |
| QC status | OrderItemPrintProduction qc_status/qc_started_at | QC Bekliyor is only possible when a real QC state is present. |
| delivery-ready | Completed production + QC passed/not required + no unresolved issue | Means ready for delivery handoff, not delivered. |
| operator/fason assignment | Existing production_type/operator/subcontractor fields | Display-only; no assignment semantics changed. |
| next action | Existing canonical route resolver + live readiness blockers | Single visible Sıradaki İşlem CTA. |

## UI Changes
- Production-only top tenant/page hero is hidden on production list/detail/operator/subcontract pages.
- `/admin/productions` now uses one compact full-width pool surface: header, metrics, route tabs, Havuz Özeti strip, filters, method groups and pagination.
- Separate right `Üretim / Fason` and `Havuz Özeti` cards were removed.
- `/admin/productions/{id}` now uses compact exact header, quantity/status strip, conditional process bar, compact summary, one next action and collapsed technical/photo/history sections.
- User-facing `Canonical Akış` was replaced with `Sıradaki İşlem`.
- Right sidebar duplicate summary/status/tracking blocks were removed.

## Status Truth Fixes
- Completed production no longer renders `Üretimde — Bekliyor` or waiting production labels.
- Completed production displays `Tamamlandı`, `Kalan 0`, `%100`, and `Teslimata Hazır` when eligible.
- QC not-required/suspended no longer appears as `Kalite Kontrol → Bekliyor`.
- Live exact graphic/procurement readiness is used for labels instead of stale snapshot labels.

## Verification
- `php -l app\Http\Controllers\Admin\ProductionController.php`: PASS
- `php artisan view:clear`: PASS
- `php artisan view:cache`: PASS
- `php artisan test --filter=ProductionPoolDetailUiStatusTruthTest --stop-on-failure`: PASS, 4 tests / 21 assertions
- `php artisan test --filter=ProductionPool --stop-on-failure`: PASS, 10 tests / 67 assertions
- `php artisan test --filter=ProductionCanonicalRouteResolverTest --stop-on-failure`: PASS
- `php artisan test --filter=ProductionLegacyRouteCleanupTest --stop-on-failure`: PASS
- `php artisan test --filter=PrintSetupRequirementProductionReadinessTest --stop-on-failure`: PASS
- `php artisan test --filter=Production --stop-on-failure`: PASS, 163 tests / 2258 assertions
- `php artisan test --filter=WorkFormAttachment --stop-on-failure`: PASS, 5 tests / 51 assertions
- `php artisan test --filter=AdminSmoke --stop-on-failure`: PASS, 59 tests / 214 assertions
- `php artisan test --filter=ProductionDetail --stop-on-failure`: NO MATCHING TESTS
- `php artisan test --filter=ProductionStatus --stop-on-failure`: NO MATCHING TESTS
- `php artisan test --filter=ProductionQc --stop-on-failure`: NO MATCHING TESTS

## Git Safety
- `git diff --cached --name-only`: empty output, staged area is clean.
- Worktree contains many unrelated dirty/untracked files from previous phases; they were not staged, reverted or cleaned.

## Manual Smoke Checklist
Pending user browser smoke:
- Pool: top tenant hero absent, no right summary card, single Havuz Özeti strip, list full-width, filters/tabs/pagination work.
- Completed detail: Tamamlandı, planned equals completed, Kalan 0, %100, no Üretimde Bekliyor, QC hidden/Gerekli Değil, Teslimata Hazır.
- Active internal: Grafik / Tedarik / İç Baskı / Teslimata Hazır sequence with one next action.
- Sent outsourced: Grafik / Tedarik / Fason Atama / Fasona Gönderildi / Fasondan Geldi sequence with one next action.

## Final State
READY — PRODUCTION POOL AND DETAIL UI CONSOLIDATION WITH STATUS TRUTH — MANUAL SMOKE REQUIRED

## M13-F Link - 2026-07-22
- Work Form production/subcontract alignment now consumes M13-C4 status truth through exact production row projection.
- QC waiting is displayed only when the production row is actually in quality control; otherwise Work Form projection uses Kalite Kontrol Gerekli Değil.
- See docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md.
