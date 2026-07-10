# Notification / WhatsApp / Phone Checkpoint Prep Raporu — 2026-07-09

## 1. Özet
- Yeni kod yazıldı mı? Hayır.
- Staging/commit yapıldı mı? Hayır.
- Product Hub'a dokunuldu mu? Hayır.
- Revision A-B-C'ye dokunuldu mu? Hayır.
- Public approval commitlerine dokunuldu mu? Hayır. Yalnız commitlenmiş davranışlarla kalan worktree kesişimleri analiz edildi.

## 2. Kalan Git Durumu
- Modified: `30`
- Untracked: `56`
- Notification/WhatsApp/phone odaklı modified dosyalar:
  `app/Services/Notifications/TenantWhatsappLinkService.php`,
  `app/Services/Notifications/NotificationEventService.php`,
  `app/Services/PhoneNumberNormalizer.php`,
  `app/Http/Controllers/Admin/PromotionQuoteController.php`,
  `resources/views/admin/promotion-quotes/show.blade.php`,
  `resources/views/admin/promotion-quotes/index.blade.php`,
  `tests/Feature/WhatsappLinkUsesNormalizedPhoneTest.php`,
  `tests/Feature/CompanyPhoneDisplayFormatTest.php`,
  `tests/Feature/TurkishPhoneNumberNormalizeTest.php`,
  `tests/Feature/PromotionQuoteSendActionsUxTest.php`
- Notification/WhatsApp/phone odaklı untracked dosyalar:
  `tests/Feature/CompanyWhatsappPhoneAllowsFixedLineTest.php`,
  `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`,
  `tests/Feature/PromotionQuoteDetailPhoneHelperTextTest.php`,
  `tests/Feature/PromotionQuoteDetailSendChannelUiTest.php`,
  `tests/Feature/PromotionQuoteDetailSendHotfixRegressionTest.php`,
  `tests/Feature/PromotionQuoteDetailWhatsappUiRuleTest.php`
- Ortak dosya hunk riskleri:
  `PromotionQuoteController.php` hem send-channel hem revision/index/Product Hub kırıntıları içeriyor.
  `resources/views/admin/promotion-quotes/show.blade.php` büyük quote/order UI refactor ile send modalı iç içe.
  `resources/views/admin/promotion-quotes/index.blade.php` liste UX checkpoint'i ile karışık.

## 3. Mevcut Notification / WhatsApp / Phone Akışı
- Mail preview:
  `PromotionQuoteController::sendToCustomer()` içinde `sent_channel=email` seçilirse `QuoteApprovalService::sendToCustomer(... force_email_preview=true)` çağrılıyor.
  `QuoteApprovalService` bu durumda `TenantSmtpMailerService::sendQuoteApprovalMail(..., forcePreview: true)` ile gerçek SMTP yerine `NotificationDispatchService::dispatchEmailPreview()` çağrıyor.
- SMTP dispatch:
  `TenantSmtpMailerService::sendQuoteApprovalMail()` gerçek SMTP ayarı hazırsa `Mail::mailer('tenant_smtp_runtime')->to(...)->send(...)` çalıştırıyor.
  Başarıda email log `STATUS_SENT`, hata halinde güvenli özetli `STATUS_FAILED` log üretiliyor.
- WhatsApp link:
  Genel ayarlar ekranında `NotificationSettingsController::createWhatsappLink()` önce preview üretip sonra `TenantWhatsappLinkService::createManualLink()` çağırıyor.
  Quote gönderim akışında ise worktree'de kalan `PromotionQuoteController::sendToCustomer()` `sent_channel=whatsapp_link` için approval request oluşturup gerçek `wa.me` linkini session ile döndürüyor.
- Phone normalization:
  `NotificationDispatchService::createWhatsappLink()` ve `TenantWhatsappLinkService::toWhatsappDialString()` aynı `PhoneNumberNormalizer` servisini kullanıyor.
