# Notification / WhatsApp / Phone Checkpoint Commit Apply Raporu — 2026-07-09

## 1. Ozet

- Yeni kod yazildi mi? Hayir.
- Kac commit olusturuldu? Bu checkpoint icin 3 commit planlandi; bu rapor yazilirken 2 islevsel commit olusturuldu, docs commit ayrica uygulanacaktir.
- Migration calistirildi mi? Hayir.
- DB'ye dokunuldu mu? Hayir.
- Product Hub'a dokunuldu mu? Hayir.
- Revision A-B-C'ye dokunuldu mu? Hayir.
- Public approval checkpoint'e dokunuldu mu? Hayir. Mevcut committed sanitization davranisi korunmustur.

## 2. Commit Listesi

### Commit A

- Mesaj: `notifications: add phone normalization and whatsapp link support`
- Hash: `80248cf`
- Dosyalar:
  - `app/Services/PhoneNumberNormalizer.php`
  - `app/Services/Notifications/TenantWhatsappLinkService.php`
  - `tests/Feature/TurkishPhoneNumberNormalizeTest.php`
  - `tests/Feature/WhatsappLinkUsesNormalizedPhoneTest.php`
  - `tests/Feature/CompanyPhoneDisplayFormatTest.php`
  - `tests/Feature/CompanyWhatsappPhoneAllowsFixedLineTest.php`
- Test sonucu:
  - Commit oncesi: passed, 7 test / 41 assertion
  - Commit sonrasi: passed, 7 test / 41 assertion

### Commit B

- Mesaj: `notifications: improve quote notification message formatting`
- Hash: `ddd51f5`
- Dosyalar:
  - `app/Services/Notifications/NotificationEventService.php`
  - `app/Services/Notifications/NotificationTemplateDefaultSeederService.php`
  - `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`
- Test sonucu:
  - Commit oncesi: passed, 12 test / 139 assertion
  - Commit sonrasi: passed, 12 test / 139 assertion

### Commit C

