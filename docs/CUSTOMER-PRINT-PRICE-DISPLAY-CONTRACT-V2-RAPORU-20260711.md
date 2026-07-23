# Customer Print Price Display Contract V2 Raporu — 2026-07-11

## 1. Yönetici özeti
- Amaçlanan V2 müşteri-facing fiyat sözleşmesi uygulandı.
- `Baskı fiyatı gösterilsin` modunda ana ürün satırı artık yalnız ürün fiyatını gösterir.
- Baskı fiyatları aynı modda baskı alt satırlarında ayrı görünür.
- `Baskı fiyatı gösterilmesin` modunda ana ürün satırı birleşik baskı dahil fiyatı gösterir.
- Ticari toplam, ara toplam, KDV, genel toplam, sipariş ve cari semantiği değiştirilmedi.
- Commit veya staging yapılmadı.
- Nihai karar: `CUSTOMER PRINT PRICE DISPLAY V2 READY — MANUAL REVIEW`

## 2. Eski sözleşme ve kullanıcı problemi
- Önceki davranışta müşteri-facing ana satır çoğu yüzeyde her iki modda da birleşik fiyat gösteriyordu.
- Bu nedenle `baskı fiyatı gösterilsin` seçeneği ürün ve baskı fiyatını gerçekten ayırmıyordu.
- Çoklu baskı ve farklı baskı adetlerinde ana satır ile baskı satırı arasında sunum çakışması oluşuyordu.

## 3. Yeni V2 fiyat gösterim sözleşmesi
- `show_print_price_details=true`
- Ana satır: `Ürün Birim Fiyatı`, `Ürün Toplamı`
- Baskı satırları: `Baskı Birim Fiyatı`, `Baskı Toplamı`
- Yardımcı satır: `Ürün + Baskı Toplamı`
- `show_print_price_details=false`
- Ana satır: `Baskı Dahil Birim Fiyat`, `Baskı Dahil Satır Toplamı`
- Baskı satırları yalnız açıklama/adet gösterir, fiyat göstermez.
- Baskısız ürünlerde normal `Birim Fiyat` ve `Satır Toplamı` korunur.

## 4. Truth source ve merkezi presentation service
- Merkezi contract `app/Services/CustomerFacingPriceDisplayService.php` içinde genişletildi.
- Yeni canonical alanlar:
- `price_mode`
- `customer_main_unit_price`
- `customer_main_total`
- `combined_unit_price`
- `commercial_line_total`
- `main_unit_label`
- `main_total_label`
- `show_commercial_total`
- `prints[*].show_price_details`
- Snapshot, PDF, public approval ve portal builder/controller zinciri bu contracta bağlandı.
- Presentation alanları finansal truth source yapılmadı; ticari toplamlar mevcut kaynaklardan türetilmeye devam ediyor.

## 5. Gösterilsin modu
- Ana ürün satırı yalnız ürün fiyatını gösteriyor.
- Baskı satırında baskı birim ve toplam fiyatları ayrı görünüyor.
- Ürün + baskı toplamı yardımcı satır olarak ayrı gösteriliyor.

## 6. Gösterilmesin modu
- Ana ürün satırı birleşik baskı dahil fiyatı gösteriyor.
- Baskı açıklaması ve adet görünür kalıyor.
- Baskı birim/toplam fiyatları gizleniyor.

## 7. Çoklu baskı
- Aynı kalemde birden fazla baskı varsa her baskı ayrı alt satırda gösteriliyor.
- Gösterilsin modunda her baskı kendi fiyat kırılımıyla geliyor.
- Gizli modda ayrı baskı fiyatları görünmüyor; birleşik ana fiyata dahil kalıyor.

## 8. Farklı baskı adedi
- Birleşik birim fiyat artık basit toplama ile değil `(product_line_total + all_print_totals) / product_quantity` formülüyle hesaplanıyor.
- Bu davranış `CustomerFacingPriceDisplayServiceTest` ve yeni V2 snapshot testiyle doğrulandı.

## 9. Setup/hazırlık fiyatı
- Setup dağıtımının satış baskı fiyatına gömülü kalması korundu.
- Ham `setup_total_amount`, `base_print_unit_price` gibi alanlar müşteri yüzeyine sızdırılmadı.
- Gösterilsin modunda setup etkisi baskı satış fiyatının içinde temsil ediliyor.
- Gizli modda birleşik ana fiyata dahil kalıyor.

