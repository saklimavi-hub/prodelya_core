# Public Approval Checkpoint Prep Raporu — 2026-07-09

## 1. Özet
- Yeni kod yazıldı mı?
  Hayır. Bu faz yalnız inceleme, test ve raporlama fazı olarak yürütüldü.
- Staging/commit yapıldı mı?
  Hayır.
- Product Hub'a dokunuldu mu?
  Hayır.
- Revision A-B-C'ye dokunuldu mu?
  Hayır. Yalnız mevcut kirli worktree içindeki public approval grubu analiz edildi.

## 2. Kalan Git Durumu
- Modified:
  `app/Http/Controllers/PublicQuoteApprovalController.php`, `app/Services/QuoteApprovalService.php`, `app/Http/Controllers/Admin/PromotionQuoteController.php`, `routes/web.php`, `resources/views/public/quotes/approval/show.blade.php`, `public/css/prodelya-admin.css`, notification servis dosyaları ve çok sayıda admin/UI/test dosyası kirli durumda.
- Untracked:
  `app/Mail/QuoteCustomerApprovalMail.php`, `resources/views/emails/quote-customer-approval.blade.php`, `tests/Feature/PublicQuoteApprovalDecisionActionsTest.php`, `tests/Feature/PublicQuoteApprovalNoSensitiveLeakTest.php`, `tests/Feature/PublicQuoteApprovalResponsiveStructureTest.php`, `tests/Feature/PublicQuoteApprovalShowsItemsAndPrintRowsTest.php`, `tests/Feature/PublicQuoteApprovalShowsQuoteMetaTest.php`, `tests/Feature/PublicQuoteApprovalTemplateLayoutTest.php`, `tests/Feature/PublicQuoteApprovalTurkishTerminologyTest.php`, `tests/Feature/PublicQuoteApprovalVatSingleLineTest.php`, `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`, `tests/Feature/Concerns/BuildsPublicQuoteApprovalFixtures.php` ve ek UI/test dosyaları.
- Public approval dosyaları:
  `app/Http/Controllers/PublicQuoteApprovalController.php`
  `app/Services/QuoteApprovalService.php`
  `app/Mail/QuoteCustomerApprovalMail.php`
  `resources/views/public/quotes/approval/show.blade.php`
  `resources/views/emails/quote-customer-approval.blade.php`
  `routes/web.php` içindeki `/teklif/onay/{token}` grubu
  `app/Http/Controllers/Admin/PromotionQuoteController.php` içindeki `sendToCustomer()`, `openCustomerApproval()`, `openWhatsappLink()` ve approval summary/hazir-link hunkları
  `tests/Feature/PublicQuoteApproval*`
  `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`
  `tests/Feature/QuoteNotificationIntegrationTest.php`
- Ortak dosya hunk riskleri:
  `routes/web.php` içinde public approval hunkları dışında revision route ve `catalog/search` gibi ilgisiz hunklar da var.
  `PromotionQuoteController.php` içinde public approval ile karışmış revision compare/apply, index filtreleri, notification summary, WhatsApp ve geniş UI hunkları var.
  `resources/views/admin/promotion-quotes/show.blade.php` çok büyük bir quote detail/UI refactor'u ile birlikte customer approval panelini taşıyor.
  `public/css/prodelya-admin.css` dosyası aşırı geniş ve public approval için seçici staging yapılması gereken çok sayıda ilgisiz UI/CSS değişikliği içeriyor.

## 3. Public Approval Akış Analizi
- Token üretimi:
  `QuoteApprovalService::createApprovalRequest()` içinde `Str::random(64)` ile üretiliyor ve `quote_approval_requests.token` alanına yazılıyor.
- Public route:
  `routes/web.php` içinde `public.quotes.approval.show`, `approve`, `revision`, `reject` route'ları `/teklif/onay/{token}` prefix'i altında tanımlı.
- Guest erişim koruması:
  `PublicQuoteApprovalController::resolvePublicApprovalRequest()` token ile kaydı buluyor, `tenant` ve `sendSnapshot` yoksa 404 dönüyor, ayrıca tenant için `public_quote_approval` feature erişimini doğruluyor.
- Token geçersizse ne oluyor?
  Kayıt bulunamazsa `firstOrFail()` nedeniyle 404.
- İptal edilmiş istek ne oluyor?
  `shouldHideRequest()` nedeniyle 404.
- Süresi dolmuş token ne oluyor?
  `markExpiredIfNeeded()` status'u `expired` yapıyor; ekran açılabiliyor ama aksiyon kapalı ve mesaj dönüyor.
- Müşteri teklifi görüntüleyebiliyor mu?
  Evet. Public ekran snapshot bazlı payload ile render ediliyor.
- Onay / revizyon talebi / ret akışı var mı?
  Evet. `approve()`, `requestRevision()`, `reject()` methodları mevcut.
- Aynı approval request ikinci kez yanıtlanabiliyor mu?
  Hayır. `guardRequestCanRespond()` yalnız `waiting` ve `viewed` durumlarını kabul ediyor; ikinci denemede hata mesajı dönüyor.
