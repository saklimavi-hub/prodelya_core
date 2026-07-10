# Notification / WhatsApp / Phone Hunk Staging Prep Raporu — 2026-07-09

## 1. Ozet

Bu fazda herhangi bir staging, commit, rollback veya kod degisikligi yapilmadi. Amac, mevcut calisma agacindaki degisiklikler icinde `Notification / WhatsApp / Phone` kapsaminda guvenle ayrilabilecek hunk sinirlarini belirlemek ve commit-plani acisindan riskli ortak dosyalari netlestirmektir.

Net sonuc:

- `PhoneNumberNormalizer.php` ve `TenantWhatsappLinkService.php` notification/phone cekirdegi olarak temiz ayrisiyor.
- `NotificationEventService.php` ve `NotificationTemplateDefaultSeederService.php` notification body/log sanitization ve WhatsApp metin bicimi tarafinda ayri bir notification commitine uygun.
- `PromotionQuoteController.php` ile `resources/views/admin/promotion-quotes/show.blade.php` icindeki send-channel hunks teknik olarak ayristirilabilir, ancak dosyalar yogun sekilde public approval / revision / buyuk UI refactor degisiklikleriyle karismis durumda.
- `resources/views/admin/promotion-quotes/index.blade.php` notification checkpoint kapsamina alinmamali.
- Hunk staging uygulanacaksa en yuksek risk ortak dosyalar `PromotionQuoteController.php` ve `show.blade.php` dosyalaridir.

## 2. PromotionQuoteController Hunk Analizi

Dosya: [PromotionQuoteController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/PromotionQuoteController.php)

Notification / send-channel kapsaminda kalabilecek hunklar:

- `buildSendSuccessMessage(Order $quote, ?string $sentChannel = null)`
  - `manual`, `email`, `whatsapp_link` kanal bazli basari mesaji uretimi
  - notification/send UX kapsaminda
  - Commit C adayi
- `normalizeSendRecipientData()`
  - primary contact / customer fallback ile ad, e-posta, telefon toplama
  - send-channel veri hazirlama kapsaminda
  - Commit C adayi
- `openWhatsappLink()`
  - eski manuel metin kurulumunu kaldirip `TYPE_QUOTE_LINK` kullanimina geciyor
  - telefon dogrulama metinlerini genellestiriyor
  - `public_link`, `quote_number`, `related_type`, `related_id` baglami ekliyor
  - Commit C adayi
- `sendToCustomer()`
  - `sent_channel` ayrimi
  - `manual` icin e-posta zorunlulugu
  - `email` icin `force_email_preview`
  - `whatsapp_link` icin telefon zorunlulugu, e-posta zorunlu degil
  - WhatsApp akisinda approval request + manual link olusturma
  - `InvalidArgumentException` yakalama
  - Commit C adayi

Notification disinda kalmasi gereken hunklar:

- public approval akisina ait approval request / approval ekran davranislari
- revision compare/apply ve source order baglami
- quote list / index filtreleme ve `active|converted|archived|all` gorunum mantigi
- Product Hub ile ilgili metin / encoding / kategori duzeltmeleri
- whitespace / line ending churn

Siniflandirma:

- A notification/send-channel: yukaridaki 4 ana method blogu
- B public approval: approval request / approval link / approval ekranina bagli kisimlar
- C revision A-B-C: revision compare/apply ile ilgili kisimlar
- D Product Hub: urun/kategori tarafi duzeltmeler
- E quote/order UI/list: listeleme, filtreleme, gorunum secimleri

Karar:

- Dosya komple staged edilmemeli.
- Yalnizca send-channel hunks secilirse Commit C'ye alinabilir.
- Bu dosyada yanlis hunk secimi public approval veya revision islerini notification commitine sizdirma riski tasir.

## 3. show.blade.php Hunk Analizi

Dosya: [show.blade.php](/C:/laragon/www/prodelya_core/resources/views/admin/promotion-quotes/show.blade.php)

Notification / send-channel kapsaminda kalabilecek hunklar:

- send modal icinde kanal secimi:
  - `Standart Gonderim`
  - `E-posta Onizleme`
  - `WhatsApp Link`
- gizli `sent_channel` inputu
- alici alanlarinin approval request / customer bilgisinden doldurulmasi
- telefon helper text:
  - mobil ve sabit hat ornekleri (`05xx...` veya `0212...`)
