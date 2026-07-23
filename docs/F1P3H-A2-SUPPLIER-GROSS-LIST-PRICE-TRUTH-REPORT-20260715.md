# F1P3H-A2 Supplier Gross List Price Truth Report - 2026-07-15

## Kapsam
- Faz: `PRODELYA_V1_10.16.7_F1P3H_A2_SUPPLIER_GROSS_LIST_PRICE_TRUTH_PROMPT`
- Amaç: Akdeniz tedarikçi feed'inde gross liste fiyatını procurement truth olarak ayırmak, `netfiyat`ı ayrı metadata olarak korumak, satış ve alış iskontosu ayrımını testlerle kanıtlamak.
- Kısıtlar: Cari algoritmasına dokunulmadı. Cancel restore başlatılmadı. Staging ve commit yapılmadı.

## 1. Ham Kanıt: Akdeniz Raw Satır
Read-only doğrulama ile exact varyant raw kaydı:
- `supplier_product_variants_raw.id = 8258`
- `product_code = AK-1020-KIRMIZI`
- `raw_payload.listefiyati = "30.50"`
- `raw_payload.listefiyatkapali = "30.50"`
- `raw_payload.netfiyat = "16.78"`
- `raw_payload.iskonto = "0.45"`
- `raw_payload.kur = "TL"`
- `normalized_payload.list_price = 30.5`
- `normalized_payload.purchase_price = 16.78`
- `normalized_payload.net_price = 16.78`

Bu kanıt, supplier gross liste truth'unun `30,50 TL`, feed net referansının ise `16,78 TL` olduğunu net biçimde ayırır.

## 2. Hatanın Exact Kanıtı
Aynı turda procurement legacy drift satırı read-only doğrulandı:
- `supplier_procurement_request_items.id = 19`
- `request_no = TS-2026-0012`
- `purchase_source_amount = 16.78`
- `purchase_source_currency = TRY`
- `purchase_list_price_try = 16.78`
- `discount_rate = 0`
- `purchase_unit_price = 16.78`
- `purchase_total = 167.8`
- `purchase_price_snapshot.source_type = supplier_product_variant_raw_purchase_price`
- `purchase_price_snapshot.source_id = 8258`

Bu durum yanlıştı; sistem `netfiyat`ı supplier gross liste gibi materialize ediyordu.

## 3. 30,50 / 16,78 Attribution
Akdeniz raw feed semantiği A2 kapsamında şu şekilde kanıtlandı:
- Supplier gross liste: `30,50 TL`
- Feed net referansı: `16,78 TL`
- Raw `iskonto = 0.45` alanı bu ailede satış yüzeyindeki `%45` indirimi semantik olarak açıklar.
- Formül: `30,50 x (1 - 45/100) = 16,775`
- UI yuvarlama: `16,78 TL`

Sonuç: `16,78 TL` supplier gross liste değil, indirimin uygulanmış net referansıdır.

## 4. Yeni Resolver Sözleşmesi
`app/Services/Procurement/SupplierPurchasePriceSourceResolver.php` supplier-profile bazlı gross-list sözleşmesine geçirildi.

Akdeniz için procurement truth sırası:
1. `raw_payload.listefiyati`
2. `raw_payload.listefiyatkapali`
3. normalize gross-list alanları / `source_price`

Ayrı metadata:
- `raw_payload.netfiyat` => supplier net referansı
- `source_field`
- `source_kind`

Kritik değişiklik:
- Akdeniz procurement truth'unda global `purchase_price` önceliği kaldırıldı.
- Legacy draft refresh artık `16,78`i supplier liste diye yeniden materialize etmiyor.

## 5. Normalizasyon Metadata Genişlemesi
`app/Services/ProductDataHub/PreviewParserService.php` içine Akdeniz için şu metadata alanları eklendi:
- `supplier_gross_list_price`
- `supplier_gross_list_price_source_field`
- `supplier_net_price`
- `supplier_feed_discount_rate`
- `supplier_net_price_source_field`

Böylece gross ve net referans aynı payload içinde karışmadan izlenebilir hale geldi.

## 6. Procurement Snapshot Genişlemesi
`app/Services/Procurement/ProcurementPurchasePricingService.php` içinde snapshot'a şu attribution alanları eklendi:
- `source_field`
- `source_kind`

Bu sayede procurement snapshot'ta “hangi field'dan, hangi semantik sözleşmeyle” geldiği taşınıyor.

## 7. UI / Presenter Uyumları
`app/Services/SupplierProcurementRequestDataBuilder.php` üzerinde kaynak para birimi UI gösterimi `TRY -> TL` olacak şekilde hizalandı.

