# LIVE-A4-M3 Exact Snapshot And AK1020 Zero Price Audit — 2026-07-16

## 1. Executive result
VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

## 2. Exact quote mapping
| Product | Document | Order ID | Order Item ID | Exact saved code |
|---|---|---:|---:|---|
| ET-0506-MV | TK-2026-0025 | 46 | 114 | ET-0506-MV |
| EL-KOD-35 | TK-2026-0026 | 47 | 115 | EL-KOD-35 |
| PZ-CH60SY | TK-2026-0027 | 48 | 116 | PZ-CH60SY |
| AK-1020 negative case | TK-2026-0028 | 49 | 117 | AK-1020-KIRMIZI |

## 3. ET-0506-MV snapshot
- Tenant/account: `tenant_account_id=2`
- Exact identity:
  - `tenant_catalog_product_id=7817`
  - `tenant_catalog_product_variant_id=27668`
  - `standard_product_id=3740`
  - `standard_product_variant_id=13299`
- Scalar persistence:
  - `quantity=1`
  - `list_price=9.20`
  - `discount_rate=45`
  - `unit_price=5.06`
  - `line_total=5.06`
- Snapshot attribution:
  - `price_snapshot.source_price=9.2`
  - `price_snapshot.source_currency=TRY`
  - `price_snapshot.applied_rate=1`
  - `price_snapshot.rate_source=identity`
  - `price_snapshot.base_price=9.2`
  - `price_snapshot.calculated_unit_price=5.06`
  - `price_snapshot.actual_sales_unit_price_document=5.06`
  - `price_snapshot.manual_sales_price_override=false`
  - `price_snapshot.freshness_summary.status=fresh`
  - `price_snapshot.freshness_summary.stale_price=false`
  - `price_snapshot.quote_price_snapshot.freshness.blocking=false`
- Controlled smoke checks:
  - `prints_count=0`
  - manual override yok
- Result: PASS

## 4. EL-KOD-35 snapshot
- Exact identity:
  - `tenant_catalog_product_id=10235`
  - `tenant_catalog_product_variant_id=null`
  - `standard_product_id=5123`
  - `standard_product_variant_id=null`
- Scalar persistence:
  - `quantity=1`
  - `list_price=134.00`
  - `discount_rate=45`
  - `unit_price=73.70`
  - `line_total=73.70`
- Snapshot attribution:
  - `price_snapshot.source_price=134`
  - `price_snapshot.source_currency=TRY`
  - `price_snapshot.applied_rate=1`
  - `price_snapshot.rate_source=identity`
  - `price_snapshot.base_price=134`
  - `price_snapshot.quote_price_status=not_required`
  - `price_snapshot.suggested_sales_unit_price_document=73.7`
  - `price_snapshot.actual_sales_unit_price_document=73.7`
  - `price_snapshot.freshness_summary.status=fresh`
  - `price_snapshot.freshness_summary.stale_price=false`
  - `price_snapshot.quote_price_snapshot.freshness.blocking=false`
- Controlled smoke checks:
  - flat product identity korundu
  - `prints_count=0`
- Result: PASS

## 5. PZ-CH60SY snapshot
- Exact identity:
  - `tenant_catalog_product_id=10060`
  - `tenant_catalog_product_variant_id=32302`
  - `standard_product_id=5159`
  - `standard_product_variant_id=16162`
- Scalar persistence:
  - `quantity=1`
  - `list_price=164.12`
  - `discount_rate=45`
  - `unit_price=90.266` (`actual_sales_unit_price_document=90.27`)
  - `line_total=90.266`
- Snapshot attribution:
  - `price_snapshot.source_price=3.5`
  - `price_snapshot.source_currency=USD`
  - `price_snapshot.base_price=164.12`
  - top-level `price_snapshot.applied_rate=1` ve `rate_source=identity` document TRY katmanına ait
  - exact source→TRY kanıtı `price_snapshot.sales_presentation.sales_rate=46.8914`
  - `price_snapshot.sales_presentation.sales_rate_source=derived`
  - `price_snapshot.sales_presentation.sales_list_try=164.12`
  - `price_snapshot.suggested_sales_unit_price_document=90.27`
  - `price_snapshot.actual_sales_unit_price_document=90.27`
  - `price_snapshot.freshness_summary.status=fresh`
  - `price_snapshot.freshness_summary.stale_price=false`
  - `price_snapshot.quote_price_snapshot.freshness.blocking=false`
