# LIVE-B1-M7 — Local Stock Truth and Procurement Shortfall Report — 2026-07-16

## 1. Yürütücü sonuç

Güncel durum:
- `IMPLEMENTED — EXACT LOCAL STOCK RESERVATION AND PROCUREMENT SHORTFALL READY — CONTROLLED DATA CORRECTION PENDING`

Bu rapor iki ayrı zamanı ayırır:
- `M8 öncesi audit bulgusu`
- `M8 sonrası implementasyon durumu`

## 2. M8 öncesi audit bulgusu

Browser evidence:
- `ET-0506-MV` quote ekranında `Local stok: 1.000`
- `Tedarikçi stok: 27.800`
- `SP-2026-0014` sipariş miktarı `1.500`
- `TS-2026-0015` request item miktarı `1.500`

Exact kayıt zinciri:
- order: `SP-2026-0014` / `orders.id=55`
- order item: `127`
- product: `ET-0506-MV`
- tenant catalog product: `7817`
- tenant catalog variant: `27668`
- procurement: `30`
- supplier request: `TS-2026-0015` / `16`
- supplier request item: `23`

Pre-M8 exact canlı değerler:
- `order_items.stock_snapshot.local_stock_quantity = 1000`
- `tenant_local_stocks.id=1 quantity_available = 2000`
- `order_item_procurements.local_allocated_quantity = 0`
- `order_item_procurements.supplier_requested_quantity = 1500`
- `supplier_procurement_request_items.requested_quantity = 1500`

Pre-M8 root cause:
- quote/search/live-info local stok değeri tenant catalog projection idi, operational exact truth değildi
- procurement creation local allocation yapmıyordu
- reservation yoktu
- supplier request shortfall yerine full remaining qty ile açılıyordu

## 3. M8 sonrası implementasyon durumu

M8 sonrasında worktree’de implement edilen current state:
- `tenant_local_stocks` için exact variant scope migration'ı hazır
- `tenant_stock_reservations` migration'ı hazır
- `TenantLocalStockResolver` exact variant / flat / ambiguous ayrımı yapıyor
- `ProcurementCreationService` canonical reservation + shortfall sözleşmesine bağlandı
- `SupplierProcurementRequestService` request item qty'yi shortfall üzerinden kuruyor
- quote UI, CatalogSearch ve live-info local stock provenance alanları taşıyor

Current implementation contracts:
- exact variant stock found => reserve `min(order_qty, available)`
- partial stock => procurement yalnız shortfall
- variant + product-level only => `ambiguous_product_level_stock`, auto allocation yok
- cancellation => reservation release
- product-level legacy stock => korunur, variantlara otomatik dağıtılmaz

## 4. Tarihsel exact iz

Historical conflicting truths:
- tenant catalog / snapshot local stock: `1000`
- product-level operational ledger: `2000`

Historical product-level ledger kaydı:
- `tenant_local_stocks.id=1`
- `tenant_account_id=2`
- `tenant_catalog_product_id=7817`
- `quantity_on_hand=2000`
- `quantity_reserved=0`
- `quantity_available=2000`

Karar:
- bu kayıt `legacy ambiguous product-level stock`
- exact variant reservation kaynağı olarak business onayı olmadan kullanılamaz

## 5. Current gate blockers

Açık kalan blocker'lar implementation değil, activation/correction tarafındadır:
- M8 migration'ları DB'de henüz `Pending`
- exact business truth onayı yok: `ET-0506-MV exact operational local = 1000`
- historical `TS-2026-0015` correction uygulanmadı
- manual smoke henüz correction sonrası kapanmadı

## 6. Targeted verification

PASS:
- `OrderLocalStockReservationTest`
- `PromotionQuoteExactLocalStockBadgeTest`
- `php artisan view:cache`

## 7. Controlled correction pending

Controlled correction kapsamı:
- exact variant stock row create planı
- reservation `1000`
- procurement `1500 -> 500`
- request item `1500 -> 500`

Henüz yapılmayanlar:
- DB write yok
- `TS-2026-0015` mutate edilmedi
- product-level `2000` kaydı değiştirilmedi
- variantlara otomatik dağıtım yapılmadı

## 8. Worktree / staging / commit

- worktree kirli, mevcut kapsam dışında birçok değişiklik var
- staging yapılmadı
- commit yapılmadı

## 9. Next safe step

Bir sonraki güvenli adım:
1. M8 migration'larını apply etmeye ayrı onay verilir
2. `ET-0506-MV exact local stock = 1000` işletme tarafından açıkça onaylanır
3. `TS-2026-0015` için dry-run correction planı ayrıca değerlendirilir
4. ardından controlled correction + manual smoke yapılır