- Notification log:
  Storage tarafında sanitization `NotificationDispatchService`, display tarafında sanitization `NotificationLogController` üzerinden yapılıyor.

## 4. Telefon Normalizasyon Analizi
- Mevcut worktree değişikliği `PhoneNumberNormalizer` içinde mobil odaklı kontrolü genişletip `normalizeTurkishPhoneForWhatsapp()` ile hem `2`, `3`, `4`, `5` ile başlayan 10 haneli Türkiye numaralarını kabul ediyor.
- Desteklenen örnekler:
  `02125018233 -> 902125018233`
  `2125018233 -> 902125018233`
  `+90 212 501 82 33 -> 902125018233`
  `0090 212 501 82 33 -> 902125018233`
  `05322723484 -> 905322723484`
  `5322723484 -> 905322723484`
- Boşluk, tire, parantez temizliği `digitsOnly()` ile yapılıyor.
- `null` / boş giriş `null` dönüyor.
- Çok kısa / geçersiz girişler `null` dönüyor; `WhatsappLinkUsesNormalizedPhoneTest` içinde `abc` senaryosu doğrulanıyor.
- Tenant/customer/company tarafında kullanım:
  `TenantWhatsappLinkService::toWhatsappDialString()`
  `TenantWhatsappLinkService::formatTurkishPhoneForDisplay()`
  `NotificationDispatchService::createWhatsappLink()`
  quote detail send modalında telefon ön dolumu
  WhatsApp ayar ekranı ve company gösterimi
- Sabit hat WhatsApp Business desteği iş kararı olarak desteklenmiş görünüyor.
  Kanıt:
  `CompanyWhatsappPhoneAllowsFixedLineTest.php`
  `PromotionQuoteSendChannelHotfixTest::test_PromotionQuoteWhatsappFixedLineNumberAcceptedTest`
  `TurkishPhoneNumberNormalizeTest` artık `0212` kabul ediyor.
- Test yeterliliği:
  Normalizasyonun temel formatları kapsanıyor.
  Ancak `+90`, `0090`, `0`, `10 hane`, sabit hat ve UI gösterim kombinasyonları için daha tekil servis testleri yine de artırılabilir.

## 5. WhatsApp Link Analizi
- Link formatı `https://wa.me/{dial}?text={rawurlencode(message)}`.
- Mesaj encode ediliyor.
- Kalan worktree değişikliklerine göre public approval URL mesaj içinde ayrı satırda tam URL olacak şekilde normalize ediliyor:
  `NotificationTemplateDefaultSeederService` WhatsApp template'ini iki satıra taşıyor.
  `NotificationEventService::normalizeQuoteWhatsappBody()` aynı URL'yi gövdenin sonuna tek başına ayrı satır olarak koyuyor.
  `TenantWhatsappLinkService::buildMessageBody()` `TYPE_QUOTE_LINK` için URL'yi ayrı satır üretiyor.
- WhatsApp link üretimi e-posta adresine bağlı değil.
  `PromotionQuoteSendChannelHotfixTest::test_PromotionQuoteWhatsappLinkDoesNotRequireEmailTest`
- WhatsApp için yalnız telefon yeterli.
- Telefon yoksa kullanıcı mesajı:
  `WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.`
- Ayarlar ekranında create-link response gerçek linki kullanıcıya döndürüyor.
- Quote gönderim akışında worktree değişikliklerine göre `session('whatsapp_result')` ile gerçek link dönüyor.
- Log storage tarafında token güvenliği commitlenmiş sanitization ile korunuyor:
  kalıcı `meta_json.url` placeholder oluyor,
  `message_preview` token yerine `[public-onay-linki-gizlendi]` taşıyor.
- `wa.me` query içindeki encoded public approval URL logda kalıcı olarak saklanmıyor; sanitization testi bunu doğruluyor.

