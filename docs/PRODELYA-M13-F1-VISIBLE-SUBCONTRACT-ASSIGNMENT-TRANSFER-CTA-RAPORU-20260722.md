# PRODELYA M13-F1 Visible Subcontract Assignment / Transfer CTA Report

Date: 2026-07-22
Status: READY - VISIBLE SUBCONTRACT ASSIGNMENT AND INTERNAL TO OUTSOURCED TRANSFER CTA - MANUAL SMOKE REQUIRED

## Scope

Implemented the M13-F1 hotfix for visible subcontract assignment and internal-to-outsourced transfer actions.

## Changes

- `resources/views/admin/productions/subcontract-assignment.blade.php`
  - Removed the disconnected top `Fasona Ata` submit button.
  - Added the in-form decision/action bar below company selection with selected-company summary and a single `Seçilen Firmaya Ata` submit button.
  - Button is disabled until a company is selected; company rows remain full-row clickable labels.
  - Form payload stays on canonical `admin.productions.update-assignment` and posts `production_type=outsourced`, company, cliche fields, and `return_to=subcontract_assignment`.

- `resources/views/admin/productions/index.blade.php`
  - Added `Fasona Devret` as a secondary row action for normalized internal productions with remaining quantity and non-terminal status.
  - Link targets `admin.productions.operator` with `#route-transfer-panel`.

- `resources/views/admin/productions/operator.blade.php`
  - Added visible `Fasona Devret` trigger for eligible internal jobs.
  - Added compact same-page transfer panel with `Fason Firma`, `Değişiklik Gerekçesi`, Planlanan/Tamamlanan/Fasona Gidecek metrics, and `[Fasona Devret] [Vazgeç]` actions.
  - Form uses existing `admin.productions.update-assignment` route with required payload: `production_type=outsourced`, `production_company_id`, `route_change_reason`, `return_to=subcontract_assignment`.

- `resources/views/admin/productions/show.blade.php`
  - Added read-only secondary `Fasona Devret` link to the operator transfer panel for eligible internal jobs.

- `app/Http/Controllers/Admin/ProductionController.php`
  - Passed eligible tenant subcontract companies into the operator view.
  - Existing canonical update-assignment validation and eligible-company guard remain in use.

- Tests updated in:
  - `tests/Feature/InternalOperatorFlowTest.php`
  - `tests/Feature/ProductionSubcontractorAssignmentFlowTest.php`
  - `tests/Feature/CompanySubcontractorPrintRoleUxTest.php`

## Guardrails

- No new endpoint.
- No new production row for transfer.
- No schema or migration change.
- `ProductionWorkflowService` quantity semantics were not changed.
- Transfer preserves production id, exact print id, planned/completed/remaining quantities, work form linkage, graphics/photos/history surfaces.
- No current-account transaction is created by the transfer test path.

## Verification

- `php -l app/Http/Controllers/Admin/ProductionController.php` - passed
- `php -l tests/Feature/InternalOperatorFlowTest.php` - passed
- `php -l tests/Feature/ProductionSubcontractorAssignmentFlowTest.php` - passed
- `php artisan view:clear` - passed
- `php artisan view:cache` - passed
- `php artisan test --filter=SubcontractAssignment --stop-on-failure` - no tests found in current suite
- `php artisan test --filter=InternalOperator --stop-on-failure` - passed, 10 tests / 108 assertions
- `php artisan test --filter=ProductionLegacyRouteCleanupTest --stop-on-failure` - passed, 6 tests / 43 assertions
- `php artisan test --filter=ProductionCanonicalRouteResolverTest --stop-on-failure` - passed, 1 test / 6 assertions
- `php artisan test --filter=ProductionSubcontractorAssignmentFlowTest --stop-on-failure` - passed, 9 tests / 78 assertions
- `php artisan test --filter=Production --stop-on-failure` - passed, 165 tests / 2273 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` - passed, 59 tests / 214 assertions

## Manual Smoke

Manual smoke is still required:

1. Open `/admin/productions/{production}/subcontract-assignment` for an unassigned outsourced production.
2. Confirm there is no top disconnected `Fasona Ata` submit button.
3. Select a firm row and confirm `Seçilen Firmaya Ata` becomes enabled in the same decision area.
4. Open `/admin/productions?route=internal` and confirm eligible internal rows show `Fasona Devret`.
5. Open `/admin/productions/{production}/operator#route-transfer-panel`, submit a valid transfer, and confirm redirect to subcontract assignment.
6. Confirm completed/cancelled/zero-remaining internal jobs do not show the transfer CTA.
