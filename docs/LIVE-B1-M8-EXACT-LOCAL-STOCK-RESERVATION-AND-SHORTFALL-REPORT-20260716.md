# LIVE-B1-M8 — Exact Local Stock Reservation and Procurement Shortfall Report — 2026-07-16

## 1. Faz sonucu

Status: `IMPLEMENTED — EXACT LOCAL STOCK RESERVATION AND PROCUREMENT SHORTFALL READY — CONTROLLED DATA CORRECTION PENDING`

Bu fazda tamamlananlar:
- `tenant_local_stocks` varyant scope sertleştirildi.
- `tenant_stock_reservations` ile idempotent reservation ledger eklendi.
- Order conversion / work form admission tarafında exact local stock reservation uygulanıyor.
- Procurement artık full order qty değil canonical shortfall ile açılıyor.
- Supplier request draft miktarı procurement shortfall üzerinden kuruluyor.
- CatalogSearch ve live-info payload'ları projection ile operational truth'u provenance alanlarıyla ayırıyor.
- Quote UI local stock etiketleri artık exact operational / catalog projection / unresolved ayrımıyla gösteriliyor.
- Sipariş detayı ve procurement yüzeyleri `Sipariş / Yerel tahsis / Tedarik edilecek` kontratını kullanıyor.

Bu fazda bilinçli olarak yapılmayanlar:
- `TS-2026-0015` otomatik mutate edilmedi.
- `ET-0506-MV` için exact variant ledger değeri kullanıcı onayı olmadan yazılmadı.
- Product-level legacy stok variantlara dağıtılmadı.
- Gerçek stock movement conversion sırasında üretilmedi.

## 2. Implement edilen canonical model

Canonical truth:
- tenant
- exact product
- exact variant
- reservation-backed available quantity
- procurement shortfall = order qty - reserved local

Display/projection only:
- `tenant_catalog_products.local_stock_quantity`
- `tenant_catalog_product_variants.local_stock_quantity`
- `order_items.stock_snapshot.local_stock_quantity`
- Product Hub projection stock alanları

## 3. Kod kanıtı

Yeni dosyalar:
- `database/migrations/2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table.php`
- `database/migrations/2026_07_16_120100_create_tenant_stock_reservations_table.php`
- `app/Models/TenantStockReservation.php`
- `app/Services/Stock/TenantLocalStockResolver.php`
- `app/Services/Stock/TenantStockReservationService.php`
- `app/Services/Stock/TenantLocalStockPresentationService.php`
- `tests/Feature/OrderLocalStockReservationTest.php`

Güncellenen çekirdek alanlar:
- `app/Models/TenantLocalStock.php`
- `app/Models/Order.php`
- `app/Services/ProcurementCreationService.php`
- `app/Services/ProcurementWorkflowService.php`
- `app/Services/SupplierProcurementRequestService.php`
- `app/Http/Controllers/Admin/CatalogSearchController.php`
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `resources/views/admin/procurements/index.blade.php`
- `resources/views/admin/procurements/supplier-requests/create.blade.php`

## 4. Behavior contract

Exact variant stock found:
- reservation = `min(order_qty, quantity_available)`
- procurement shortfall = `max(order_qty - reservation, 0)`
- request item qty = shortfall

Variant + only product-level ledger:
- auto allocation yok
- `reason_code = ambiguous_product_level_stock`
- quote/search satışını bloklamaz
- procurement UI warning üretir

Full local stock:
- procurement candidate oluşmamalı

Partial local stock:
- yalnız shortfall procurement'e düşmeli

Cancellation:
- active reservation release edilir
- available quantity geri açılır

## 5. Hedefli test kanıtı

PASS:
- `OrderLocalStockReservationTest`
  - exact variant resolver
  - ambiguous product-level refusal
  - partial reservation -> shortfall
  - supplier request uses shortfall
  - cancellation releases reservation
- `PromotionQuoteExactLocalStockBadgeTest`
- `php artisan view:cache`

Bu faz sonunda broad suite henüz koşulmadı.

## 6. Controlled data correction plan

Henüz uygulanmadı. `TS-2026-0015` için correction ancak ayrı açık onayla düşünülmeli.

Önkoşullar:
- request draft/requested seviyesinde olmalı
- received = 0 olmalı
- finalized supplier cari etkisi olmamalı
- downstream receipt olmamalı
- business exact variant truth ayrı onaylanmalı

Beklenen kontrollü correction hedefi:
- reservation active = exact approved local qty
- procurement local_allocated = same qty
- procurement supplier_requested = exact shortfall
- request item requested = same shortfall
- audit log

## 7. Manuel smoke durumu

Henüz kapatılmadı.

Kapatmak için beklenen kontrollü smoke:
- exact variant local row business-confirmed value ile hazırlanır
- order qty > local qty senaryosunda yalnız shortfall procurement'e düşer
- supplier request shortfall ile açılır
- ikinci order ile double allocation önlenir

## 8. Gate

Current gate:
- `IMPLEMENTED — EXACT LOCAL STOCK RESERVATION AND PROCUREMENT SHORTFALL READY — CONTROLLED DATA CORRECTION PENDING`

Live gate henüz `VERIFIED` değildir.
