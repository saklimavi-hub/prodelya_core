# LIVE-B1-M9 — Local Stock Controlled Correction Dry-Run Report — 2026-07-16

## 1. Yürütücü sonuç

Durum:
- `APPROVAL BLOCKED — MIGRATIONS PENDING AND BROAD REGRESSION NOT CLEAN`

Dry-run planlama durumu:
- `READY FOR APPROVAL` henüz verilemez

Neden:
- M8 migration'ları DB'de henüz uygulanmamış
- broad gate tamamen temiz değil
- exact business truth onayı yok: `ET-0506-MV exact operational local = 1000`

## 2. Migration status

`php artisan migrate:status` sonucu:
- `2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table` => `Pending`
- `2026_07_16_120100_create_tenant_stock_reservations_table` => `Pending`

`php artisan migrate --pretend` sonucu write-free doğrulandı:
- `tenant_local_stocks` tablosuna `tenant_catalog_product_variant_id`
- `stock_scope`
- `legacy_assignment_status`
- unique/index hardening
- `tenant_stock_reservations` tablosu oluşturma

Gerçek migrate çalıştırılmadı.

## 3. Broad regression

PASS:
- `Procurement`
- `PromotionQuote`
- `CatalogSearch`
- `TenantCatalog`
- `CurrentAccount`
- `AdminSmokeTest`

FAIL:
- `Stock`
  - `Tests\Feature\ProductSelectionWarningDisplayTest::test_quote_show_displays_local_stock_priority_and_supplier_stock_snapshot`
  - exact failure: test fixture `local_stock_quantity = 12` set ediyor ama payload `visible_stock_quantity = 2531` görüyor
  - attribution: `stale/changed stock provenance-visible quantity expectation`, M9 dry-run correction write planından ayrı bir stock-facing regression
- `Order`
  - `Tests\Feature\OrderShowTrackingScreenTest::test_orders_show_renders_as_tracking_screen_with_module_links`
  - exact failure: test `/admin/procurements/1` hard-coded show link bekliyor, response yeni order detail helper layout ile bu exact linki göstermiyor
  - attribution: `stale UI assertion / order detail helper link drift`, M8 correction logic regression değil

Broad gate sonucu:
- `NOT CLEAN`

## 4. Business confirmation

Business confirmation required:
- `ET-0506-MV exact operational local stock = 1000 units`

Bu değer şunlardan körlemesine türetilmeyecek:
- tenant catalog projection `1000`
- legacy product-level ledger `2000`

Onay olmadan:
- exact variant row write yok
- reservation apply yok
- historical correction yok

## 5. Legacy product-level stock

Exact legacy row:
- `tenant_local_stocks.id = 1`
- `tenant_account_id = 2`
- `tenant_catalog_product_id = 7817`
- `tenant_catalog_product_variant_id = absent in current DB`
- `quantity_on_hand = 2000.0000`
- `quantity_reserved = 0.0000`
- `quantity_available = 2000.0000`
- note: `Tenant katalog ekranından güncellendi.`

Karar:
- `legacy ambiguous product-level stock`

Bu turda yapılmayanlar:
- silme yok
- `2000 -> 1000` düzeltme yok
- variantlara dağıtma yok
- reservation kaynağı olarak kullanma yok

## 6. TS-2026-0015 safety audit

Exact kayıtlar:
- request: `TS-2026-0015` / `supplier_procurement_requests.id=16`
- request status: `taslak`
- procurement: `order_item_procurements.id=30`
- procurement status: `tedarik_bekliyor`
- request item: `23`
- order: `SP-2026-0014` / `orders.id=55`
- order item: `127`

Observed quantities:
- procurement requested: `1500.0000`
- procurement local_allocated: `0.0000`
- procurement supplier_requested: `1500.0000`
- procurement received: `0.0000`
- procurement remaining: `1500.0000`
- request item requested: `1500.00`
- request item received: `0.00`
- request item remaining: `1500.00`

Lifecycle dates:
- `ordered_at = null`
- `partially_received_at = null`
- `fully_received_at = null`
- `cancelled_at = null`

Open related request items for procurement `30`:
- only `request_item.id=23`

Safety matrix:
- `received_quantity = 0` => `PASS`
- partial receipt / downstream receipt evidence => `PASS` (none found in procurement/request item state)
- completed procurement => `PASS` (not completed)
- cancelled lifecycle conflict => `PASS`
- locked lifecycle conflict => `PASS` (draft request, active procurement)
- finalized supplier debit => `PASS` (`CurrentAccount` exact count below)
- notification / resend history => `PASS` (no persisted related log found)