- Yeni gönderimde eski açık approval request iptal ediliyor mu?
  Evet. `QuoteApprovalService::sendToCustomer()` içinde önce `cancelOpenRequests($quote, 'replaced_by_new_send')` çağrılıyor.
- Snapshot tabanlı çıktı korunuyor mu?
  Evet. Public ekran `sendSnapshot->snapshot_json` üzerinden besleniyor.
- Canlı order datası yerine send snapshot mı gösteriliyor?
  Evet. `buildViewPayload()` ürün, baskı ve toplamları snapshot'tan kuruyor; canlı model yalnız fallback/meta amaçlı kullanılıyor.
- Onay sonrası siparişe dönüşüm otomatik mi, manuel mi?
  Manuel. Approval statüsü siparişe çevrilebilir hale getiriyor; otomatik convert yok.
- Public approval ile revision request ilişkisi nasıl?
  Müşteri tarafındaki revize isteği `QuoteApprovalRequest::STATUS_REVISION_REQUESTED` ve `Order::CUSTOMER_APPROVAL_REVISION_REQUESTED` olarak işleniyor; bu, revision A-B-C çekirdeğindeki order revision compare/apply akışından ayrı.
- Mail template:
  `QuoteCustomerApprovalMail` ve `emails.quote-customer-approval` view'i yalnız müşteri adı, teklif no, geçerlilik tarihi, toplam ve public approval URL gösteriyor.

## 4. Güvenlik ve Hassas Veri Kontrolü
- Public quote approval blade:
  Snapshot'tan yalnız müşteriye görünür alanlar render ediliyor. `PublicQuoteApprovalController::sanitizePublicText()` not alanlarında `supplier_cost`, `group_code`, `file_path`, `physical_path`, `balance`, `notification_logs`, `pdh_raw` gibi örüntüleri düşürüyor.
- Public approval controller response:
  Ürün satırlarında `customer_unit_price`, `customer_line_total`, `print_total` ve güvenli notlar kullanılıyor. Controller response'ta tenant içi maliyet ve teknik alanlar taşınmıyor.
- Mail blade / mailable payload:
  Mail view'de iç maliyet, raw payload, Product Hub teknik alanları, tenant internal alanları gösterilmiyor.
- Test kanıtı:
  `PublicQuoteApprovalNoSensitiveLeakTest`, `PublicQuoteApprovalCustomerPriceDisplayTest`, `PromotionQuoteCustomerMailHotfixTest`, `QuoteNotificationIntegrationTest` bu yüzeylerde hassas veri sızıntısı olmadığını doğruluyor.
- Sonuç:
  Public sayfa ve mail içeriği tarafında supplier cost, alış fiyatı, maliyet, raw payload, `group_code`, `file_path`, `physical_path`, `api_key`, `smtp_password`, `tenant_id`, current account/payment internals doğrudan görünmüyor.

## 5. Token / Public URL Log Riski
- Public approval URL hangi servislerde üretiliyor?
  `QuoteApprovalService::resolvePublicQuoteUrl()`, `PromotionQuoteController::openCustomerApproval()`, `PromotionQuoteController::openWhatsappLink()`, `sendToCustomer()` ve mail render akışı public quote URL üretiyor.
- `message_preview` içine düşüyor mu?
  Evet, yüksek olasılıkla düşüyor.
  `TenantSmtpMailerService::sendQuoteApprovalMail()` preview modunda `renderQuoteApprovalPreview()` HTML çıktısını `NotificationDispatchService::dispatchEmailPreview()` ile `message_preview` alanına yazıyor.
  Bu sanitization `token` kelimesini maskeliyor ama URL içindeki gerçek rastgele token değerini genel olarak maskelemiyor.
- `meta_json` içine `public_link` veya `url` olarak giriyor mu?
  Evet.
  `NotificationEventService::sanitizeMetaContext()` `public_quote_url` anahtarını gizliyor; bu iyi.
  Ancak `NotificationDispatchService::createWhatsappLink()` log meta'sını `['url' => $link]` şeklinde yazıyor.
  Bu `wa.me` URL'si query içinde encoded public approval linki taşıyor.
- Token ham şekilde kalıyor mu?
  Evet, risk burada.
  `sanitizeStructuredValue()` ve `NotificationLogController::sanitizeStructured()` `token` kelimesini maskeleyebiliyor ama URL içindeki gerçek token değeri için genel URL-redaction yapmıyor.
  Sonuç olarak `meta_json.url` ve muhtemelen preview body içinde tekrar kullanılabilir public approval token'ı kalabilir.
- Bu logları kim görebiliyor?
  Notification log ekranına erişimi olan tenant admin kullanıcıları.
  `NotificationLogController` tenant scope ile gösteriyor; yani platform dışına açılmıyor ama tenant içi yetkili kullanıcılar görebiliyor.
- Risk seviyesi:
  Orta-yüksek.
  Çünkü token guest erişimli, tekrar kullanılabilir ve log içinde saklanırsa public approval linki ikinci bir iç kullanıcı tarafından açılabilir.
