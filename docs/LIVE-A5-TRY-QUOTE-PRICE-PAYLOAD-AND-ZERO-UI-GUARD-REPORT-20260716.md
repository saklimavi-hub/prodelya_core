# LIVE-A5 TRY Quote Price Payload And Zero UI Guard Report — 2026-07-16

## 1. Executive result
VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

## 2. Scope
- Bu rapor, `PRODELYA_V1_10.17.11` ve `10.17.14` kapanış kanıtlarını tek yerde toplar.
- Bu tur yalnız read-only exact attribution yapıldı.
- Production code, DB update, sync/apply/project, staging ve commit yapılmadı.

## 3. AK-1020 controlled resmoke — TK-2026-0029
- Exact order:
  - `order_id=50`
  - `tenant_account_id=2`
  - `document_number=TK-2026-0029`
  - `document_type=quote`
  - `status=draft`
  - `subtotal=16.775`
  - `grand_total=16.775`
  - `created_at=2026-07-16 09:39:22`
- Exact order item:
  - `order_item_id=118`
  - `product_code=AK-1020-KIRMIZI`
  - `quantity=1`
  - `list_price=30.50`
  - `discount_rate=45`
  - `unit_price=16.775`
  - `line_total=16.775`

## 4. Exact identity
- `tenant_catalog_product_id=9626`
- `tenant_catalog_product_variant_id=31440`
- `standard_product_id=4611`
- `standard_product_variant_id=15300`
- `product_code=AK-1020-KIRMIZI`

## 5. Exact price snapshot
- `price_snapshot.source_price=30.5`
- `price_snapshot.source_currency=TRY`
- `price_snapshot.base_price=30.5`
- `price_snapshot.applied_rate=1`
- `price_snapshot.rate_source=identity`
- `price_snapshot.suggested_sales_unit_price_document=16.78`
- `price_snapshot.actual_sales_unit_price_document=16.78`
- `price_snapshot.manual_sales_price_override=false`

## 6. Exact freshness
- `status=fresh`
- `projection_outdated=false`
- `stale_price=false`
- `stale_stock=false`
- `blocking=false`
- `warning_codes=[]`

## 7. UI ↔ DB parity
| Alan | Browser beklenen | Saved DB | Sonuç |
|---|---:|---:|---|
| Product code | AK-1020-KIRMIZI | AK-1020-KIRMIZI | PASS |
| List price | 30.50 | 30.50 | PASS |
| Unit price | non-zero | 16.775 | PASS |
| Line total | non-zero | 16.775 | PASS |
| Source price | 30.50 | 30.50 | PASS |
| Source currency | TRY | TRY | PASS |
| Applied rate | 1 | 1 | PASS |
| Freshness | fresh | fresh | PASS |
| Stale price | false | false | PASS |
| Blocking | false | false | PASS |

- Exact browser unit/toplam numerikleri bu rapora ayrı capture edilmedi.
- Kullanıcının verdiği controlled save kaydı ve read-only DB sonucu arasında non-zero parity blocker kalmadı.

## 8. Final live quote gate
Current state: VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

Gate open evidence:
- exact AK-1020 identity PASS
- `30.50 TRY` source truth PASS
- non-zero `list/unit/line` PASS
- `fresh` PASS
- `stale_price=false` PASS
- `blocking=false` PASS
- `manual override=false` PASS

## 9. Staging/commit
- Staging yapılmadı.
- Commit yapılmadı.
