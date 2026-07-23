# F1P3 PROCUREMENT SUPPLIER PRICE CURRENCY UI REPORT — 2026-07-14

## 1. Preflight
- `git status --short`: worktree dirty, staged alan bos
- `git diff --cached --stat`: bos
- F1P1 canonical procurement pricing foundation dosyalari mevcut
- `.tmp` artefaktlari bu fazda stage edilmedi

## 2. Canonical source resolver
- Tek truth source: `app/Services/Procurement/SupplierPurchasePriceSourceResolver.php`
- Pricing compose/update: `app/Services/Procurement/ProcurementPurchasePricingService.php`
- Supplier request item canonical alanlari: `app/Models/SupplierProcurementRequestItem.php`

## 3. Field/schema mapping
- Original supplier amount: `purchase_source_amount`
- Original supplier currency: `purchase_source_currency`
- Source->TRY rate/date/source: `purchase_fx_rate`, `purchase_fx_rate_date`, `purchase_fx_rate_source`
- TRY equivalent: `purchase_list_price_try`
- Discount: `discount_rate`
- Calculated/manual/final unit: `purchase_calculated_unit_price`, `purchase_manual_unit_price`, `purchase_unit_price`
- Immutable snapshot payload: `purchase_price_snapshot`

## 4. UI mapping
- Edit gridinde gorunur alanlar kompakt sekilde tutuldu:
  - Tedarikci Liste
  - Alis Iskontosu %
  - Alis Birim Fiyati
  - Alis Toplami
- Ayri buyuk fiyat ayrintisi karti eklenmedi
- Sales reference dili ve gorunur sales fallback etiketleri kaldirildi

## 5. TRY behavior
- TRY supplier source varsa original amount/currency dogrudan gosterilir
- Identity rate kullaniciya gosterilmez
- TL equivalent mevcut snapshot alanindan okunur

## 6. USD/EUR behavior
- Original amount/currency korunur
- `1 USD/EUR = X TL` satiri canonical FX snapshot’tan okunur
- Kur tarihi yalniz USD/EUR icin gosterilir

## 7. Discount precision
- Discount input mevcut canonical service contract’ina bagli kaldi
- Hesaplanan helper degeri snapshot’tan okunur; Blade yeniden hesap yapmaz

## 8. Manual override
- Alis Birim Fiyati inputu yalniz manual override icin kullanilir
- `Hesaplanani kullan` aksiyonu hidden flag ile backend’de manual override’i sifirlar
- Manual override badge’i gorunur kaldirildi

## 9. Unresolved policy
- Missing supplier source icin gorunur mesaj: `Tedarikci liste fiyati bulunamadi`
- Sales fallback, sahte `0,00 TL` source ve teknik payload gosterimi yok
- Manual unit override contract’i korunur

## 10. Completed correction
- Completed request quantity alanlari kilitli kalir
- `Alis Fiyatlarini Kaydet` aksiyonu korunur
- Fiyat/not guncellemesi canonical snapshot gorunumune baglandi

## 11. Supplier print
- `resources/views/admin/procurements/supplier-requests/print.blade.php` fiyatsiz kaldi
- Purchase/sales/currency alanlari supplier printte yok

## 12. No sales fallback evidence
- Procurement source resolver testinde sales snapshot supplier truth’u override etmez
- Missing supplier source testinde procurement row unresolved kalir
- Edit UI’da `Satis Ref` ve benzeri etiketler render edilmez

## 13. Historical snapshot
- Edit ekrani canli urunu yeniden hesaplamaz
- Mevcut request item snapshot’i gosterilir
- Eski request satiri live rate degisikligi ile degismez

## 14. Permissions/security
- Purchase price alanlari mevcut permission standardina bagli kaldi
- Yetkisiz kullanici fiyat alanlarini gormez
- Supplier print ve diger public/customer yuzeylerde fiyat sizintisi yok

## 15. Tests
- `tests/Feature/SupplierProcurementRequestPriceReferenceTest.php`: PASS
- `tests/Feature/SupplierProcurementRequestEditFixTest.php`: PASS
- `tests/Feature/SupplierProcurementRequestPrintFormTest.php`: PASS
- `tests/Feature/ProcurementPurchasePriceSnapshotTest.php`: PASS
- `tests/Feature/ProcurementPurchasePriceCurrencyIsolationTest.php`: PASS
- `tests/Feature/CompletedSupplierProcurementPurchasePriceUpdateTest.php`: PASS
- `tests/Feature/SupplierRequestPriceFreePrintReferenceTest.php`: PASS
- Broad suite bu rapor aninda henuz calistirilmadi

## 16. Manual smoke
- Manual smoke bekleniyor

## 17. Worktree/staging/commit
- Staging yapilmadi
- Commit yapilmadi

## 18. F1P4 gate
- F1P4 current account davranisina dokunulmadi

IMPLEMENTED — PROCUREMENT SUPPLIER PRICE AND CURRENCY UI WIRED TO CANONICAL SNAPSHOT — MANUAL SMOKE PENDING


## 19. F1P3H-A recovery delta (2026-07-15)
- Legacy draft procurement rows can now be explicitly refreshed from canonical supplier truth via `Tedarikçi Fiyatını Yenile`.
- Visible `Alış Birim Fiyatı` input now binds to the effective final purchase unit, not only manual override state.
- `Hesaplananı kullan` restores the calculated value into the visible field and preserves backend override semantics.
- Refresh route/controller/service were added with tenant, permission, draft, receipt and finalized-current-account guards.
- Edit GET remains non-mutating; no silent snapshot rewrite was introduced.
- Snapshot materialization uses exact supplier source resolution and still avoids any sales fallback.
- Request `10` audited target remains `PZ-CH60SY` with canonical supplier truth `3.5 USD -> 46.9966 -> 164.4881 -> 164.49 / 1,644.88` after explicit refresh.
- `SupplierProcurementCurrentAccountTransactionTest` drift was recovered by aligning the test to explicit manual override contract; production current-account algorithm was not changed.
- New targeted tests added:
  - `ProcurementSupplierPriceSourceAttributionTest`
  - `ProcurementSupplierExactVariantPriceTest`
  - `ProcurementSupplierPricePresenterBindingTest`
  - `ProcurementDraftPriceRefreshTest`
  - `ProcurementSupplierPriceLabelIntegrityTest`
- Broad procurement filters now pass:
  - `SupplierProcurementRequest`: 30 tests / 259 assertions
  - `ProcurementPurchasePrice`: 9 tests / 70 assertions
  - `Procurement`: 103 tests / 1575 assertions
- Manual browser smoke remains pending.
- Staging/commit still not performed.

IMPLEMENTED — PROCUREMENT SUPPLIER PRICE/CURRENCY UI + LEGACY DRAFT REFRESH RECOVERY COMPLETE — MANUAL SMOKE PENDING
