# LIVE-B1-M10-A Local Product Source Identity and Route Separation Report — 2026-07-17

## 1. Executive result
Status: RECOVERED — KENDİ ÜRÜNLERİM MENU LINK AND ROUTE CONTRACT — MANUAL RESMOKE REQUIRED

High-confidence result:
- Canonical source resolver now distinguishes `own_product`, `supplier_local_stock`, and `supplier_catalog`.
- `/admin/catalog/local-products` is now the canonical `Ürün Listem` surface.
- `/admin/catalog/local-products/supplier-stock` now filters by operational `tenant_local_stocks` existence instead of projection `local_stock_quantity` alone.
- Create and import screens are separated into dedicated GET surfaces without changing existing store/import business behavior.
- Legacy route compatibility is preserved through the existing `/admin/catalog/local-products` list route and legacy `?edit=` flow.
- Promotion Quote manual-pass workspace lock was preserved and reverified by tests.

## 2. Existing route compatibility
Verified current routes:

| İşlev | Route name | URI | Method |
|---|---|---|---|
| Ürün Listem | `admin.catalog.local-products` | `/admin/catalog/local-products` | `GET` |
| Store | `admin.catalog.local-products.store` | `/admin/catalog/local-products` | `POST` |
| Yeni Ürün Ekle | `admin.catalog.local-products.create` | `/admin/catalog/local-products/create` | `GET` |
| Dosyadan Ürün Aktar | `admin.catalog.local-products.import` | `/admin/catalog/local-products/import` | `GET` |
| Import preview | `admin.catalog.local-products.import.preview` | `/admin/catalog/local-products/import/preview` | `POST` |
| Import store | `admin.catalog.local-products.import.store` | `/admin/catalog/local-products/import` | `POST` |
| CSV şablonu | `admin.catalog.local-products.import.template` | `/admin/catalog/local-products/import/template` | `GET` |
| Tedarikçiden Stoğa Alınanlar | `admin.catalog.local-products.supplier-stock` | `/admin/catalog/local-products/supplier-stock` | `GET` |
| Update | `admin.catalog.local-products.update` | `/admin/catalog/local-products/{product}` | `PUT` |
| Destroy | `admin.catalog.local-products.destroy` | `/admin/catalog/local-products/{product}` | `DELETE` |
| Deactivate | `admin.catalog.local-products.deactivate` | `/admin/catalog/local-products/{product}/deactivate` | `POST` |

Compatibility result:
- Existing POST/PUT/DELETE route names were kept.
- Legacy list URI remains valid.
- Existing edit query compatibility remains available through `localProducts()` redirect/render delegation.

## 3. Canonical source resolver
Implemented service:
- `app/Services/TenantCatalog/TenantCatalogProductSourceResolver.php`

Canonical internal types:
- `own_product`
- `supplier_local_stock`
- `supplier_catalog`

Canonical user-facing badges:
- `Kendi Ürünüm`
- `Tedarikçiden Stoğa Alınan`
- `Tedarikçi Ürünü`

Classification contract:
- `own_product`: `catalog_source = local_product`
- `supplier_local_stock`: supplier identity exists and tenant-scoped operational `tenant_local_stocks.quantity_on_hand > 0`
- `supplier_catalog`: supplier catalog row without operational local stock

Important negative proof:
- Projection `tenant_catalog_products.local_stock_quantity > 0` alone no longer classifies a row as supplier-local.

## 4. Own product list
Implemented query/service:
- `app/Services/TenantCatalog/TenantLocalProductQueryService.php`
- `ownProductsForTenant()`

Implemented UI:
- `resources/views/admin/catalog/local-products-index.blade.php`
- `resources/views/admin/catalog/partials/_local-products-subnav.blade.php`

Result:
- `Ürün Listem` shows only `own_product` rows.
- Other-tenant local products remain excluded.
- Supplier-local rows do not leak into this surface.

## 5. Supplier local stock list
Implemented query/service:
- `supplierLocalStockProductsForTenant()`