- Blocker mı?
  Evet.
  Public approval ekranı ve mail içeriği tek başına güvenli görünse de notification preview/log yüzeyinde ham public link/token saklanma riski commit öncesi temizlenmeli.

## 6. Commit Planı
- Commit mesajı:
  `quotes: add public approval flow and mail template`
- Dahil edilecekler:
  `app/Http/Controllers/PublicQuoteApprovalController.php`
  `app/Services/QuoteApprovalService.php`
  `app/Mail/QuoteCustomerApprovalMail.php`
  `resources/views/public/quotes/approval/show.blade.php`
  `resources/views/emails/quote-customer-approval.blade.php`
  `routes/web.php` içindeki public approval route hunkları
  `app/Http/Controllers/Admin/PromotionQuoteController.php` içindeki yalnız public approval request/send/link hunkları
  `tests/Feature/PublicQuoteApproval*`
  `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`
  public approval fixture concern dosyası
- Dışarıda bırakılacaklar:
  Notification/WhatsApp implementation hunks
  `PhoneNumberNormalizer` hunks
  revision compare/apply hunkları
  quote/order büyük UI hunkları
  Product Hub hunkları
  unrelated CSS hunkları
- Hunk notları:
  `routes/web.php`
  Sadece `/teklif/onay/{token}` public route grubu ile admin quote tarafındaki `send-to-customer` ve `customer-approval.open` hunkları alınmalı. Revision ve `catalog/search` hunkları dışarıda bırakılmalı.
  `PromotionQuoteController.php`
  `openCustomerApproval()`, `sendToCustomer()`, ilgili summary/helper hunkları alınabilir; revision compare/apply, aktif/converted/archived index filtreleri ve diğer UI refactor hunkları dışarıda kalmalı.
  `public/css/prodelya-admin.css`
  Public approval commit için bu dosya mümkünse hiç alınmamalı. Dosya çok karışık ve unrelated admin/order UI değişiklikleri baskın.
  `resources/views/admin/promotion-quotes/show.blade.php`
  Müşteri onayı sekmesi, gönderim modalı, public link açma ve özet kartları seçici hunk staging ile alınabilir; geri kalan quote detail layout refactor'u ayrı checkpoint olmalı.
  Notification servisleri
  Mevcut halde public approval ile kesişen log/token riski taşıyorlar. Bu grup public approval commit'ine karıştırılmamalı; önce mini sanitization fazı ile ayrılmalı.
- Risk seviyesi:
  Worktree hunk seviyesi açısından yüksek. Dosyalar ortak ve büyük.

## 7. Test Sonuçları
- `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`:
  passed, 34 test, 324 assertion
- `php artisan test --filter="PublicQuoteApprovalNoSensitiveLeak|PublicQuoteApprovalCustomerPriceDisplay"`:
  passed, 2 test, 28 assertion
- `php artisan test --filter="QuoteCustomerApprovalMail|QuoteApprovalMail"`:
  no tests found
- Ek doğrulama:
  `php artisan test --filter="PromotionQuoteCustomerMailHotfixTest|QuoteNotificationIntegrationTest"`:
  passed, 10 test, 121 assertion
- `php artisan test --filter="PromotionQuote|OrderRevision|RepeatOrder"`:
  passed, 177 test, 1501 assertion
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`:
  passed, 14 test, 111 assertion

## 8. Smoke Planı / Smoke Sonucu
- Manuel smoke bu fazda çalıştırılmadı.
- Önerilen smoke planı:
  Bir teklif için müşteriye gönderim oluştur.
  Guest olarak public approval linkini aç.
  Fiyatların snapshot ile doğru göründüğünü kontrol et.
  Hassas veri görünmediğini kontrol et.
  Onay, revize isteği ve red aksiyonlarını ayrı ayrı dene.
  Aynı linke ikinci kez yanıt dene.
  Süresi dolmuş/geçersiz token dene.
  Mail template render kontrolü yap.
  Notification log preview ve meta ekranında token/public link görünüp görünmediğini doğrula.

## 9. Kalan Riskler
- Notification preview ve WhatsApp log meta'sında public approval token'ı saklanabiliyor olabilir.
- `PromotionQuoteController.php`, `routes/web.php`, `resources/views/admin/promotion-quotes/show.blade.php`, `public/css/prodelya-admin.css` dosyaları çok karışık; yanlış hunk staging riski yüksek.
- Public approval commit'i notification cleanup, WhatsApp ve büyük UI refactor ile karışmaya çok açık.
- `public/css/prodelya-admin.css` bu checkpoint için en kırılgan ortak dosya.

## 10. Net Karar
- Önce token log sanitization düzeltmesi gerekli.

Gerekçe:
- Public approval public yüzeyi ve mail içeriği temiz görünüyor.
- Ancak notification preview/log tarafında ham public URL veya encoded token log/meta içinde kalabiliyor.
- Bu risk ayrı notification fazına bırakılabilecek kadar küçük değil; reusable guest token saklama riski oluşturuyor.

## 11. Sonraki Adım
- Önce notification token sanitization mini fazı
- Sonrasında kullanıcı onayı ile `PUBLIC-APPROVAL-CHECKPOINT-COMMIT-APPLY`