- Controlled smoke checks:
  - parent/sibling fallback yok
  - `prints_count=0`
- Result: PASS

## 6. AK-1020 browser evidence
- Browser evidence supplied by user:
  - ürün başlığı: `AK-1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem`
  - stok: `22.713`
  - satış liste etiketi: `30,50 TL`
  - live-info message: `Canlı ürün bilgisi şu anda alınamadı.`
  - visible form alanları: `Satış Liste 0,00`, `Satış Birim Fiyatı 0,00`, `Toplam 0,00`
  - teklif özeti: `0,00 TL`

## 7. AK-1020 DB persistence
- Exact saved record exists:
  - `order_id=49`
  - `document_number=TK-2026-0028`
  - `order_item_id=117`
  - exact code `AK-1020-KIRMIZI`
- Saved scalar values:
  - `list_price=30.50`
  - `discount_rate=45`
  - `unit_price=16.775`
  - `line_total=16.775`
  - `grand_total=16.78`
- Saved identity:
  - `tenant_catalog_product_id=9626`
  - `tenant_catalog_product_variant_id=31440`
  - `standard_product_id=4611`
  - `standard_product_variant_id=15300`
- Saved snapshot:
  - `price_snapshot.source_price=30.5`
  - `price_snapshot.source_currency=TRY`
  - `price_snapshot.freshness_summary.status=fresh`
  - `price_snapshot.freshness_summary.stale_price=false`
  - `price_snapshot.quote_price_snapshot.freshness.blocking=false`
- Classification:
  - `CRITICAL — ZERO PRICE QUOTE SAVED` kanıtlanmadı
  - saved DB non-zero olduğu için bu vaka `UI DISPLAY / HYDRATION / LIVE-INFO PRICE PAYLOAD PARITY BUG`

## 8. AK-1020 source/catalog/live-info trace
| Layer | ID | Code | Price | Currency | Stock | Status |
|---|---:|---|---:|---|---:|---|
| Raw product | 4692 | 1020 | 30.50 | TL | 16820 | processed |
| Raw variant | 8258 | AK-1020-KIRMIZI | 30.50 | TL | 22713 | processed |
| Standard product | 4611 | generated `AK-1020` family | 30.50 | TL | 320538 aggregate in meta | unmapped/category pending |
| Standard variant | 15300 | 1020 Kırmızı | 30.50 | TL | 22713 | warning-free variant snapshot |
| Tenant catalog product | 9626 | family `AK-1020` | 30.50 | TRY | product visible_in_quote=false | parent shell |
| Tenant catalog variant | 31440 | AK-1020-KIRMIZI | 30.50 | TRY | 22713 | visible_in_quote via variant meta / sellable |
| CatalogSearch | `9626 / 31440` | AK-1020-KIRMIZI | `source_price=30.50`, `quote_price_value=null` | TRY | 22713 | `sellable=true`, `fresh`, parity broken |
| Live-info pre-save probe | `9626 / 31440` | AK-1020-KIRMIZI | `current_price=30.50`, `quote_price_value=null` | TRY | 22713 | `status=200`, `ok=true`, parity broken |
| Saved order_item | 117 | AK-1020-KIRMIZI | list `30.50`, unit `16.775` | TRY | snapshot fresh | persisted non-zero |

## 9. Live-info failure reason
- Exact server-side probe without `quote_item_id`:
  - HTTP `200`
  - `ok=true`
  - `public_safe_message="Ürün güncel ve teklif için uygun."`
  - `quote_price_value=null`
  - `quote_price_status=not_required`
- Exact controller contract:
  - tenant yoksa `403`
  - service status/body aynen dönülüyor
- Exact JS generic error branch:
  - `ensureLiveProductInfo()` fetch non-2xx veya runtime exception → `state.status='error'`
  - `renderLiveProductInfoPanel()` `safeState.status === 'error'` → `Canlı ürün bilgisi şu anda alınamadı.`
- Exact audit result:
  - current JSON contract’ta explicit `reason_code` alanı yok
  - browser’daki generic message current codebase’de yalnız fetch/error catch branch’inden gelebilir
  - fakat reproducible parity bug generic message’den bağımsız olarak kanıtlandı: successful payload bile `quote_price_value=null` dönüyor