## 8. Satış ve Alış İskontosu Ayrımı
A2 sözleşmesi testlerle şu ayrımı korudu:
- Satış: `30,50 - %45 = 16,775` => `order_items.discount_rate`, `unit_price`
- Alış: `30,50 - %55 = 13,725` => `supplier_procurement_request_items.discount_rate`, `purchase_unit_price`

Bu ayrım production current-account algoritmasına dokunmadan kanıtlandı.

## 9. Kâr Sözleşmesi
Canonical TL örneği:
- Gross liste: `30,50 TL`
- Final satış birim: `16,78 TL`
- Final alış birim: `13,73 TL`
- Birim ürün kârı: `3,05 TL`
- `100` adet toplam kâr: `305,00 TL`

Precise snapshot değerleri:
- Sales calculated: `16.775`
- Purchase calculated: `13.725`

## 10. Eklenen / Güncellenen Testler
Yeni testler:
- `tests/Feature/AkdenizSupplierGrossListPriceMappingTest.php`
- `tests/Feature/ProcurementSupplierGrossListSourceTest.php`
- `tests/Feature/ProcurementSalesPurchaseDiscountIsolationTest.php`
- `tests/Feature/ProcurementGrossListRefreshTest.php`
- `tests/Feature/ProductSalesPurchaseUnitMarginContractTest.php`

Güncellenen testler:
- `tests/Feature/ProcurementPurchasePriceSourceResolverTest.php`
- `tests/Feature/ProcurementSupplierPriceLabelIntegrityTest.php`
- `tests/Feature/SupplierProcurementRequestPriceReferenceTest.php`

## 11. Test Sonuçları
Hedefli PASS:
- `AkdenizSupplierGrossListPriceMappingTest`
- `ProcurementSupplierGrossListSourceTest`
- `ProcurementSalesPurchaseDiscountIsolationTest`
- `ProcurementGrossListRefreshTest`
- `ProductSalesPurchaseUnitMarginContractTest`
- `ProcurementSupplierPriceLabelIntegrityTest`
- `ProcurementPurchasePriceSourceResolverTest`
- `SupplierProcurementRequestPriceReferenceTest`
- Toplam: `14 tests`, `100 assertions`, PASS

İlgili kapılar PASS:
- `ProcurementPurchasePriceSnapshotTest`
- `ProcurementPurchasePriceCurrencyIsolationTest`
- `SupplierProcurementCurrentAccountTransactionTest`
- `ProcurementDraftPriceRefreshTest`
- `SupplierProcurementRequestUiTest`
- `SupplierProcurementRequestPriceReferenceTest`

Broad PASS:
- `SupplierProcurementRequest` => `30 tests`, `259 assertions`
- `ProcurementPurchasePrice` => `9 tests`, `70 assertions`
- `Procurement` => `107 tests`, `1604 assertions`
- `PromotionQuote` => `162 tests`, `1445 assertions`
- `ProcessDepth` => `47 tests`, `452 assertions`
- `AdminSmokeTest` => `59 tests`, `214 assertions`

## 12. Fiyat Süreç HTML Güncellemesi
Broad testler PASS olduktan sonra `docs/PRICE-CURRENCY-DATA-LINEAGE-MAP-20260715.html` şu şekilde düzeltildi:
- Akdeniz mapping tablosunda gross liste ve net referans ayrıldı.
- TL satış örneği `AK-1020-KIRMIZI / 30,50 TL / %45 / 16,78 TL` olarak düzeltildi.
- TL procurement örneği `30,50 TL / %55 / 13,73 TL / 1.372,50 TL` zincirine taşındı.
- Birim ve toplam kâr örneği `3,05 TL / 305,00 TL` olarak işlendi.
- Eski `16,78 TL = Tedarikçi Liste` anlatımı kaldırıldı.

## 13. Manuel Smoke Durumu
Bu prompt kapsamında manuel smoke henüz kullanıcı tarafından kapatılmadı.
- Durum: `PENDING USER MANUAL SMOKE`

## 14. Worktree / Git Durumu
- Worktree kirli durumda bırakıldı; ilgisiz değişikliklere dokunulmadı.
- Staging yapılmadı.
- Commit yapılmadı.

## 15. Sonuç
A2 fazı implementasyon, attribution ve test kapıları açısından kapatıldı:
- Gross supplier liste truth procurement tarafında ayrıştırıldı.
- `netfiyat` artık ayrı metadata semantiğiyle tutuluyor.
- Legacy draft refresh gross-list contract'a hizalandı.
- Cari algoritması değişmeden bırakıldı.
- Sonraki olası fazlar için cancel restore hâlâ ayrı bir konu olarak bekliyor.
