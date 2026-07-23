# LIVE-B1-M2 — Procurement Need And No-Print Label Parity Report — 2026-07-16

## 1. Executive result

Status: `RECOVERED — ORDER LIST / DETAIL NO-PRINT LABEL PARITY — MANUAL RESMOKE REQUIRED`

User-reported symptom:
- browser showed `TK-2026-0027 -> SP-2026-0012`
- order list showed `Grafik: Hazır`, `Tedarik: Bekliyor`, `Üretim: Kayıt Yok`, `Teslimat: Bekliyor`
- next action showed `Tedarik bekliyor`
- `/admin/procurements` active list did not visibly show `SP-2026-0012`
- later manual browser confirmed list-side parity and candidate visibility, but order detail still showed `Grafik: Hazır` for the same no-print order

Exact conclusion:
- procurement need exists
- candidate aggregation exists
- supplier request transition exists
- root cause was UI/data-builder parity, not missing admission
- the remaining drift was order detail using a non-canonical graphic/procurement/production status source instead of the shared sticky-panel resolver

## 2. Exact read-only audit

Exact order trace:
- quote: `TK-2026-0027`
- order: `SP-2026-0012`
- order id: `51`
- quote id: `48`
- tenant id: `2`
- work form ids: `[26]`
- item id: `119`
- product code: `PZ-CH60SY`
- product name: `PZ-CH60SY Telefon Tutucu Siyah`
- print count: `0`
- supplier id: `6`
- procurement id: `26`
- requires procurement: `true`
- raw procurement status: `tedarik_bekliyor`
- requested quantity: `1.0000`
- local allocated: `0.0000`
- received: `0.0000`
- remaining: `1.0000`
- fulfillment source: `supplier`
- open supplier request item: `null` on the first audit, then real browser flow later created `TS-2026-0014`

Candidate aggregation proof:
- supplier group contains supplier `6`
- open item count: `3`
- candidate item count: `2`
- open request count: `1`
- candidate ids: `[24, 26]`
- `SP-2026-0012 / PZ-CH60SY` is present in candidate aggregation

Root-cause classification:
- `need missing` => FALSE
- `candidate hidden / UI parity bug` => TRUE
- `order detail uses stale non-canonical no-print label path` => TRUE

## 3. Expected parity contract

Before any supplier request is opened:
- procurement label: `Talep Hazırlanacak`
- next action: `Tedarik talebini hazırla`
- `Tedarik Bekliyor` must not be shown yet

After the supplier request is opened and marked requested:
- procurement label: `Tedarik Bekliyor`
- next action: `Tedarikçiden dönüş bekle`

For no-print orders:
- graphic label: `Gerekli Değil`
- production label: `Gerekli Değil`
- graphic queue inclusion: no
- production queue inclusion: no

Procurement screen:
- candidate needs must be visible in the main active screen
- visibility must include order / work form / product context
- candidate must not be hidden only in supplier-count side cards

## 4. Applied implementation scope

Code areas updated:
- `app/Models/OrderItemProcurement.php`
- `app/Services/OrderListSummaryService.php`
- `app/Services/OrderShowSummaryService.php`
- `app/Services/OrderDetailProcessDepthPresenter.php`
- `resources/views/admin/procurements/index.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `tests/Feature/OrderNoPrintListDetailLabelParityTest.php`
- `tests/Feature/OrderNoPrintPostProcurementRequestLabelTest.php`
- `tests/Feature/OrderNoPrintDetailQueueExclusionTest.php`
- `tests/Feature/PrintedOrderDetailLabelRegressionTest.php`

Behavioral changes:
- introduced user-facing procurement state resolver for pre-request vs post-request states
- order list and order detail now use request-aware procurement labels
- no-print graphic/production labels are normalized to `Gerekli Değil`
- procurement candidates are rendered in a visible main-column table
- order detail module cards and flow/status surfaces now read the canonical sticky-panel module statuses
- completed procurement remains visible as `Tedarik Tamamlandı` instead of collapsing to `Gerekli Değil`

Non-goals preserved:
- no supplier request auto-open
- no pricing mutation
- no current-account mutation
- no Product Hub changes
- no global CSS refactor

## 5. Tests

Targeted PASS:
- `OrderProcurementAdmissionTest`
- `OrderProcurementCandidateVisibilityTest`
- `OrderProcurementRequestStateLabelTest`
- `OrderNoPrintLabelParityTest`
- `OrderNoPrintQueueExclusionTest`
- `OrderNoPrintListDetailLabelParityTest`
- `OrderNoPrintPostProcurementRequestLabelTest`
- `OrderNoPrintDetailQueueExclusionTest`
- `PrintedOrderDetailLabelRegressionTest`
- `ProcurementCandidateToRequestTransitionTest`
- `OrderProcurementAdmissionIdempotencyTest`
- `OrderNoPrintGraphicProductionExclusionTest`
- `ConvertedOrderListAdmissionTest`
- `OrderWorkflowLabelParityTest`
- `ProcurementEndToEndSmokeTest`
- broad `Procurement`

Broad-note attribution:
- a proactive broad `Order` run previously surfaced stale test-copy drift outside this prompt's exact manual parity gate
- after the order-detail parity fix, the near broad procurement gate is green again

## 6. Manual resmoke checklist

Already user-confirmed PASS:
1. `SP-2026-0012` candidate need is visible in procurement.
2. `Talebi Aç` transition works.
3. After request creation, order list shows `Tedarik Bekliyor`.
4. After request creation, next action shows `Tedarikçiden dönüş bekle`.
5. No-print order list shows `Grafik: Gerekli Değil`.
6. No-print order list shows `Üretim: Gerekli Değil`.

Still required after this M4 fix:
1. Open `/admin/orders/51` and verify the main flow card shows `Grafik: Gerekli Değil`.
2. Verify the right process summary shows `Grafik: Gerekli Değil`.
3. Verify `Üretim: Gerekli Değil` remains visible in order detail.
4. Verify procurement remains `Tedarik Bekliyor` and does not regress to `Talep Hazırlanacak`.
5. Verify active focus remains `Tedarikçiden dönüş bekle`.
6. Verify no 404, 405, or 500 occurs.

## 7. Worktree discipline

- staging yapılmadı
- commit yapılmadı
- final manual closeout sonucu bekleniyor
