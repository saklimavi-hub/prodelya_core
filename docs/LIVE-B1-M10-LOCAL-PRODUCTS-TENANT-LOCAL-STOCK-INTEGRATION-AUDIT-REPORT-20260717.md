# LIVE-B1-M10 Local Products + Tenant Local Stock Integration Audit Report — 2026-07-17

## 1. Executive result
Status: PARTIAL / CURRENT STATE UPDATED — M10 audit remains valid, and M10-A source/route separation is now implemented

Accepted closeout for the original audit:
- AUDITED — LOCAL PRODUCTS AND TENANT LOCAL STOCK INTEGRATION MAP READY

Current-state addendum after M10-A:
- IMPLEMENTED — LOCAL PRODUCT SOURCE IDENTITY AND SEPARATED ADMIN ROUTES — MANUAL SMOKE REQUIRED
- Quote workspace regression lock remains intact and manually accepted.
- No second inventory system was introduced.
- Operational truth remains `tenant_local_stocks` + `tenant_stock_reservations` + `stock_movements`.
- The specific M10-A gap around mixed own-product / supplier-local UI and projection-based supplier-local classification is now closed.
- Deeper M10 inventory-flow gaps still remain for later phases: create/import/manual stock flows are not yet fully converged onto exact variant-aware operational writes.

## 2. Accepted quote UX regression lock
Regression lock status:
- MANUAL PASS — accepted compact metadata and workspace behavior

Accepted compact metadata sample:
```text
Etkin Promosyon · SKU: ET-0506-MV · Local stok: 1.000 · Tedarikçi stok: 27.800 · Güncellendi: 29.06.2026 06:46
```

Accepted workspace behaviors:
- müşteri arama çalışıyor
- Ürün Ekle çalışıyor
- çoklu ürün satırı çalışıyor
- baskı satırı çalışıyor
- fiyat/toplam hesaplaması çalışıyor
- save/edit hydration çalışıyor
- selected-row metadata kabul edilebilir

Regression gates preserved during M10-A:
- `PromotionQuoteWorkspaceJavascriptContractTest`: PASS
- `PromotionQuoteCompactLocalStockLabelTest`: PASS
- `PromotionQuoteMetadataHydrationParityTest`: PASS

Canonical lock statement:
- VERIFIED — PROMOTION QUOTE CUSTOMER, PRODUCT WORKSPACE AND COMPACT STOCK METADATA — LIVE-B1 UX GATE OPEN