## 10. PDF sonucu
- `app/Services/PromotionQuotePdfService.php` ve `resources/views/admin/promotion-quotes/pdf.blade.php` V2 contracta bağlandı.
- Görünür modda PDF ana sütunları ürün fiyatını gösteriyor, baskı fiyatı alt satıra taşınıyor.
- Gizli modda PDF ana sütunları baskı dahil birleşik fiyatı gösteriyor.
- QR kod eklenmedi, sade PDF düzeni korundu.

## 11. Public onay sonucu
- `app/Http/Controllers/PublicQuoteApprovalController.php` snapshot alanlarını yeni ana fiyat contractıyla okumaya başladı.
- `resources/views/public/quotes/approval/show.blade.php` dinamik ana fiyat etiketlerini ve gerekli olduğunda `Ürün + Baskı Toplamı` satırını gösteriyor.

## 12. Müşteri portalı sonucu
- Quote portal detail ve order portal detail aynı contracttan besleniyor.
- Quote ve order yüzeylerinde dinamik ana fiyat etiketleri gösteriliyor.
- Order portal açıklama metni yeni sözleşmeye göre güncellendi.

## 13. E-posta/send snapshot sonucu
- Mail şablonunda satır bazlı fiyat kırılımı yok; yalnız grand total ve approval link gösteriliyor.
- Grand total semantiği değişmedi.
- `app/Services/QuoteSendSnapshotBuilder.php` içine V2 presentation alanları donduruldu.
- Snapshot artık ana fiyat modu, ürün-only/combined alanlar ve baskı görünürlüğünü açıkça saklıyor.

## 14. WhatsApp sonucu; varsa
- Audit edilen quote approval/mail/snapshot akışında fiyatlı ayrı bir WhatsApp müşteri özeti yüzeyi bulunmadı.
- Bu nedenle özel bir WhatsApp item-price contract güncellemesi uygulanmadı.

## 15. Currency davranışı
- `TRY` müşteri-facing yüzeylerde `TL` olarak normalize edilmeye devam ediyor.
- TL/USD/EUR için aynı contract kullanılacak şekilde currency display central service üzerinden korundu.
- Promotion quote/currency regresyon grubu geçti.

## 16. Snapshot immutability
- Yeni V2 alanları gönderim snapshot’ına donduruluyor.
- Ayrı bir regression testi eklendi: `tests/Feature/CustomerPrintPriceDisplayContractV2Test.php`
- Eski snapshot immutability davranışını bozan bir bulgu çıkmadı.

## 17. Güvenlik ve tenant isolation
- Public approval token scope korunuyor.
- Portal company scope ve tenant isolation testleri V2’ye hizalanıp geçti.
- Supplier cost, group code, setup raw, current account ve benzeri hassas alanlar müşteri yüzeyinde görünmüyor.

## 18. Değişen dosyalar
- `app/Services/CustomerFacingPriceDisplayService.php`
- `app/Services/QuoteSendSnapshotBuilder.php`
- `app/Services/PromotionQuotePdfService.php`
- `app/Services/CustomerPortalQuoteDataBuilder.php`
- `app/Services/CustomerPortalOrderDataBuilder.php`
- `app/Http/Controllers/PublicQuoteApprovalController.php`
- `resources/views/admin/promotion-quotes/pdf.blade.php`
- `resources/views/public/quotes/approval/show.blade.php`
- `resources/views/customer-portal/quotes/show.blade.php`
- `resources/views/customer-portal/orders/show.blade.php`
- `tests/Feature/CustomerFacingPriceDisplayServiceTest.php`
- `tests/Feature/CustomerFacingPriceWithSetupDistributionTest.php`
- `tests/Feature/PublicQuoteApprovalCustomerPriceDisplayTest.php`
- `tests/Feature/CustomerPortalQuotePriceDisplayTest.php`
- `tests/Feature/QuotePdfCustomerPriceDisplayTest.php`
- `tests/Feature/QuotePdfSetupPriceVisibilityTest.php`
- `tests/Feature/CustomerPrintPriceDisplayContractV2Test.php`
- `tests/Feature/CustomerPortalAndPublicFlowSecurityRegressionTest.php`
- `tests/Feature/CustomerPortalOrderListDetailTest.php`
- `tests/Feature/CustomerPortalQuoteListDetailTest.php`
- `tests/Feature/CustomerPortalUxPolishTest.php`

