# PRODELYA M13-N2.1 Exact Selective Snapshot Verification Report - 2026-07-23

Status: BLOCKED - SELECTIVE SNAPSHOT DOES NOT REPRODUCE FULL-SUITE PASS

## Main Repo Safety

- Main repo: `C:\laragon\www\prodelya_core`
- Main HEAD: `c7f2a80`
- Main staged area: empty at start and final verification
- Main staging/commit/tag/reset/restore/stash/clean: not used
- Dirty worktree: preserved

## Temp Clone

- Path: `C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_20260723`
- Base: clean clone from `c7f2a80`
- Whole dirty worktree copy: not used
- Runtime dependency: `vendor` junction only
- Isolated runtime dirs created under temp `storage` and `bootstrap/cache`

## Applied Scope

- N1 A files copied path-by-path: 50
- N1 B patch files applied or represented: 8
- N2 C whole-file dependencies: 6
- Snapshot status count: 61
- Tracked changed count: 39
- Untracked manifest count: 22
- Previous 609-file blanket snapshot was not reproduced.

## B Patch Artifacts

Patch directory:

```text
.tmp/pre-m14-selective-patches
```

Applied cleanly with `git apply --check` + `git apply`:

- `admin-menu-currency-permission.patch`
- `graphic-module-history-label.patch`
- `customer-portal-order-visible-files.patch`
- `public-quote-approval-copy-labels.patch`
- `tenant-advanced-catalog-m13-m4.patch`
- `tenant-advanced-catalog-supplier-access.patch`

Regenerated from actual temp diff after correcting line-ending/hunk-count issues:

- `tenant-advanced-catalog-applied.patch`

Not applied:

- `tenant-advanced-catalog-local-stock-fixtures.patch` was malformed and superseded by `tenant-advanced-catalog-applied.patch`.

## C Whole-File Dependency Checksums

| File | State | Lines | SHA256 |
|---|---:|---:|---|
| `resources/views/public/graphics/approval/show.blade.php` | tracked | 593 | `8BC1870FA9F693F5CB44FE914D807B158067D0021EA8E3056F42D0FA70F15F15` |
| `app/Http/Controllers/Admin/LocalProductController.php` | untracked | 137 | `E2B350EA0C978BE07468586BD6317B5C43FF421559094C178F79875CC7A341B5` |
| `app/Services/TenantCatalog/TenantLocalProductWriteService.php` | untracked | 882 | `0A0D818E52318A3D887DA06B240FB99F1D352F36845EB5F8736A582822A9BD79` |
| `resources/views/admin/catalog/local-products-index.blade.php` | untracked | 234 | `572CE4EBD709378D96CC8450943A8A650BEE6F3FBE7C4AA7E09978FEF9BD0939` |
| `app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php` | tracked | 1902 | `069326B1D29E3CE82F22CEF8EBAA868E215DE826E756D24C62203A40CBD1EF6C` |
| `resources/views/super-admin/product-data-hub/sources/index.blade.php` | tracked | 400 | `DE232CEB1241FE39454802B191172A51B297F3565C379EAF175D9773F159EDE9` |

## Targeted Gate Results

Passed:

```text
php artisan test --filter=ProcurementDraftPriceRefreshTest --stop-on-failure
PASS, 4 tests, 33 assertions
```

Failed:

```text
php artisan test --filter=TenantAdvancedCatalogTest --stop-on-failure
FAIL
Tests\Feature\TenantAdvancedCatalogTest::test_supplier_local_stock_product_is_visible_on_local_products_and_search
Expected page text: Tedarikçiden Local Stoğa Alınan Ürünler
Actual exact selective snapshot renders: Ürün Listem
```

Reason:

The exact selective manifest excludes `routes/web.php` and the broader local-products supplier-stock route/view changes. The N1 B instructions also reject older local stock/list route fixture changes. Under those rules, the copied C local-products view is present, but the exact route behavior expected by the dirty-tree test is not reproducible.

## Full Suite

Not run.

Reason: targeted gate failed. Per prompt, checkpoint execution cannot be approved without targeted pass and `2213/2213` full-suite pass on the exact selective snapshot.

## Excluded Files Confirmed

Intentionally not copied as broad whole-file dependencies:

- `routes/web.php`
- `public/css/prodelya-admin.css`
- `.env*`
- `node_modules`
- full dirty worktree
- `.tmp/*.xml`
- manifest-external dirty/untracked files

## Final Decision

```text
BLOCKED — SELECTIVE SNAPSHOT DOES NOT REPRODUCE FULL-SUITE PASS
```

Checkpoint execution is not approved from exact selective snapshot verification.

## Execution Manifest If Scope Is Reopened

Current direct-stage candidate remains N1 A + six N2 C whole files + reports.

Interactive B staging must remain narrow, but the blocker shows the manifest likely needs a new explicit decision for the supplier-stock local-products route/view/test surface before a valid Pre-M14 checkpoint can be proven.

Commit message, only after a future exact snapshot passes:

```text
checkpoint: pre-m14 full-suite cleanup
```