## 3. Current routes and screens
### User-facing route/screen map
| Kullanıcı yüzeyi | Route | Controller | Service | Model/Table |
|---|---|---|---|---|
| Ürün Listem | `GET /admin/catalog/local-products` | `TenantCatalogController@localProducts` | `TenantLocalProductQueryService::ownProductsForTenant()` | `tenant_catalog_products` |
| Tedarikçiden Stoğa Alınanlar | `GET /admin/catalog/local-products/supplier-stock` | `TenantCatalogController@localProductsSupplierStock` | `TenantLocalProductQueryService::supplierLocalStockProductsForTenant()` | `tenant_catalog_products`, `tenant_local_stocks` |
| Yeni Ürün Ekle | `GET /admin/catalog/local-products/create` | `TenantCatalogController@localProductsCreate` | existing create/edit payload flow | `tenant_catalog_products` |
| Ürün Oluştur | `POST /admin/catalog/local-products` | `TenantCatalogController@storeLocalProduct` | `buildLocalProductPayload()`, `syncLocalStockRecord()` | `tenant_catalog_products`, `tenant_local_stocks` |
| Ürün Düzenle | `PUT /admin/catalog/local-products/{product}` | `TenantCatalogController@updateLocalProduct` | `buildLocalProductPayload()`, `syncLocalStockRecord()` | `tenant_catalog_products`, `tenant_local_stocks` |
| Ürün Pasifleştir | `POST /admin/catalog/local-products/{product}/deactivate` | `TenantCatalogController@deactivateLocalProduct` | controller only | `tenant_catalog_products` |
| Ürün Sil / Arşivle | `DELETE /admin/catalog/local-products/{product}` | `TenantCatalogController@destroyLocalProduct` | controller only | `tenant_catalog_products`, historical `order_items` check |
| Dosyadan Ürün Aktar ekranı | `GET /admin/catalog/local-products/import` | `TenantCatalogController@localProductsImport` | controller only | session preview |
| CSV şablonu indir | `GET /admin/catalog/local-products/import/template` | `TenantCatalogController@localProductsImportTemplate` | controller only | none |
| Import preview | `POST /admin/catalog/local-products/import/preview` | `TenantCatalogController@previewLocalProductsImport` | CSV parser in controller | session preview |
| Import store | `POST /admin/catalog/local-products/import` | `TenantCatalogController@storeLocalProductsImport` | `buildLocalProductPayload()`, `syncLocalStockRecord()` | `tenant_catalog_products`, `tenant_local_stocks` |
| Manuel stok sayı güncelle | `POST /admin/catalog/{product}/local-stock` | `TenantCatalogController@updateLocalStock` | `syncLocalStockRecord()` | `tenant_catalog_products`, `tenant_local_stocks` |
| Stok girişi / supplier-to-local | `POST /admin/catalog/{product}/local-stock-entry` | `TenantCatalogController@storeLocalStockEntry` | `resolveSellableVariantForLocalStock()`, `ensureSellableForLocalStock()`, `applyLocalStockEntry()` | `tenant_catalog_products`, `tenant_catalog_product_variants`, `tenant_local_stocks`, `tenant_supplier_purchase_entries`, `stock_movements` |
| Quote search DTO | `GET /admin/catalog/search` | `CatalogSearchController@search` | `ProductHubSellableTruthService`, `TenantLocalStockPresentationService`, currency/freshness services | `tenant_catalog_products`, `tenant_catalog_product_variants`, `tenant_local_stocks` |

### Important absences
- No dedicated `stock_movements` admin screen/route was found in `routes/web.php`, `app/Http/Controllers`, or `resources/views/admin`.
- No dedicated tenant own-product XML import route was found.
- No dedicated local-product variant create/edit screen was found.

### Exact function of `/admin/catalog/local-products`
As of M10-A, this route is now the canonical `Ürün Listem` surface.

Legacy compatibility retained:
- existing POST store route remains on the same URI
- existing edit compatibility is preserved via `?edit=` path handling inside `localProducts()`

The previous mixed own-product + supplier-local screen has been separated into dedicated list surfaces.

## 4. Product identity map
### A — Tenant’ın manuel oluşturduğu kendi ürünü
Observed contract:
- product card is created in `tenant_catalog_products`
- `standard_product_id = null`
- `catalog_source = local_product`
- `tenant_sku` is generated from tenant + product code if missing
- supplier identity yok
- no separate supplier identity chain
- no variant identity creation path exists in current form

Identity chain:
- `tenant_catalog_product_id`: yes
- `tenant_catalog_product_variant_id`: no
- `standard_product_id`: null
- `standard_product_variant_id`: null
- supplier identity: no
- tenant-owned identity: yes
- product code / SKU: yes

### B — Tedarikçiden local stoğa alınan ürün
Observed contract:
- original tenant catalog product identity is preserved
- supplier identity remains in catalog source metadata
- optional exact variant can be selected for stock entry
- variant row stock snapshot can be updated
- product row stock snapshot can be updated
- operational `tenant_local_stocks` write still ultimately depends on existing stock-sync helpers outside M10-A scope

Identity chain:
- `tenant_catalog_product_id`: preserved
- `tenant_catalog_product_variant_id`: optional in entry flow
- `standard_product_id`: preserved if product already has one
- `standard_product_variant_id`: preserved only through existing catalog variant identity
- supplier identity: preserved
- tenant-owned identity: no
- product code / SKU: preserved

### C — CSV ile tenant’ın kendi ürünü
Observed contract:
- preview exists, stored in session
- duplicate key is tenant + `product_code` + `catalog_source=local_product`
- import creates/updates `tenant_catalog_products`
- no real variant import mapping exists
- stock column still writes projection/product fields and operational `tenant_local_stocks` through existing helpers