- preview / helper metinleri:
  - WhatsApp Link icin e-posta zorunlu degil
  - Standart Gonderim mail ister
  - E-posta Onizleme dis e-posta gondermez
  - WhatsApp Link yalniz telefon ile calisir
- istemci tarafi JS:
  - `channelValues = ['manual', 'email', 'whatsapp_link']`
  - WhatsApp preview icin public URL'yi ayri satira tasiyan mantik
  - kanal bazli helper text uretimi
  - modal ac/kapa akisi

Notification disinda kalmasi gereken hunklar:

- sayfa topbar / genel layout redesign
- tab yapisi
- sticky action bar
- convert modal redesign
- approval summary / approval card / detay panelleri
- buyuk quote detail UI refactoru

Siniflandirma:

- A send-channel/notification: modal + helper text + send preview JS
- B public approval: approval kartlari, approval ozeti, public approval baglamli paneller
- C revision/repeat: revision / tekrar siparisle ilgili bloklar
- D big quote detail UI: genel sayfa iskeleti ve buyuk UI duzenlemeleri

Karar:

- Teknik olarak ayristirilabilir, fakat yuksek risklidir.
- Bu dosya icin patch staging sarttir; dosya bazli staging uygun degildir.
- Send modal ve ilgili JS haricindeki bloklar notification commitine alinmamalidir.

## 4. index.blade.php Hunk Analizi

Dosya: [index.blade.php](/C:/laragon/www/prodelya_core/resources/views/admin/promotion-quotes/index.blade.php)

Gozlenen degisiklikler:

- gorunum mantigi `active`, `converted`, `archived`, `all`
- ust aksiyonlarin approval-state kisayollarindan filtre bazli yapiya gecmesi
- istatistik kartlari ve chip bar degisimi
- tablo kolonlari ve bos durum metinlerinin degismesi
- siparise donusen tekliflerin ayri gorunume tasinmasi

Siniflandirma:

- Notification / WhatsApp / Phone kapsamina girmez
- Quote/order list UI checkpoint kapsamina girer

Karar:

- Notification checkpoint veya send-channel checkpoint icine alinmamali
- Ayrica `PromotionQuoteController.php` icindeki listeleme/filter hunklariyla baglidir

## 5. Notification Service Hunk Analizi

### 5.1 PhoneNumberNormalizer

Dosya: [PhoneNumberNormalizer.php](/C:/laragon/www/prodelya_core/app/Services/PhoneNumberNormalizer.php)

Bulgu:

- `normalizeTurkishPhoneForWhatsapp()` sabit hatlari da kapsayacak sekilde genisletilmis
- `normalizeTurkishMobileForWhatsapp()` geriye donuk uyumluluk icin bu metoda delegasyon yapiyor
- `toWhatsappDialString()` ve display formatting ayni normalize mantigini kullaniyor
- `[2345]` ile baslayan 10 haneli ulusal prefix artik gecerli

Karar:

- Tamamen Commit A kapsamina uygun
- Ayrisimi temiz

### 5.2 TenantWhatsappLinkService

Dosya: [TenantWhatsappLinkService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/TenantWhatsappLinkService.php)

Bulgu:

- preview ve manual link olusturma akisi `quote_number` kullaniyor
- WhatsApp mesaji artik public URL'yi ayri satira koyuyor
- hata metni `cep telefonu` yerine `telefon numarasi`
- `related_type` ve `related_id` baglami eklenmis

Karar:

- Tamamen Commit A kapsamina uygun
- Ayrisimi temiz

### 5.3 NotificationEventService

Dosya: [NotificationEventService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/NotificationEventService.php)

Bulgu:

- `quote_sent_to_customer` + `whatsapp_link` icin body normalize ediliyor
- duplicate URL ayiklama ve public URL'yi tek, temiz, ayri satirda koruma mantigi eklenmis

Karar:

- Commit B kapsamina uygun
- Notification body sanitization/formatting degisikligidir

### 5.4 NotificationTemplateDefaultSeederService

Dosya: [NotificationTemplateDefaultSeederService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/NotificationTemplateDefaultSeederService.php)

Bulgu:

- varsayilan quote WhatsApp metni inline URL yerine satir sonu ile ayrilmis URL formatina gecmis