## 6. Mail / SMTP / Preview Analizi
- E-posta preview gerçek gönderimden ayrı.
  `TenantSmtpMailerService::sendQuoteApprovalMail()` içinde `forcePreview` veya SMTP hazırlıksızlık varsa preview log üretiliyor.
- SMTP failure kullanıcıya güvenli mesaj dönüyor:
  `Teklif kaydı oluşturuldu ancak e-posta gönderilemedi. SMTP ayarlarını veya müşteri e-posta adresini kontrol edin.`
- Quote approval / send flow rollback olmuyor.
  `QuoteApprovalService::sendToCustomer()` request ve quote durumunu transaction içinde kaydediyor, notification tarafı sonradan `dispatchSafely()` ile çalışıyor.
  Notification failure `catch (\Throwable)` ile akışı kırmıyor.
- Mail template müşteriye uygun ve public approval linkini gerçek çalışır halde koruyor.
  Log tarafında ise redacted kalıyor.
- SMTP credential görünürlüğü:
  `TenantSmtpMailerService::buildMailDiagnostic()` hata detayını kategorize edip `password`, `token`, `secret` sızıntısını yüzeye taşımıyor.
- Notification log içine hassas HTML/token düşmemesi `NotificationDispatchService` sanitization katmanına bağlı ve mevcut testler geçti.
- Türkçe karakter problemi:
  Aktif kalan risk düşük ama sıfır değil.
  `PromotionQuoteController.php` ve `config/admin_menu.php` diffinde encoding temizlikleri ile beraber başka metin değişimleri var; karışık diff nedeniyle bu konu ayrı checkpointte dikkatle ele alınmalı.

## 7. Notification Log Güvenlik Analizi
- Storage tarafı:
  `NotificationDispatchService`
  `message_preview`, `provider_response`, `meta_json` sanitize ediliyor.
- Display tarafı:
  `NotificationLogController`
  `safeMeta` ve `safeProviderResponse` view'a sanitize verilerek gösteriliyor.
- Redacted alanlar:
  `url`
  `public_link`
  `public_quote_url`
  `public_quote_approval_url`
  `approval_url`
- Gizlenen alanlar:
  `smtp_password`, `mail_password`, `api_key`, `token`, `file_path`, `physical_path`, `raw_xml`, `raw_json`, `pdh_raw`, `group_code`, `supplier_cost`, `subcontractor_cost`, `profit`, `cost`
- Kritik sızıntı bulgusu:
  Test koşullarında kritik sızıntı görülmedi.
- Orta risk:
  Worktree'de kalan `PromotionQuoteController` ve detail UI refactor'ı log summary ekranıyla birlikte commitlenirse güvenli scope bozulabilir.
- Tenant scope:
  `NotificationLogController::index()` `forTenant($tenant->id)` kullanıyor.
  `show()` içinde `abort_unless($notificationLog->tenant_account_id === $tenant->id, 404)`.
  Repo içinde notification security/log UI testleri mevcut.
- Açık bulgu:
  Bildirim log tenant izolasyonu için ilgili testler repo genelinde mevcut olsa da bu prep fazında özel bir manuel smoke yapılmadı.

## 8. PromotionQuoteController Hunk Riskleri
- Notification / send channel hunkları:
  `buildSendSuccessMessage`
  `normalizeSendRecipientData`
  `openWhatsappLink`
  `sendToCustomer`
- WhatsApp link hunkları:
  `TYPE_QUOTE_LINK`
  fixed-line kabulü
  email bağımsız `whatsapp_link` dalı
  `whatsapp_result` session dönüşü
- E-posta preview hunkları:
  `sent_channel=email`
  `force_email_preview`
  manual send için email zorunluluğu
- Customer approval/open hunkları:
  `openCustomerApproval` public approval checkpoint ile sınır komşusu
  `sendToCustomer` içindeki approval request üretimi public approval çekirdeğiyle iç içe
- Revision compare/apply hunkları:
  aynı dosyada güçlü biçimde karışık duruyor, bu checkpointten ayrı tutulmalı