## 10. Zero-price fallback behavior
- `CatalogSearch` canonical fiyat truth gösteriyor:
  - `source_price=30.50`
  - `source_currency=TRY`
- `ProductHubLiveProductInfoService` da aynı canonical truth’u koruyor fakat:
  - `quote_price_value=null`
  - `quote_price_status=not_required`
- `applyLiveProductQuotePricing()` branch:
  - `quoteValue === null` olduğunda satır `_row_error` set ediyor
  - `list_price=''`
  - `calculated_unit_price=''`
  - `unit_price=''`
  - `line_total='0.00'`
- Sonuç:
  - canonical truth mevcutken visible form `0.00` state’e düşebiliyor
  - bu davranış kabul edilemez

## 11. Save guard behavior
- Request validation yalnız `min:0` uygular:
  - `items.*.list_price`
  - `items.*.unit_price`
  - `items.*.calculated_unit_price`
- Catalog-backed product için explicit `>0` guard yok.
- `resolveUnitPricePayload()` server-side unit price’ı `list_price * (1 - discount)` üzerinden üretir.
- `store()` loop’unda persisted item şu alanlardan kuruluyor:
  - `list_price` request/normalized payload
  - `unit_price` resolved payload
  - `price_snapshot` = catalog snapshot + pricing snapshot
- Bu exact negative vakada non-zero save olmuş çünkü catalog/snapshot truth korunmuş.
- Ancak code-level audit sonucu:
  - canonical list truth > 0 iken visible UI `0.00` state’e düşmesi engellenmiyor
  - catalog-backed row için explicit non-zero save guard da görünmüyor

## 12. Security/tenant scope
- Tüm read-only DB inspeksiyonları `tenant_account_id=2` ve exact order ids `46,47,48,49` ile sınırlandı.
- Cross-tenant mutation yapılmadı.
- Prompt dışı route/menu/public/customer alanlarına dokunulmadı.

## 13. Data mutation status
- Production DB update yok.
- Sync/apply/project yok.
- Quote mutation yok.

## 14. Worktree/staging/commit
- Worktree kirliydi; ilgisiz dosyalara dokunulmadı.
- Staging yapılmadı.
- Commit yapılmadı.

## 15. AK-1020 controlled resmoke — TK-2026-0029
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
  - `unit_price=16.775`
  - `line_total=16.775`
- Exact identity:
  - `tenant_catalog_product_id=9626`
  - `tenant_catalog_product_variant_id=31440`
  - `standard_product_id=4611`
  - `standard_product_variant_id=15300`
- Exact price snapshot:
  - `price_snapshot.source_price=30.5`
  - `price_snapshot.source_currency=TRY`
  - `price_snapshot.base_price=30.5`
  - `price_snapshot.applied_rate=1`
  - `price_snapshot.rate_source=identity`
  - `price_snapshot.suggested_sales_unit_price_document=16.78`
  - `price_snapshot.actual_sales_unit_price_document=16.78`
  - `price_snapshot.manual_sales_price_override=false`
- Exact freshness:
  - `status=fresh`
  - `projection_outdated=false`
  - `stale_price=false`
  - `stale_stock=false`
  - `blocking=false`
  - `warning_codes=[]`
- UI ↔ DB parity:
  - browser tarafından exact sayısal birim/toplam ekran görüntüsü bu rapora ayrı kaydedilmedi
  - ancak kullanıcı kontrollü save için exact kayıt `TK-2026-0029` verdi ve saved DB tarafında beklenen tüm non-zero truth korundu
  - `Satış Liste: 30,50 TL` beklentisi saved `list_price=30.50` ile PASS
  - `Satış Birim Fiyatı: non-zero` beklentisi saved `unit_price=16.775` ile PASS
  - `Toplam: non-zero` beklentisi saved `line_total=16.775` ile PASS
  - generic live-info error için bu exact kayda ait ayrı browser source kanıtı rapora eklenmedi; saved record tarafında parity/freshness blocker yok

## 16. Live quote gate
Current state: VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN

Gate open evidence:
- exact AK-1020 identity PASS
- `30.50 TRY` source truth PASS
- non-zero `list/unit/line` PASS
- `fresh` PASS
- `stale_price=false` PASS
- `blocking=false` PASS
- `manual override=false` PASS

## 17. Data mutation status
- Bu tur production code/DB update yok.
- Sync/apply/project yok.
- Staging/commit yok.