Identity chain:
- `tenant_catalog_product_id`: yes
- `tenant_catalog_product_variant_id`: no
- `standard_product_id`: null by design
- supplier identity: no
- product code / SKU: yes

### D — Product Hub’dan yalnız teklif edilen ürün
Observed contract:
- remains supplier/global catalog projection unless local-stock entry is used
- quote search treats it as sellable DTO if truth service allows it
- local stock can later be introduced through `/admin/catalog/{product}/local-stock-entry`

## 5. Product source types
Current canonical source labels in code/UI:
- `Kendi Ürünüm`
- `Tedarikçiden Stoğa Alınan`
- `Tedarikçi Ürünü`

Audit result after M10-A:
- PASS: source typing is now backed by `TenantCatalogProductSourceResolver`
- PASS: own-product and supplier-local list separation is explicit in route/controller/view structure
- PASS: supplier-local classification no longer depends on projection `local_stock_quantity > 0` alone
- PASS: duplicate rendering between own-product and supplier-local list is covered by tests
- REMAINING: quote/search/UI surfaces beyond this phase were intentionally not rewired to the new resolver

## 6. TenantLocalStock integration
### Operational truth today
Canonical operational table:
- `tenant_local_stocks`

Current resolver:
- `TenantLocalStockResolver`

Reservation layer:
- `tenant_stock_reservations`
- `TenantStockReservationService`

Presentation adapter:
- `TenantLocalStockPresentationService`

### Flow map
| Akış | Local stock row create | Variant scope | quantity_on_hand | reserved | available | movement |
|---|---|---|---:|---:|---:|---|
| Manuel kendi ürün | yes via `syncLocalStockRecord()` | product only today | direct overwrite from form stock | `0` | mirrors on-hand | no |
| Tedarikçiden stoğa alma | yes/update through current stock entry flow | controller updates variant projection fields too | increments | unchanged | recomputed | yes, `stock_movements` create |
| CSV import | yes via `syncLocalStockRecord()` | product only | direct overwrite/import value | `0` | mirrors on-hand | no |
| Manuel stok sayı güncelle | yes/update via `syncLocalStockRecord()` | product only | direct overwrite | unchanged/usually `0` | mirrors on-hand | no |
| Sipariş rezervasyonu | no new row; existing row used | exact resolver if variant row exists | unchanged | increases | decreases | no |
| Sipariş iptali | no new row; active reservations released | exact row if reservation exists | unchanged | decreases | increases | no |
| Fiziksel stok tüketimi | not found in current audited flow | unresolved | unresolved | unresolved | unresolved | not found |

### Key finding
M10-A did not alter stock-write semantics.

Current state remains:
- reservation/procurement truth is exact-aware
- local product/manual/import flows still mostly rely on product-level helper paths
- supplier-local list filtering now uses operational existence correctly, but create/import/manual write convergence is still future work

## 7. Exact variant scope
Canonical M8 contract:
- varyantsız ürün: `tenant_catalog_product_variant_id = null`, `stock_scope = product`
- varyantlı ürün: `tenant_catalog_product_variant_id = exact variant id`, `stock_scope = variant`

What is implemented:
- `tenant_local_stocks` model + migration support exact variant scope
- `TenantLocalStockResolver` refuses sibling auto-allocation and returns:
  - `exact_variant_stock_found`
  - `ambiguous_product_level_stock`
  - `variant_stock_missing`
- reservation tests prove:
  - exact variant reservation works
  - product-level legacy stock is intentionally ambiguous
  - shortfall procurement uses only missing quantity

What is missing/partial:
- manual local product create/edit has no variant UI or variant row creation path
- CSV import has no variant mapping
- `syncLocalStockRecord()` is not exact-variant aware
- supplier-to-local entry hardening beyond this phase remains future work

Conclusion:
- PASS: resolver and reservation rules
- PARTIAL: supplier-to-local exact-write hardening
- MISSING: local product variant create/edit/import exact stock integration