## 19. Yeni/güncellenen testler
- Güncellendi: `CustomerFacingPriceDisplayServiceTest`
- Güncellendi: `CustomerFacingPriceWithSetupDistributionTest`
- Güncellendi: `PublicQuoteApprovalCustomerPriceDisplayTest`
- Güncellendi: `CustomerPortalQuotePriceDisplayTest`
- Güncellendi: `QuotePdfCustomerPriceDisplayTest`
- Güncellendi: `QuotePdfSetupPriceVisibilityTest`
- Eklendi: `CustomerPrintPriceDisplayContractV2Test`
- Hizalandı: portal detail regression testleri ve portal UX copy testi

## 20. Hedefli test sonuçları
- `CustomerFacingPriceDisplayServiceTest`: geçti, 4 test
- `CustomerPrintPriceDisplayContractV2Test`: geçti, 1 test
- `PublicQuoteApprovalCustomerPriceDisplayTest`: geçti, 1 test
- `CustomerPortalQuotePriceDisplayTest`: geçti, 2 test
- `QuotePdfCustomerPriceDisplayTest`: geçti, 2 test
- `QuotePdfSetupPriceVisibilityTest`: geçti, 1 test
- `QuoteSend|QuoteApproval|PublicQuoteApproval`: geçti, 50 test
- `CustomerPortal|PortalQuote`: ilk turda 4 portal expectation failure verdi; V2 contracta hizalanınca ikinci turda 33 test geçti
- `PromotionQuoteCurrencySnapshotTest`: geçti, 5 test
- `PromotionQuote|Currency`: geçti, 176 test
- `OrderRevision|RepeatOrder`: geçti, 51 test
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: geçti, 60 test

## 21. Development DB sayaçları
- `tenants = 6`
- `tenant_catalog_products = 18032`
- `orders = 31`
- Bu sayaçlarda uygulama sonrası beklenmeyen artış/azalış görülmedi.

## 22. Local browser/PDF smoke
- Test runtime doğrulaması: `phpunit.xml` içinde `DB_CONNECTION=sqlite` ve `DB_DATABASE=:memory:` mevcut.
- PDF smoke davranışı render tabanlı testlerle doğrulandı.
- Local SAKLImavi browser smoke bu turda çalıştırılmadı.
- Bloker: Bu turn görünür konuşma bağlamında kullanılabilir local SAKLImavi giriş bilgisi yoktu; bu nedenle canlı browser login smoke güvenli şekilde tamamlanmadı.

## 23. Final Git durumu
- `HEAD`: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Staged alan finalde boş.
- Commit yapılmadı.
- Worktree baştan beri kirliydi; kapsam dışı değişikliklere dokunulmadı.

## 24. Kalan riskler
- Local browser smoke ve görsel PDF incelemesi manuel olarak hâlâ yapılmalı.
- Git, bazı dosyalarda gelecekte CRLF/LF uyarısı verebilir; davranışsal etki görülmedi ancak repo EOL politikası ayrıca izlenmeli.
- Mail yüzeyi itemize fiyat kırılımı göstermediği için V2 contractın e-posta tarafı grand total seviyesinde güvence altında; item satırı içeren yeni bir mail template eklenirse bu contract yeniden kullanılmalı.

## 25. Nihai karar
- `CUSTOMER PRINT PRICE DISPLAY V2 READY — MANUAL REVIEW`

## 26. Kullanıcının manuel kontrol adımları
1. Local admin ile bir baskılı teklif aç.
2. `Baskı fiyatı gösterilsin` seç.
3. PDF, public approval ve müşteri portalında ana ürün satırının yalnız ürün fiyatını gösterdiğini doğrula.
4. Aynı kayıtta baskı alt satırında baskı birim/toplam fiyatlarının ayrı göründüğünü doğrula.
5. `Baskı fiyatı gösterilmesin` seç.
6. Aynı yüzeylerde ana fiyatın birleşik olduğunu, baskı açıklamasının kaldığını ve baskı fiyatlarının gizlendiğini doğrula.
7. Ara toplam, KDV ve genel toplamın iki modda aynı kaldığını doğrula.
8. Public approval linkini admin teklif ekranındaki `Teklifi İncele` akışından aç.
9. Portal quote detail için `http://saklimavi.prodelya_core.test/musteri-portal/teklifler/{quoteId}` yolunu kullan.
10. Gerekirse admin PDF route üzerinden teklif PDF’ini aç ve 6–7 ürün senaryosunda kompaktlığı görsel kontrol et.