## 7. Exact variant stock plan

Apply sonrası hedef row:
- `tenant_account_id = 2`
- `tenant_catalog_product_id = 7817`
- `tenant_catalog_product_variant_id = 27668`
- `quantity_on_hand = 1000`
- `quantity_reserved = 0` initially
- `quantity_available = 1000`
- `stock_scope = variant`

Dry-run action:
- `would_create = 1`
- exact row already exists ise overwrite yok
- product-level legacy row korunur

Prerequisite:
- migrations applied
- business truth approved

## 8. Reservation plan

Dry-run only:
- `order_id = 55`
- `order_item_id = 127`
- target exact variant local stock row
- reservation quantity `1000`
- status `active`

Dry-run expected:
- `would_create = 1`
- current live DB'de reservation table yok, bu yüzden actual create yapılamaz

## 9. Procurement correction plan

Target procurement: `30`

Before:
- `requested_quantity = 1500`
- `local_allocated_quantity = 0`
- `supplier_requested_quantity = 1500`
- `remaining_quantity = 1500`

After (approved apply hedefi):
- `requested_quantity = 1500`
- `local_allocated_quantity = 1000`
- `supplier_requested_quantity = 500`
- `remaining_quantity = 500`

Rule:
- `requested_quantity` değişmez
- yalnız allocation/shortfall alanları düzelir

## 10. Supplier request correction plan

Target request item: `23`

Before:
- `requested_quantity = 1500`
- `remaining_quantity = 1500`
- `received_quantity = 0`

After (approved apply hedefi):
- `requested_quantity = 500`
- `remaining_quantity = 500`
- `received_quantity = 0`

Request header:
- `TS-2026-0015` draft olarak kalır
- aggregate totals canonical builder/service ile recompute edilmeli
- elle summary write yapılmamalı

## 11. No-side-effect guarantees

Current read-only evidence:
- `tenant_stock_reservations` table => `absent` because migrations pending
- stock movement count for legacy local stock correction path => no downstream stock evidence found
- `current_account_transactions` where `source_type = supplier_procurement_request` and `source_id = 16` => `0`
- `current_account_transactions` where `source_type = order` and `source_id = 55` => not part of procurement correction scope
- `notification_logs` where `related_type = supplier_procurement_request` and `related_id = 16` => `0`
- `notification_logs` where `related_type = order` and `related_id = 55` => not part of procurement correction scope

Apply plan invariants:
- `Current account writes = 0`
- `Stock movements = 0`
- `Notifications = 0`
- `received quantity mutation = 0`
- `purchase price mutation = 0`

## 12. Dry-run output

Planned dry-run summary:
- exact variant stock row:
  - `would_create = 1`
  - `quantity_on_hand = 1000`
- reservation:
  - `would_create = 1`
  - `quantity = 1000`
- procurement:
  - `local_allocated 0 -> 1000`
  - `supplier_requested 1500 -> 500`
  - `remaining 1500 -> 500`
- supplier request item:
  - `requested 1500 -> 500`
  - `remaining 1500 -> 500`
- side effects:
  - `Current account writes = 0`
  - `Stock movements = 0`
  - `Notifications = 0`

Güncel hard stop:
- actual dry-run command execution yok
- because schema not ready and approval gate closed

## 13. New smoke plan

Correction sonrası ayrı controlled smoke planı:
1. migrations apply
2. exact variant stock row approved value ile create
3. yeni controlled order for `ET-0506-MV` qty `1500`
4. expected:
   - reservation `1000`
   - procurement candidate `500`
   - supplier request `500`
5. ikinci order ile double-allocation smoke
6. legacy `TS-2026-0015` correction ayrı verification

## 14. Worktree / staging / commit

- worktree kirli, kapsam dışı çok değişiklik var
- `git diff --cached --stat` boş
- staging yapılmadı
- commit yapılmadı
- production DB write yapılmadı

## 15. Approval gate

Current gate:
- `APPROVAL BLOCKED — MIGRATIONS PENDING AND BROAD REGRESSION NOT CLEAN`

Approval istenmeme gerekçeleri:
- broad gate fail
- migrations pending
- exact stock `1000` business confirmation yok
- reservation table live DB'de henüz yok

Approval açılmadan önce gerekenler:
1. broad `Stock` ve `Order` stale driftleri ayrıştırılıp temizlenir
2. migration apply için açık onay alınır
3. business exact stock `1000` açıkça onaylanır

Bu koşullar sağlanırsa hedef ifade:
- `READY FOR APPROVAL — EXACT VARIANT LOCAL STOCK AND TS-2026-0015 CONTROLLED CORRECTION PLAN`