## 8. StockMovement integration
### What exists
`stock_movements` creation in audited code was found only in:
- `TenantCatalogController@applyLocalStockEntry()`

Current write behavior there:
- creates movement for supplier purchase or existing stock intake
- `movement_type = in`
- `reason = purchase`
- quantity/currency/reference document saved

### Reservation vs movement separation
Current behavior remains directionally correct:
- reservation uses `tenant_stock_reservations`
- reservation changes `quantity_reserved` and `quantity_available`
- reservation does not create `stock_movements`
- order cancellation releases reservation

Missing:
- no audited physical stock consumption flow from order fulfillment that consumes reservation and writes outbound movement
- no audited return/adjustment lifecycle tied to reservation consume/release

## 9. Supplier receipt behavior
Audit scope result remains unchanged:
- procurement request creation and reservation shortfall are separate from physical stock intake
- no evidence in the audited order/procurement admission path that received quantity is automatically written into `tenant_local_stocks`
- supplier-to-local intake currently exists as an explicit catalog-side manual stock-entry action, not as a fully audited procurement receipt lifecycle

## 10. Manual product create/edit
Current form state after M10-A:
- dedicated create/edit GET surface exists
- stock field behavior is intentionally unchanged
- product card and inventory movement are still not separated at behavior level
- no variant create/edit flow was added

Audit conclusion:
- UI separation: PASS
- inventory-flow separation: NOT YET

## 11. File import
Current state after M10-A:
- CSV only
- preview exists
- dedicated import screen exists
- duplicate SKU policy remains unchanged
- no variant mapping
- no XML own-product import route
- no stock movement import separation

Audit conclusion:
- PASS: separate import surface
- PASS: existing CSV business behavior preserved
- MISSING: variant import and movement-based opening stock split

## 12. Quote search DTO
What quote search currently unifies:
- tenant own products
- supplier products
- supplier products with local stock
- exact variant rows when visible variants exist

Important behavior:
- `TenantLocalStockPresentationService` still tries operational exact stock first
- quote UI may still fall back to projection display values when operational exact is unavailable

Audit conclusion after M10-A:
- PASS: quote regression lock preserved
- PASS: M10-A did not regress compact metadata contract
- OUT OF SCOPE: quote/search surfaces were not migrated onto the new source resolver in this phase

## 13. Tenant isolation
Evidence found:
- local product list is tenant-scoped
- supplier-local list is tenant-scoped
- create/update/delete routes operate within current tenant
- quote search is tenant-scoped
- reservation lookup is tenant-scoped
- tests cover that another tenant’s local products and stock do not leak into current tenant screens

Isolation conclusion:
- PASS: tenant cannot see another tenant’s local products through audited UI
- PASS: foreign tenant operational stock does not trigger supplier-local classification
- PASS: core tenant scoping is present

## 14. Legacy stock
Canonical rule from M8 remains:
- product-level stock for a varianted product is ambiguous
- no auto-distribution to sibling variants

Legacy status:
- PASS: ambiguity is preserved at reservation/procurement truth layer
- PARTIAL: create/import/manual flows can still generate product-level stock rows by default

## 15. Gap matrix
| Başlık | Sonuç | Not |
|---|---|---|
| local product card | PASS | tenant catalog card exists and is isolated |
| own-product list route | PASS | dedicated list screen now exists |
| supplier-local list route | PASS | dedicated screen now exists |
| source resolver | PASS | canonical resolver added |
| projection-only supplier-local classification | PASS | blocked by operational filter |
| create surface separation | PASS | dedicated GET screen added |
| import surface separation | PASS | dedicated GET screen added |
| manual create inventory semantics | PARTIAL | still writes card + stock directly |
| variant create | MISSING | no local product variant create/edit surface |
| exact stock row write | PARTIAL | resolver/reservation yes, create/import/manual mostly no |
| opening movement | MISSING | initial stock is still not a dedicated opening balance movement |
| stock adjustment | MISSING | no dedicated adjustment flow found |
| supplier-to-local exact hardening | PARTIAL | list classification fixed; deeper write hardening remains |
| file import | PARTIAL | CSV flat import exists; no XML own-product route, no variant mapping |
| quote DTO | PARTIAL | shared DTO exists, but projection and operational truth are still mixed |
| source badges | PASS | single resolver-backed badge contract exists for this phase |
| duplicate prevention | PASS | supplier-local vs own-product duplication covered by tests |
| reservation | PASS | exact variant reservation + shortfall procurement work |
| movement | PARTIAL | intake movement exists; full lifecycle still unresolved |
| cancellation release | PASS | active reservations release on cancellation |
| consumption | MISSING | outbound stock consumption path not proven in audited code |
| tenant isolation | PASS | core tenant scoping is present |
| legacy product-level stock | PASS | ambiguity rule enforced at resolver layer |