Karar:

- Commit B kapsamina uygun
- Template/metin format uyumu saglar

### 5.5 TenantSmtpMailerService

Dosya: [TenantSmtpMailerService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/TenantSmtpMailerService.php)

Bulgu:

- Incelenen durumda notification/public approval mail davranisi mevcut, fakat bu turn icinde unstaged diff gorunmuyor

Karar:

- Yeni notification checkpoint icinde ek staging hedefi olmamali
- Bu dosya ancak gercek unstaged hunk varsa yeniden degerlendirilmelidir

### 5.6 NotificationDispatchService

Dosya: [NotificationDispatchService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/NotificationDispatchService.php)

Bulgu:

- Public approval token sanitization davranisinin committed oldugu goruluyor
- Bu turn icinde unstaged diff gorunmuyor

Karar:

- Bu fazda yeniden staging adayi olarak ele alinmamali

## 6. Net Commit Plani

### Commit A — Phone normalization + WhatsApp link core

Onerilen dosyalar:

- [PhoneNumberNormalizer.php](/C:/laragon/www/prodelya_core/app/Services/PhoneNumberNormalizer.php)
- [TenantWhatsappLinkService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/TenantWhatsappLinkService.php)
- [TurkishPhoneNumberNormalizeTest.php](/C:/laragon/www/prodelya_core/tests/Feature/TurkishPhoneNumberNormalizeTest.php)
- [WhatsappLinkUsesNormalizedPhoneTest.php](/C:/laragon/www/prodelya_core/tests/Feature/WhatsappLinkUsesNormalizedPhoneTest.php)
- [CompanyPhoneDisplayFormatTest.php](/C:/laragon/www/prodelya_core/tests/Feature/CompanyPhoneDisplayFormatTest.php)
- [CompanyWhatsappPhoneAllowsFixedLineTest.php](/C:/laragon/www/prodelya_core/tests/Feature/CompanyWhatsappPhoneAllowsFixedLineTest.php)

Not:

- Bu commit notification/controller/UI bagimliliklarindan en temiz ayrisan parcadir.

### Commit B — Notification message formatting + sanitization alignment

Onerilen dosyalar:

- [NotificationEventService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/NotificationEventService.php)
- [NotificationTemplateDefaultSeederService.php](/C:/laragon/www/prodelya_core/app/Services/Notifications/NotificationTemplateDefaultSeederService.php)
- [QuoteNotificationIntegrationTest.php](/C:/laragon/www/prodelya_core/tests/Feature/QuoteNotificationIntegrationTest.php)
- [NotificationPublicApprovalTokenSanitizationTest.php](/C:/laragon/www/prodelya_core/tests/Feature/NotificationPublicApprovalTokenSanitizationTest.php)
- [PromotionQuoteCustomerMailHotfixTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteCustomerMailHotfixTest.php)

Not:

- `TenantSmtpMailerService.php` ve `NotificationDispatchService.php` icin mevcut turn'de unstaged diff gorulmedigi icin yeni staging listesine varsayilan olarak alinmamali.
- Daha once committed sanitization degisikliklerinin tekrar staging kapsaminda dusunulmesi yanlis olur.

### Commit C — Promotion quote send-channel controller/UI hunks

Onerilen hunk kapsami:

- [PromotionQuoteController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/PromotionQuoteController.php)
  - yalnizca `buildSendSuccessMessage`, `normalizeSendRecipientData`, `openWhatsappLink`, `sendToCustomer` ile ilgili send-channel hunks
- [show.blade.php](/C:/laragon/www/prodelya_core/resources/views/admin/promotion-quotes/show.blade.php)
  - yalnizca send modal, helper text, preview JS, `sent_channel` hidden inputu
- testler:
  - [PromotionQuoteSendChannelHotfixTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteSendChannelHotfixTest.php)
  - [PromotionQuoteSendActionsUxTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteSendActionsUxTest.php)
  - [PromotionQuoteDetailSendChannelUiTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteDetailSendChannelUiTest.php)
  - [PromotionQuoteDetailWhatsappUiRuleTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteDetailWhatsappUiRuleTest.php)
  - [PromotionQuoteDetailPhoneHelperTextTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteDetailPhoneHelperTextTest.php)
  - [PromotionQuoteDetailSendHotfixRegressionTest.php](/C:/laragon/www/prodelya_core/tests/Feature/PromotionQuoteDetailSendHotfixRegressionTest.php)