Implemented UI:
- `resources/views/admin/catalog/local-products-supplier-stock.blade.php`

Operational columns exposed from ledger-backed query:
- eldeki
- rezerve
- kullanılabilir
- son stok hareketi

Result:
- Supplier-local list is driven by operational stock existence.
- Projection-only rows are excluded.
- Own products are excluded.
- Duplicate supplier rows are suppressed by canonical classification.

## 6. Create/import separation
Implemented surfaces:
- `resources/views/admin/catalog/local-products-create.blade.php`
- `resources/views/admin/catalog/local-products-import.blade.php`
- shared form partial: `resources/views/admin/catalog/partials/_local-product-form.blade.php`

Result:
- `Yeni Ürün Ekle` is a dedicated GET surface.
- `Dosyadan Ürün Aktar` is a dedicated GET surface.
- Existing store/import behavior was intentionally not changed.
- No CSV business rule, stock write helper, or import parser behavior was modified in this phase.

## 7. Operational stock filtering
Canonical filter proof:
- Supplier-local screen depends on tenant-scoped operational `tenant_local_stocks` existence and `quantity_on_hand > 0`.
- A projection-only local stock number no longer makes a supplier product appear in the supplier-local screen.

Support relation added:
- `TenantCatalogProduct::stockMovements()`

Operational presentation fields added for list rows:
- `operational_quantity_on_hand`
- `operational_quantity_reserved`
- `operational_quantity_available`
- `operational_local_stock_exists`
- `last_stock_movement_at`

## 8. Source badges
Single resolver contract now feeds badge semantics for this phase.

Current badge outputs:
- `Kendi Ürünüm`
- `Tedarikçiden Stoğa Alınan`
- `Tedarikçi Ürünü`

This is intentionally reusable for future quote/search surfaces, but quote workspace code was not touched here.

## 9. Tenant isolation
Validated contracts:
- tenant A own product does not render in tenant B own-product list
- foreign tenant operational stock does not cause current tenant supplier-local classification
- own/supplier-local filtering remains tenant-scoped
- duplicate visibility across tenants is blocked by tenant-aware query service

## 10. Duplicate prevention
Validated contracts:
- same business row is not rendered both as `supplier_catalog` and `supplier_local_stock`
- own product never appears inside supplier-local list
- projection-only local stock does not create a false supplier-local duplicate

## 11. Tests
Targeted/local tests:
- `TenantCatalogProductSourceResolverTest`: PASS
- `LocalProductsOwnProductListTest`: PASS
- `LocalProductsSupplierStockListTest`: PASS
- `LocalProductsOperationalStockFilterTest`: PASS
- `LocalProductsTenantIsolationTest`: PASS
- `LocalProductsLegacyRouteCompatibilityTest`: PASS
- `LocalProductsCsvImportVisibilityTest`: PASS
- `LocalProductsNoDuplicateSourceClassificationTest`: PASS
- `TenantAdvancedCatalogTest`: PASS after correcting an accidental test-route drift in the own-product visibility assertion

Menu/regression tests:
- `TenantProductCatalogMenuSimplificationTest`: PASS
- `AdminMenuServiceTest`: PASS

Regression locks / broad gates:
- `PromotionQuoteWorkspaceJavascriptContractTest`: PASS
- `PromotionQuoteCompactLocalStockLabelTest`: PASS
- `PromotionQuoteMetadataHydrationParityTest`: PASS
- broad `CatalogSearch`: PASS
- broad `Stock`: PASS
- broad `AdminSmoke`: PASS
- broad `LocalProducts`: PASS

## 12. Manual smoke
Status: REQUIRED / NOT RUN IN THIS TURN

Routes to verify manually:
- `/admin/catalog/local-products`
- `/admin/catalog/local-products/supplier-stock`
- `/admin/catalog/local-products/create`
- `/admin/catalog/local-products/import`