## 16. Recommended implementation phases
- M10-A — Local product identity and route cleanup
  - COMPLETE in code/tests, manual smoke pending
- M10-B — Manual product create/edit exact variant integration
- M10-C — Opening stock movement and adjustment flow
- M10-D — Supplier product → local stock intake hardening
- M10-E — CSV/XML own-product import
- M10-F — Unified quote DTO/search source provenance
- M10-G — Reservation/movement lifecycle smoke
- M10-H — Legacy stock migration policy

## 17. Test inventory
Targeted/new phase tests:
- `TenantCatalogProductSourceResolverTest`: PASS
- `LocalProductsOwnProductListTest`: PASS
- `LocalProductsSupplierStockListTest`: PASS
- `LocalProductsOperationalStockFilterTest`: PASS
- `LocalProductsTenantIsolationTest`: PASS
- `LocalProductsLegacyRouteCompatibilityTest`: PASS
- `LocalProductsCsvImportVisibilityTest`: PASS
- `LocalProductsNoDuplicateSourceClassificationTest`: PASS

Broader/regression tests:
- `TenantAdvancedCatalogTest`: PASS
- `TenantProductCatalogMenuSimplificationTest`: PASS
- `AdminMenuServiceTest`: PASS
- broad `CatalogSearch`: PASS
- broad `Stock`: PASS
- broad `AdminSmoke`: PASS
- broad `LocalProducts`: PASS

## 18. Worktree / staging / commit
- Worktree: dirty, with many unrelated modifications already present
- Staged area: intentionally untouched in this phase
- This phase performed:
  - code implementation inside allowed scope
  - targeted and broad tests
  - docs updates
- Not performed:
  - migration
  - DB correction
  - stock correction
  - reservation apply
  - staging
  - commit

## 19. Next gate
Next gate remains manual smoke for the four admin surfaces:
- `/admin/catalog/local-products`
- `/admin/catalog/local-products/supplier-stock`
- `/admin/catalog/local-products/create`
- `/admin/catalog/local-products/import`

Manual closeout should verify:
- own products only in `Ürün Listem`
- only operational supplier-local rows in `Tedarikçiden Stoğa Alınanlar`
- projection-only rows excluded from supplier-local list
- menu/subnavigation order correct
- no 404/405/500
- accepted Promotion Quote compact metadata unchanged

Final phase statement:
- AUDIT UPDATED — M10 integration baseline preserved, M10-A route/source separation implemented, MANUAL SMOKE REQUIRED

## 2026-07-17 Template-A Addendum
- Template-A implementation consumed the existing single-spine local stock audit results without changing operational stock, reservation, procurement or quote pricing semantics.
- Supplier-local list continues to read operational tenant_local_stocks truth rather than catalog projection-only local stock.
- Manual UI smoke is still pending for the new production surfaces.


## 2026-07-17 Addendum — Exact Variant Supplier-Local Recovery
- Supplier-local list artık product aggregation yerine exact `tenant_local_stocks` rows kullanıyor.
- Varianted product + legacy product-scope stock normal listeden hariç; sade warning summary gösteriliyor.
- Exact variant detail route bağlandı: `/admin/catalog/{product}/variants/{variant}`.
- ET-0506 live audit: operational truth hâlâ tek product-scope 2000 row; auto correction yapılmadı.
- Promotion Quote MANUAL PASS regression lock korunuyor.