- Quote list filtre hunkları:
  `index()` içindeki `filter/view/active/converted/archived` değişiklikleri UI checkpoint'e ait
- Product Hub warning hunkları:
  kategori/encoding metin temizliği bu checkpointe ait değil
- Sonuç:
  `PromotionQuoteController.php` tek committe güvenli alınmamalı.
  Send-channel çekirdeği ayrı patch planıyla alınmalı.
  `index()` ve revision/Product Hub hunks'ları kesin dışarıda kalmalı.

## 9. Commit Planı
- Commit A: `notifications: add phone normalization and whatsapp link support`
  Dosyalar:
  `app/Services/PhoneNumberNormalizer.php`
  `app/Services/Notifications/TenantWhatsappLinkService.php`
  `tests/Feature/TurkishPhoneNumberNormalizeTest.php`
  `tests/Feature/WhatsappLinkUsesNormalizedPhoneTest.php`
  `tests/Feature/CompanyPhoneDisplayFormatTest.php`
  `tests/Feature/CompanyWhatsappPhoneAllowsFixedLineTest.php`
  Hunk notu:
  sadece normalizasyon ve `wa.me` üretimi
  sabit hat desteği
  invalid phone davranışı
  quote-specific controller/UI hunks dışarıda
  Risk:
  düşük-orta
  Test önerisi:
  `php artisan test --filter="WhatsappLinkUsesNormalizedPhone|PhoneNumberNormalizer|CompanyPhoneDisplayFormat|CompanyWhatsappPhoneAllowsFixedLine"`
- Commit B: `notifications: improve quote email preview and dispatch logging`
  Dosyalar:
  `app/Services/Notifications/TenantSmtpMailerService.php`
  `app/Services/Notifications/NotificationEventService.php`
  `app/Services/Notifications/NotificationDispatchService.php`
  `app/Http/Controllers/Admin/NotificationLogController.php`
  `app/Services/QuoteApprovalService.php`
  `app/Services/Notifications/NotificationTemplateDefaultSeederService.php`
  `tests/Feature/QuoteNotificationIntegrationTest.php`
  `tests/Feature/NotificationPublicApprovalTokenSanitizationTest.php`
  `tests/Feature/PromotionQuoteCustomerMailHotfixTest.php`
  Hunk notu:
  quote mail preview / SMTP dispatch / safe diagnostic / WhatsApp body normalization
  commitlenmiş public approval token sanitization hunkları yeniden yazılmamalı
  varsa yalnız tamamlayıcı, çakışmayan ek hunks alınmalı
  Risk:
  orta
  Test önerisi:
  `php artisan test --filter="QuoteNotificationIntegration|NotificationPublicApprovalTokenSanitization|PromotionQuoteCustomerMailHotfix"`
- Commit C: `quotes: wire send channel actions for customer quote delivery`
  Dosyalar:
  `app/Http/Controllers/Admin/PromotionQuoteController.php`
  `resources/views/admin/promotion-quotes/show.blade.php`
  net ayrılabiliyorsa `tests/Feature/PromotionQuoteSendChannelHotfixTest.php`
  `tests/Feature/PromotionQuoteSendActionsUxTest.php`
  `tests/Feature/PromotionQuoteDetailSendChannelUiTest.php`
  `tests/Feature/PromotionQuoteDetailWhatsappUiRuleTest.php`
  `tests/Feature/PromotionQuoteDetailPhoneHelperTextTest.php`
  `tests/Feature/PromotionQuoteDetailSendHotfixRegressionTest.php`
  Hunk notu:
  yalnız send modalı, send channel seçimi, WhatsApp açma/link oluşturma
  `index()` filtreleri, revision compare/apply, Product Hub ve büyük detail layout değişimi dışarıda
  Risk:
  yüksek
  Test önerisi:
  `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone"`