Not:

- Bu commit ancak manuel hunk secimi ile guvenli olabilir.
- Dosya bazli `git add` cok yuksek karisma riski tasir.

### Commit D — Documentation only

Onerilen dosyalar:

- bu rapor
- faza ait diger kontrol raporlari

## 7. Ortak Dosya Risk Tablosu

| Dosya | Risk | Neden | Oneri |
|---|---|---|---|
| `app/Http/Controllers/Admin/PromotionQuoteController.php` | Cok yuksek | notification, public approval, revision, Product Hub, listeleme ayni diff icinde | Yalniz patch staging, method-bazli secim |
| `resources/views/admin/promotion-quotes/show.blade.php` | Cok yuksek | send modal hunks buyuk quote detail UI ve approval panelleriyle karismis | Yalniz patch staging, blok-bazli secim |
| `resources/views/admin/promotion-quotes/index.blade.php` | Orta | notification ile ilgisiz ama ayni feature kumesinde | Notification commitlerinden tamamen disla |
| `app/Services/Notifications/NotificationEventService.php` | Dusuk | konu tekil ve notification odakli | Dosya bazli staging uygun |
| `app/Services/Notifications/TenantWhatsappLinkService.php` | Dusuk | konu tekil ve WhatsApp link core odakli | Dosya bazli staging uygun |
| `app/Services/PhoneNumberNormalizer.php` | Dusuk | konu tekil ve phone normalization odakli | Dosya bazli staging uygun |
| `app/Services/Notifications/NotificationTemplateDefaultSeederService.php` | Dusuk | notification template format degisikligi | Dosya bazli staging uygun |

## 8. Test Sonuclari

Calistirilan filtreler:

1. `php artisan test --filter="WhatsappLinkUsesNormalizedPhone|PhoneNumberNormalizer|CompanyPhoneDisplayFormat|CompanyWhatsappPhoneAllowsFixedLine|TurkishPhoneNumberNormalize"`
   - Sonuc: passed
   - 7 test, 41 assertion

2. `php artisan test --filter="QuoteNotificationIntegration|NotificationPublicApprovalTokenSanitization|PromotionQuoteCustomerMailHotfix"`
   - Sonuc: passed
   - 12 test, 139 assertion

3. `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone"`
   - Sonuc: passed
   - 20 test, 113 assertion

4. `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`
   - Sonuc: passed
   - 34 test, 324 assertion

5. `php artisan test --filter="PromotionQuote|OrderRevision|RepeatOrder"`
   - Sonuc: passed
   - 177 test, 1504 assertion

6. `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
   - Sonuc: passed
   - 14 test, 111 assertion

Genel degerlendirme:

- Notification / WhatsApp / phone degisiklikleri testlerle destekleniyor.
- Public approval, revision/repeat order ve Product Hub regresyon paketlerinde de bu turn icinde hata gorulmedi.
- Teknik risk staging ayriminda; davranissal risk test bazinda su anda dusuk gorunuyor.

## 9. Net Karar

`Notification / WhatsApp / Phone` icin staging prep acisindan ilerlenebilir, ancak bu yalniz kontrollu hunk secimiyle guvenlidir.

Ozellikle:

- Commit A ve Commit B dosya bazli staginge uygun gorunuyor.
- Commit C ancak manuel patch staging ile yapilmali.
- `index.blade.php` bu checkpointten tamamen cikarilmali.
- `PromotionQuoteController.php` ve `show.blade.php` icinde public approval / revision / buyuk UI refactor hunklarinin notification commitlerine sizmamasi kritik.

## 10. Sonraki Adim

Bir sonraki fazda uygulanmasi gereken en guvenli sira:

1. Commit A dosyalarini ayri al
2. Commit B notification-format dosyalarini ayri al
3. Commit C icin `PromotionQuoteController.php` ve `show.blade.php` uzerinde satir/hunk bazli secim yap
4. Ortak dosyalarda secimden sonra ayni test filtrelerini tekrar kos

Bu rapor sadece analiz ve hazirlik amaciyla uretilmistir; bu fazda staging veya commit uygulanmamistir.