Checklist:
- `Ürün Listem` only own products
- `Tedarikçiden Stoğa Alınanlar` only operational supplier-local rows
- projection-only supplier rows absent from supplier-local screen
- create screen opens correctly
- import screen opens correctly
- subnavigation/menu order is correct
- no 404/405/500
- Promotion Quote accepted compact metadata remains unchanged

## 13. Worktree/staging/commit
This phase intentionally did not perform:
- migration
- DB write outside normal test database activity
- stock correction
- procurement/reservation behavior change
- staging
- commit

Staging state target: remains untouched.

## 14. Next phase
Recommended next step after manual smoke:
- continue with local product/inventory flow hardening without creating a second inventory system
- keep operational ledger truth in `tenant_local_stocks`
- keep projection stock as display/projection only
- do not reopen Promotion Quote compact metadata unless a separate regression appears

## 15. M10-A R1 Menu Link Recovery
Manual finding:
- `Kendi Ürünlerim` menu link was non-functional in the tenant sidebar.

Evidence and root cause:
- Direct route contract was valid: `admin.catalog.local-products` resolved and `/admin/catalog/local-products` was registered.
- The tenant sidebar rendered `Kendi Ürünlerim` as a second-level child inside `Ürün ve Katalog`.
- `catalog-local-products` was configured as an accordion parent without a direct canonical route on the item itself.
- `AdminMenuService` returned accordion children but did not assign `href` to accordion items.
- The tenant sidebar render path flattened section children and did not treat nested accordion children specially, so the parent behaved like a non-navigating label/collapse surface.

Fix:
- Added canonical parent route and active patterns to `catalog-local-products` in `config/admin_menu.php`.
- Updated `AdminMenuService` so accordion/group items can receive `href` and route-based active state when a valid route exists.
- Updated the tenant sidebar render path in `resources/views/layouts/prodelya-admin.blade.php` to render accordion children correctly and make the parent title navigable to `admin.catalog.local-products`.
- Preserved child links for:
  - `Ürün Listem`
  - `Tedarikçiden Stoğa Alınanlar`
  - `Yeni Ürün Ekle`
  - `Dosyadan Ürün Aktar`

Rendered/result proof after fix:
- `Kendi Ürünlerim` now renders with `href="http://localhost/admin/catalog/local-products"`.
- Nested child links render under the same parent in tenant sidebar HTML.
- Static routes `create`, `import`, `supplier-stock` continue to return 200 and are not swallowed by `/{product}`.

Tests:
- `LocalProductsMenuLinkTest`: PASS
- `LocalProductsMenuParentNavigationTest`: PASS
- `LocalProductsStaticRoutePrecedenceTest`: PASS
- `LocalProductsAuthorizedRouteSmokeTest`: PASS
- `TenantProductCatalogMenuSimplificationTest`: PASS
- `AdminMenuServiceTest`: PASS
- broad `LocalProducts`: PASS
- `AdminSmokeTest`: PASS
- `PromotionQuoteWorkspaceJavascriptContractTest`: PASS
- `PromotionQuoteCompactLocalStockLabelTest`: PASS

Manual smoke:
- Initial manual smoke: FAIL on `Kendi Ürünlerim` link
- Recovery after code/test: IMPLEMENTED
- Current closeout state: MANUAL RESMOKE REQUIRED

## 2026-07-17 Template-A Addendum
- Local Product production template adaptation completed on top of the M10-A source/route separation contract.
- Route/menu/source identity contract remained unchanged while own list, supplier stock, create/edit and import surfaces were visually upgraded.
- Manual smoke remains required before any staging or commit.


## 2026-07-17 Addendum — Exact Variant Supplier-Local Recovery
- Supplier-local list artık product aggregation yerine exact `tenant_local_stocks` rows kullanıyor.
- Varianted product + legacy product-scope stock normal listeden hariç; sade warning summary gösteriliyor.
- Exact variant detail route bağlandı: `/admin/catalog/{product}/variants/{variant}`.
- ET-0506 live audit: operational truth hâlâ tek product-scope 2000 row; auto correction yapılmadı.
- Promotion Quote MANUAL PASS regression lock korunuyor.
