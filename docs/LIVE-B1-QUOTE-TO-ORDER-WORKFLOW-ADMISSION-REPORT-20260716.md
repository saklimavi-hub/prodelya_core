# LIVE-B1 — Quote to Order Workflow Admission Report — 2026-07-16

## 1. Executive result

Status: `MANUAL CLOSEOUT PENDING — LIVE-B1-M5 ACTUAL ROUTE PARITY TESTS PASS, BROWSER OVERRIDE STEP STILL PENDING`

Reason:
- Exact read-only audit for `TK-2026-0027 -> SP-2026-0012` proved the procurement need exists and is aggregated as a candidate.
- Root cause was not conversion admission loss. Root cause was user-facing label parity plus procurement candidate visibility.
- No supplier request was auto-created during the original audit.
- The later manual browser flow confirmed candidate visibility, request transition, post-request order-list procurement labels, and supplier discount calculations.
- Order detail no-print label parity was then recovered so the detail view can consume the same canonical sticky status resolver as the order list.`r`n- Actual show-route HTTP regressions now pass through `OrderNoPrintActualShowRouteParityTest` and `OrderShowRouteNoPrintPostRequestHttpTest`, so the remaining uncertainty is browser-side closeout, not untested server rendering.
- Procurement edit pricing now also has a linked manual gate: LIVE-B1-M3 recovered the `%55` supplier discount live recalculation contract for `TS-2026-0014`, but the explicit browser `250,00 -> Hesaplananı kullan` override step is still pending.
- Final VERIFIED wording must wait for the remaining manual closeout steps.

## 2. Exact audit addendum for SP-2026-0012

Observed exact record:
- quote: `TK-2026-0027`
- order: `SP-2026-0012`
- order id: `51`
- quote id: `48`
- work form ids: `[26]`
- order item id: `119`
- product: `PZ-CH60SY Telefon Tutucu Siyah`
- quantity: `1.0000`
- print count: `0`
- supplier id: `6`
- procurement id: `26`
- requires procurement: `true`
- raw procurement status: `tedarik_bekliyor`
- requested: `1.0000`
- local allocated: `0.0000`
- received: `0.0000`
- remaining: `1.0000`
- fulfillment source: `supplier`
- open supplier request item: `null` on the first audit; later manual flow created `TS-2026-0014`

Interpretation:
- procurement admission exists
- open request did not exist yet in the initial audit
- order list showing `Tedarik Bekliyor` at that point was incorrect
- correct pre-request label is `Talep Hazırlanacak`
- correct next action is `Tedarik talebini hazırla`
- after the real supplier request is opened and marked requested, the correct state becomes `Tedarik Bekliyor` / `Tedarikçiden dönüş bekle`

## 3. Candidate aggregation addendum

Exact aggregation proof for tenant `2` / supplier `6`:
- supplier group contains supplier `6`
- open item count: `3`
- candidate item count: `2`
- open request count: `1`
- candidate ids: `[24, 26]`
- `SP-2026-0012 / PZ-CH60SY` is included in candidate aggregation

Interpretation:
- this was not a missing-need bug
- this was a candidate visibility / UI presentation bug

## 4. LIVE-B1 parity outcome

Applied parity decisions:
- no-print orders now present `Grafik: Gerekli Değil`
- no-print orders now present `Üretim: Gerekli Değil`
- pre-request procurement now presents `Talep Hazırlanacak`
- pre-request next action now presents `Tedarik talebini hazırla`
- `Tedarik Bekliyor` is reserved for post-request states only
- procurement index now exposes candidate needs in the main screen instead of hiding them only in side counts
- order detail module cards, main flow, right process summary and active focus now read the canonical order-list parity state
- completed procurement remains visible as `Tedarik Tamamlandı` instead of collapsing to `Gerekli Değil`

## 5. Targeted tests

PASS:
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

Additional note:
- earlier proactive broad `Order` drift remains outside this exact manual closeout scope
- no production rollback or stale copy reintroduction was needed to green the procurement-adjacent gate

## 6. Worktree / staging / commit

- staging yapılmadı
- commit yapılmadı
- supplier request otomatik açılmadı by audit code
- pricing / current-account / Product Hub / global CSS alanlarına dokunulmadı beyond the already approved prompt scopes

## 7. Current gate

Current status:
- `MANUAL CLOSEOUT PENDING`

Already user-confirmed PASS:
- procurement candidate görünür
- `Talebi Aç` transition çalıştı
- order list request sonrası `Tedarik Bekliyor` / `Tedarikçiden dönüş bekle` gösteriyor
- no-print order list `Grafik: Gerekli Değil` / `Üretim: Gerekli Değil` gösteriyor
- Akdeniz ve Pozitron `%55` alış iskontosu hesapları doğru
- save/reopen korunuyor

Still required next manual checks:
- `/admin/orders/51` main flow: `Grafik: Gerekli Değil`
- `/admin/orders/51` right process summary: `Grafik: Gerekli Değil`
- `/admin/orders/51` active focus procurement request state remains `Tedarikçiden dönüş bekle`
- procurement edit `TS-2026-0014` explicit browser override step: `250,00` manual final, then `Hesaplananı kullan` restore
- no 404/405/500


## 8. M8 stock reservation addendum

Current related status:
- IMPLEMENTED — EXACT LOCAL STOCK RESERVATION AND PROCUREMENT SHORTFALL READY — CONTROLLED DATA CORRECTION PENDING`r

Addendum:
- Quote->order admission artik exact variant local stock reservation ile shortfall procurement ayrimi uretiyor.
- Candidate / supplier request miktarlari canonical shortfall uzerinden ilerliyor.
- CatalogSearch ve live-info local stock sayisini operational truth gibi sunmak yerine provenance ile ayiriyor.
- TS-2026-0015 otomatik mutate edilmedi; kontrollu correction ayrik onay bekliyor.
- Bu nedenle genel LIVE-B1 workflow parity kod seviyesi olarak toparlansa da mevcut gecmis veri icin manuel/correction closeout hala ayri bir gate olarak duruyor.