- Mesaj: `quotes: wire send channel actions for customer quote delivery`
- Sonuc: uygulanmadi
- Neden:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php` icindeki send-channel hunks ayni diff icinde quote list filtreleri, revision bloklari ve diger buyuk degisikliklerle karismis durumda.
  - `resources/views/admin/promotion-quotes/show.blade.php` icindeki send modal / channel JS hunks buyuk quote detail layout refactoru, sticky action bar, convert modal ve tab yapisiyla ayni diff yapisina bagli.
  - Bu nedenle guvenli manuel patch staging uygulanmadan dosya bazli staging riskli bulundu.

### Commit D

- Mesaj: `docs: add notification whatsapp phone checkpoint reports`
- Kapsam:
  - `docs/NOTIFICATION-WHATSAPP-PHONE-CHECKPOINT-PREP-RAPORU-20260709.md`
  - `docs/NOTIFICATION-WHATSAPP-PHONE-HUNK-STAGING-PREP-RAPORU-20260709.md`
  - `docs/NOTIFICATION-WHATSAPP-PHONE-CHECKPOINT-COMMIT-APPLY-RAPORU-20260709.md`
- Not:
  - Bu rapor dosyasi docs commitinin icinde olacagi icin hash final ciktiyla birlikte verilecektir.

## 3. Hunk Staging Notlari

- `PromotionQuoteController.php`
  - Send-channel methodleri (`buildSendSuccessMessage`, `normalizeSendRecipientData`, `openWhatsappLink`, `sendToCustomer`) hedef kapsamdaydi.
  - Ancak ayni diff icinde `index()` filtreleme, revision compare/apply ve diger ilgisiz degisiklikler bulundugu icin bu fazda alinmadi.

- `show.blade.php`
  - Gonderim modali, `sent_channel`, kanal secimi ve preview JS hedef kapsamdaydi.
  - Ancak buyuk detail UI refactoru, sticky alt bar, convert modal ve sekme altyapisiyla karismis oldugu icin bu fazda alinmadi.

- `index.blade.php`
  - Bilincli olarak disarida birakildi.
  - Notification / WhatsApp / phone checkpoint kapsamina girmiyor.

- `NotificationEventService.php`
  - Guvenli sekilde ayrildi ve Commit B'ye alindi.

- `TenantWhatsappLinkService.php`
  - Guvenli sekilde ayrildi ve Commit A'ya alindi.

- `PhoneNumberNormalizer.php`
  - Guvenli sekilde ayrildi ve Commit A'ya alindi.

## 4. Disarida Birakilanlar

- `resources/views/admin/promotion-quotes/index.blade.php`
- quote/order buyuk UI refactoru
- public approval panelleri
- revision bloklari
- Product Hub dosyalari ve hunklari
- `.tmp`, `.env`, DB, log, screenshot, debug ciktilari
- `public/css/prodelya-admin.css`

## 5. Guvenlik Sonucu

- WhatsApp phone normalization:
  - korunuyor
  - `0212`, `0532`, `+90`, `0090`, bosluklu formatlar destekleniyor

- Fixed-line support:
  - korunuyor
  - sabit hat numaralari icin WhatsApp Business link uretilmesi testlerle dogrulandi

- E-posta bagimsiz WhatsApp link:
  - service/core seviyesinde korunuyor
  - controller/UI tarafindaki wiring Commit C bilincli olarak ertelendi

- Public approval token log redaction korunuyor mu?
  - evet
  - bu turn'de committed sanitization davranisini bozan yeni bir degisiklik alinmadi

- Notification log hassas veri sizintisi var mi?
  - final test matrisinde negatif bulgu yok
  - ilgili sanitization ve integration testleri gecti

## 6. Final Test Sonuclari

1. `php artisan test --filter="WhatsappLinkUsesNormalizedPhone|PhoneNumberNormalizer|CompanyPhoneDisplayFormat|CompanyWhatsappPhoneAllowsFixedLine|TurkishPhoneNumberNormalize"`
   - passed, 7 test / 41 assertion

2. `php artisan test --filter="QuoteNotificationIntegration|NotificationPublicApprovalTokenSanitization|PromotionQuoteCustomerMailHotfix"`
   - passed, 12 test / 139 assertion

3. `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone"`
   - passed, 20 test / 113 assertion

4. `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`
   - passed, 34 test / 324 assertion

5. `php artisan test --filter="PromotionQuote|OrderRevision|RepeatOrder"`
   - passed, 177 test / 1504 assertion

6. `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
   - passed, 14 test / 111 assertion

## 7. Full Suite Durumu

- Full suite zorunlu degildi.
- Bu fazda full suite calistirilmadi.
- Hedeflenmis regression matrisi eksiksiz olarak calistirildi ve tamamı gecti.

## 8. Kalan Worktree Durumu

- quote/order UI grubu kaldi mi?
  - evet
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Models/Order.php`
  - `resources/views/admin/orders/*`
  - ilgili order/quote UI testleri

- index/show buyuk UI kaldi mi?
  - evet
  - `resources/views/admin/promotion-quotes/index.blade.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`

- `public/css/prodelya-admin.css` kaldi mi?
  - evet
  - bu checkpoint'e alinmadi

## 9. Net Karar

- Notification / WhatsApp / Phone checkpoint tamamen kapandi mi?
  - kismen
  - service/core ve notification formatting cekirdegi guvenli sekilde commit zincirine alindi
  - controller/UI wiring tarafi bilincli olarak disarida birakildi

- Commit C neden uygulanmadi?
  - `PromotionQuoteController.php` ve `show.blade.php` icindeki hedef hunks, bu fazin disindaki buyuk UI / revision / listeleme degisiklikleriyle ayni diff icinde yer aliyor
  - kullanici talimatina uygun olarak riskli staging zorlanmadi

## 10. Sonraki Oneri

- quote/order buyuk UI checkpoint prep
- template master plan
- kalan docs/test cleanup

Bu fazda notification / WhatsApp / phone cekirdegi guvenli sekilde ayrildi; quote/order buyuk UI ve template degisiklikleri sonraki checkpoint'e birakildi.
