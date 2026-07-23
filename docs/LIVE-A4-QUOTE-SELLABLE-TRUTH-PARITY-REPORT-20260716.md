# LIVE-A4 Quote Sellable Truth Parity Report — 2026-07-16

## 1. Executive result
VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

## 2. Scope
- Bu dosya worktree’de mevcut değildi; 2026-07-16 M3 audit kapsamında promptta istenen hedef ada oluşturuldu.
- Bu tur yalnız read-only audit bulgularını özetler.

## 3. Sellable-truth parity baseline
- `ProductHubSellableTruthService` shared decision katmanı olarak kalıyor.
- Controlled exact saves:
  - `TK-2026-0025` → `ET-0506-MV`
  - `TK-2026-0026` → `EL-KOD-35`
  - `TK-2026-0027` → `PZ-CH60SY`
- Bu üç kayıtta search/save parity kırılmadı; exact saved snapshotlar beklenen kimlik ve freshness alanlarını korudu.

## 4. Exact controlled save snapshot summary
| Ürün | Document | Item | Identity | Currency | Base | Freshness | Result |
|---|---|---:|---|---|---:|---|---|
| ET-0506-MV | TK-2026-0025 | 114 | `7817 / 27668 / 3740 / 13299` | TRY | 9.20 | fresh | PASS |
| EL-KOD-35 | TK-2026-0026 | 115 | `10235 / null / 5123 / null` | TRY | 134.00 | fresh | PASS |
| PZ-CH60SY | TK-2026-0027 | 116 | `10060 / 32302 / 5159 / 16162` | USD source / TRY base | 164.12 | fresh | PASS |

## 5. AK-1020 parity audit result
- Browser evidence:
  - satış liste etiketi `30,50 TL`
  - stok `22,713`
  - live-info mesajı: `Canlı ürün bilgisi şu anda alınamadı.`
  - visible form alanları: `0,00`
- Read-only saved DB:
  - `49 / TK-2026-0028 / order_item 117`
  - exact code `AK-1020-KIRMIZI`
  - `list_price=30.50`, `unit_price=16.775`, `line_total=16.775`, `grand_total=16.78`
- Sonuç:
  - zero-price save kanıtlanmadı
  - fakat UI/live-info parity blocker kanıtlandı

## 6. Root cause summary
- `CatalogSearch` AK-1020 varyantlarını sellable + visible + fresh döndürüyor.
- Aynı payload içinde `source_price=30.50 TRY` var.
- Buna rağmen `quote_price_value=null` ve `quote_price_status=not_required`.
- `ProductHubLiveProductInfoService` pre-save probe’da da aynı kombinasyonla `200/ok=true` döndürüyor.
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php` içindeki `applyLiveProductQuotePricing()` şu koşulda visible fiyat alanlarını boşaltıyor:
  - `!isReadyQuotePriceStatus(quoteStatus) || quoteValue === null ...`
- Böylece canonical fiyat bilgisi varken visible row `0.00` durumuna düşebiliyor.

## 7. UI message attribution
- Exact generic mesaj `Canlı ürün bilgisi şu anda alınamadı.` yalnız JS `catch` dalında render edilir.
- Exact branch:
  - `ensureLiveProductInfo()` fetch `!response.ok` veya runtime hata → `state.status='error'`
  - `renderLiveProductInfoPanel()` `safeState.status === 'error'` → generic mesaj
- Live-info JSON contract’ında explicit `reason_code` alanı yok.

## 8. AK-1020 controlled resmoke — TK-2026-0029
- Exact order/item:
  - `order_id=50`
  - `order_item_id=118`
  - `document_number=TK-2026-0029`
  - `product_code=AK-1020-KIRMIZI`
- Identity:
  - `tenant_catalog_product_id=9626`
  - `tenant_catalog_product_variant_id=31440`
  - `standard_product_id=4611`
  - `standard_product_variant_id=15300`
- Price snapshot:
  - `source_price=30.5`
  - `source_currency=TRY`
  - `base_price=30.5`
  - `applied_rate=1`
  - `rate_source=identity`
  - `suggested_sales_unit_price_document=16.78`
  - `actual_sales_unit_price_document=16.78`
  - `manual_sales_price_override=false`
- Freshness:
  - `status=fresh`
  - `projection_outdated=false`
  - `stale_price=false`
  - `stale_stock=false`
  - `blocking=false`
  - `warning_codes=[]`
- UI/DB parity:
  - `Satış Liste 30,50 TL` beklentisi saved `list_price=30.50` ile hizalı
  - `Satış Birim Fiyatı non-zero` saved `unit_price=16.775`
  - `Toplam non-zero` saved `line_total=16.775`
  - exact browser unit/toplam numerikleri bu rapora ayrı capture edilmedi; read-only DB parity truth PASS

## 9. Gate state
Current state: VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

## 10. Data mutation status
- Production DB update yok.
- Sync/apply/project yok.
- Staging/commit yok.