- Commit D: `docs: add notification whatsapp phone checkpoint reports`
  Dosyalar:
  `docs/NOTIFICATION-WHATSAPP-PHONE-CHECKPOINT-PREP-RAPORU-20260709.md`
  gerekiyorsa devam fazı raporları
  Hunk notu:
  yalnız raporlar
  Risk:
  düşük
  Test önerisi:
  gerekmez

## 10. Commit'e Alınmayacak Dosyalar
- `.env`
- `.env.*`
- `database/database.sqlite`
- `.tmp/*`
- screenshot dosyaları
- log dosyaları
- browser cookie/debug dosyaları
- `storage`
- `vendor`
- `node_modules`
- Product Hub checkpoint ile ilgili hunks
- Revision A-B-C hunks
- Public approval page/mail/template commitlenmiş hunks
- `resources/views/admin/promotion-quotes/show.blade.php` içindeki büyük layout refactor blokları
- `resources/views/admin/promotion-quotes/index.blade.php` içindeki genel liste UX checkpoint hunks
- unrelated docs

## 11. Test Sonuçları
- `php artisan test --filter="WhatsappLinkUsesNormalizedPhone|PhoneNumberNormalizer|CompanyPhoneDisplayFormat"`
  geçti, 4 test / 25 assertion
- `php artisan test --filter="QuoteNotificationIntegration|PromotionQuoteSendChannelHotfix|NotificationPublicApprovalTokenSanitization"`
  geçti, 16 test / 112 assertion
- `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`
  geçti, 34 test / 324 assertion
- `php artisan test --filter="PromotionQuote|OrderRevision|RepeatOrder"`
  geçti, 177 test / 1504 assertion
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
  geçti, 14 test / 111 assertion

## 12. Smoke Planı / Smoke Sonucu
- Manuel smoke bu fazda çalıştırılmadı.
- Önerilen smoke planı:
  teklif detayında gönderim modalı açılıyor mu
  e-posta preview çalışıyor mu
  gerçek mail göndermeden preview log oluşuyor mu
  WhatsApp link oluşturuluyor mu
  e-posta boşken telefon ile link üretiliyor mu
  `0212` sabit hat için link üretiliyor mu
  public onay URL'si WhatsApp mesajında ayrı satırda tam URL mi
  notification log ekranında token redacted mi
  başka tenant notification log'u açılamıyor mu
  SMTP password / secret görünmüyor mu

## 13. Kalan Riskler
- ortak controller riski:
  `PromotionQuoteController.php` çok karışık
- log/token riski:
  commitlenmiş sanitization şu an yeterli görünüyor, ama send-channel hunkları yanlış gruplanırsa tekrar sızıntı doğabilir
- full suite timeout:
  bu fazda full suite koşulmadı
- quote/order UI ile karışma riski:
  `show.blade.php` ve `index.blade.php` çok yüksek
- encoding/line-ending riski:
  bazı dosyalarda BOM/encoding temizlikleri ve işlevsel hunks aynı diff içinde

## 14. Net Karar
- Önce hunk planı gözden geçirilmeli

Gerekçe:
- Notification/WhatsApp/phone çekirdeği mantıksal olarak ayrışıyor.
- Ancak `PromotionQuoteController.php` ve detail/index blade dosyaları commit-apply fazında güvenli seçici hunk planı olmadan yüksek risk taşıyor.
- Token sanitization çekirdeği zaten doğru commitlendi; bunu bozmadan ilerlemek için controller/UI ayrıştırması önceden yazılmalı.

## 15. Sonraki Adım
- kullanıcı onayı sonrası `NOTIFICATION-WHATSAPP-PHONE-CHECKPOINT-COMMIT-APPLY`
- ama bundan önce özellikle şu alt grubun hunk planı netleştirilmeli:
  `PromotionQuoteController.php`
  `resources/views/admin/promotion-quotes/show.blade.php`
  `resources/views/admin/promotion-quotes/index.blade.php`
