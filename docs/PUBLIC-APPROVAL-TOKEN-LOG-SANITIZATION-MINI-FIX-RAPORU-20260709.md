# Public Approval Token Log Sanitization Mini Fix Raporu — 2026-07-09

## 1. Özet
- Ne düzeltildi?
  Notification log storage ve notification log detay gösteriminde public approval token içeren düz/encoded linkler redacted hale getirildi.
- Hangi dosyalar değişti?
  `app/Services/Notifications/NotificationDispatchService.php`
  `app/Http/Controllers/Admin/NotificationLogController.php`
  `tests/Feature/NotificationPublicApprovalTokenSanitizationTest.php`
  `tests/Feature/QuoteNotificationIntegrationTest.php`
  `tests/Feature/PromotionQuoteSendChannelHotfixTest.php`
- Public approval iş akışı değişti mi?
  Hayır.
- Migration var mı?
  Hayır.
- DB write var mı?
  Yalnız testlerin doğal log kayıtları dışında şema/migration/manuel DB işlemi yok.

## 2. Eski Risk
- `message_preview` riski:
  E-posta preview HTML'i veya WhatsApp mesaj önizlemesi içinde `/teklif/onay/{token}` linki kalabiliyordu.
- `meta_json.url` riski:
  WhatsApp log meta'sında `wa.me` URL'si query içinde encoded public approval linki taşıyabiliyordu.
- Encoded WhatsApp URL riski:
  Doğrudan `token` kelimesi maskelenmese bile `rawurlencode()` ile taşınan gerçek token log içinde saklanabiliyordu.
- Token görünürlüğü:
  Notification log ekranına erişen tenant admin, reusable guest public approval linkini log/meta üzerinden görebilirdi.

## 3. Yeni Sanitization Davranışı
- `message_preview`:
  Log storage aşamasında düz public approval linkleri ve text içinde taşınan approval path'leri `[public-onay-linki-gizlendi]` olarak redacted ediliyor.
- `meta_json`:
  `url`, `public_link`, `public_quote_url`, `public_quote_approval_url`, `approval_url` gibi alanlarda public approval link bulunursa kalıcı log kaydı placeholder ile tutuluyor.
- WhatsApp log:
  Kullanıcıya dönen gerçek `wa.me` link bozulmuyor; ancak logda saklanan `meta_json.url` redacted oluyor.
- Notification log ekranı:
  Controller tarafındaki structured meta sanitizer da aynı public approval link örüntülerini redacted gösteriyor; böylece eski veya farklı kaynaklı meta payload'larda da ek koruma var.

## 4. Korunan Davranışlar
- Müşteri linki çalışıyor mu?
  Evet. Mail/WhatsApp üretim tarafında gerçek public approval URL hâlâ oluşturuluyor.
- WhatsApp gerçek link üretimi korunuyor mu?
  Evet. Session/result tarafında kullanıcıya açılabilir gerçek `wa.me` URL dönmeye devam ediyor.
- Public approval approve/revision/reject akışı değişmedi mi?
  Değişmedi.

## 5. Güvenlik Testleri
- Raw token yok:
  Yeni ve güncellenen testlerde token string'inin log preview/meta içinde bulunmadığı doğrulandı.
- `/teklif/onay` linki logda yok:
  E-posta ve WhatsApp log preview alanlarında raw public approval path kalmadığı doğrulandı.
- Encoded token yok:
  `meta_json.url` içindeki encoded `wa.me` query taşıması redacted hale geldi.
- Mevcut hassas alan sanitizer bozulmadı:
  `group_code`, `file_path`, `physical_path`, `pdh_raw`, `supplier_cost` gibi önceki sanitizer kapsamı regresyon testleriyle korunmuş görünüyor.

## 6. Test Sonuçları
- `php artisan test --filter="NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone"`
  passed, 7 test, 67 assertion
- `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`
  passed, 34 test, 324 assertion
- `php artisan test --filter="PromotionQuote|OrderRevision|RepeatOrder"`
  passed, 177 test, 1504 assertion
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
  passed, 14 test, 111 assertion
- Ek hizalama:
  `php artisan test --filter="NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone|PromotionQuoteSendChannelHotfixTest"`
  passed, 18 test, 123 assertion

## 7. Kalan Riskler
- Bu faz log storage ve log meta yüzeyini sertleştirdi; ancak repo worktree genel olarak hâlâ karışık ve public approval checkpoint staging sırasında ortak dosya riskleri devam ediyor.
- Notification sistemi içinde public link taşıyan başka feature'lar ileride eklenirse aynı sanitizer örüntüsünün korunması gerekecek.
- Historical olarak daha önce yazılmış log kayıtları veritabanında mevcutsa, storage geriye dönük temizlenmedi; yalnız controller-side meta gösteriminde ek redaction var.

## 8. Sonraki Öneri
- `PUBLIC-APPROVAL-CHECKPOINT-COMMIT-APPLY`

