# Revision / Public Approval Checkpoint Prep Raporu — 2026-07-09

## 1. Özet
- Yeni kod yazılmadı: Hayır. Bu fazda yalnız okuma, test ve raporlama yapıldı.
- Staging/commit yapıldı mı: Hayır.
- Migration çalıştırıldı mı: Hayır.
- Product Hub'a dokunuldu mu: Hayır. Sadece mevcut kalan değişiklikler ve kısa Product Hub regresyon testi incelendi.
- Genel sonuç: Product Hub sonrası worktree'de 38 modified + 123 untracked dosya ile toplam 161 kirli öğe var. Kalan işler büyük ölçüde `revizyon`, `repeat order`, `public approval`, `mail/notification/whatsapp`, `quote/order UI` ve iki migration etrafında kümeleniyor.
- En önemli teknik karar: Mantıksal commit zinciri çıkarılabiliyor; ancak `public/css/prodelya-admin.css`, `resources/views/admin/promotion-quotes/show.blade.php`, `resources/views/admin/orders/show.blade.php`, `app/Http/Controllers/Admin/PromotionQuoteController.php`, `routes/web.php` ve `config/admin_menu.php` içinde karışık hunks bulunduğu için kör `git add .` veya kaba dosya bazlı staging güvenli değil.
- En önemli güvenlik notu: Public quote approval ekranında doğrudan kritik sızıntı görülmedi; ancak public approval URL/token içeren linklerin notification log preview/meta alanlarına düşme riski var. Bu commit prep için blocker değil, ama ayrı güvenlik düzeltme notu olarak işaretlenmeli.

## 2. Kalan Git Durumu
- Modified dosyalar: 38
- Untracked dosyalar: 123
- Toplam kirli öğe: 161
- `git diff --stat`: 38 dosya, `7338 insertions`, `3848 deletions`

Ana gruplar:
- Revizyon sistemi:
  `app/Models/OrderRevision.php`, `app/Models/OrderRevisionChange.php`, `app/Services/OrderRevision*`, `resources/views/admin/promotion-quotes/revision-compare.blade.php`, çok sayıda `OrderRevision*` testi
- Repeat order / kopya sipariş:
  `app/Services/OrderQuoteDraftCloneService.php`, `app/Models/Order.php`, `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`, `RepeatOrder*` ve `RevisionRepeatOrder*` testleri
- Public quote approval:
  `app/Http/Controllers/PublicQuoteApprovalController.php`, `app/Services/QuoteApprovalService.php`, `app/Mail/QuoteCustomerApprovalMail.php`, `resources/views/public/quotes/approval/show.blade.php`, `resources/views/emails/quote-customer-approval.blade.php`, `PublicQuoteApproval*` testleri
- Mail / notification / WhatsApp / phone:
  `app/Services/Notifications/*`, `app/Services/PhoneNumberNormalizer.php`, `QuoteNotificationIntegrationTest`, `WhatsappLinkUsesNormalizedPhoneTest`, `CompanyPhoneDisplayFormatTest`
- Quote/order liste ve detay UI:
  `resources/views/admin/orders/*.blade.php`, `resources/views/admin/promotion-quotes/*.blade.php`, `OrderController.php`, `PromotionQuoteController.php`, ilgili çok sayıda UI/regression testi
- Migration:
  `2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
  `2026_07_08_150000_create_order_revisions_tables.php`
- Ortak dosya hunk riskleri:
  `routes/web.php`, `config/admin_menu.php`, `public/css/prodelya-admin.css`, `PromotionQuoteController.php`

## 3. Dosya Grupları

### Revizyon sistemi
- Kapsam:
  `OrderRevision`, `OrderRevisionChange`, `OrderRevisionComparisonService`, `OrderRevisionRecordService`, `OrderRevisionApplyService`, `revision-compare.blade.php`
- Mevcut davranış:
  Revizyon taslağı kaynak siparişten quote draft olarak üretiliyor, karşılaştırma matrisi kuruluyor, uygulanabilir alanlar kayıt altına alınıyor, apply akışı idempotent guard ile korunuyor.
- Güçlü taraflar:
  `OrderRevisionApplyService` tenant eşleşmesi, revision quote guard, permission guard ve `already applied` koruması içeriyor.
  Finans/tahsilat/cari/procurement/production/delivery kayıtları otomatik mutate edilmiyor.
  Apply yalnız güvenli ticari alanlara daraltılmış: adet, fiyat, baskı notu/fiyatı, teslim tipi.
- Risk:
  Apply akışı DB migration'larına bağımlı.
  `PromotionQuoteController.php` içinde revizyon route/show/edit/send/UI karışık halde.
  `public/css/prodelya-admin.css` içindeki revision compare stilleri tek başına ayrılmalı.
- Commit’e hazır parçalar:
  Revizyon model/service/test dosyalarının büyük kısmı mantıksal olarak aynı pakette.
- Dikkat isteyen parçalar:
  `PromotionQuoteController.php`, `routes/web.php`, `Order.php`, ortak CSS.

### Repeat order / kopya sipariş
- Kapsam:
  `OrderQuoteDraftCloneService`, `Order.php` metadata alanları, copy metadata migration, repeat/revision source ilişkileri
- Mevcut davranış:
  Repeat order ve revision ayrı `copy_type` ile tutuluyor.
  `source_order_id`, `copy_type`, `revision_number`, `copied_by_user_id`, `copied_at` alanları dolduruluyor.
  Yeni kayıt her zaman quote draft olarak oluşuyor; doğrudan siparişe çevrilmiş operasyon başlamıyor.
- Güçlü taraflar:
  `SNAPSHOT_BLACKLIST` ile maliyet, raw/payload/path/current_account benzeri alanlar snapshot'tan temizleniyor.
  Finans, ödeme, operasyon geçmişi birebir kopyalanmıyor; yeni draft item/print satırları oluşturuluyor.
- Risk:
  Repeat ve revision aynı clone servisinde olduğu için commit ayırımı dosya bazında değil hunk/konsept bazında düşünülmeli.
- Commit önerisi:
  Metadata/migration ile clone service'i iki ayrı commit yapmak daha temiz.

### Public quote approval
- Kapsam:
  `PublicQuoteApprovalController`, `QuoteApprovalService`, `QuoteCustomerApprovalMail`, public approval blade, mail blade, public approval route hunks
- Mevcut davranış:
  Guest token ile görüntüleme, approve, revision request ve reject akışı var.
  Her gönderimde yeni approval request üretiliyor, eski açık request'ler cancel ediliyor.
  Görüntüleme snapshot tabanlı; canlı order içeriği yerine send snapshot okunuyor.
- Güçlü taraflar:
  Forbidden pattern filtreleri ile purchase/supplier/raw/path/group_code vb. yüzeyler bastırılıyor.
  Tenant feature access guard var.
  Aynı request ikinci kez yanıtlanamıyor.
- Risk:
  Public token içeren URL'lerin internal notification log preview/meta alanlarında kalabilmesi.
  `PromotionQuoteController.php` send channel hotfix + public approval + revision hunks aynı dosyada.

### Mail / notification / WhatsApp / phone
- Kapsam:
  `TenantSmtpMailerService`, `NotificationDispatchService`, `NotificationEventService`, `TenantWhatsappLinkService`, `PhoneNumberNormalizer`
- Mevcut davranış:
  E-posta preview ve gerçek SMTP gönderimi ayrılmış.
  WhatsApp link oluşturma e-postaya zorunlu bağlı değil.
  `PhoneNumberNormalizer` hem `+90`, hem `0`, hem `0090` girişlerini normalize ediyor.
  `02125018233` benzeri sabit hatları da `+902125018233` formatına taşıyabiliyor.
- Güçlü taraflar:
  Notification failure quote approval ana akışını rollback etmiyor.
  Message sanitization ile maliyet/token/path anahtar kelimeleri baskılanıyor.
- Risk:
  Log preview/meta sanitization public approval URL tokenını tam gizlemiyor.
  Sabit hattın WhatsApp linkine çevrilmesi iş kararı olarak kabul edilmiş; teknik olarak link üretir ama gerçek WhatsApp hesabı garantisi yok.

### Quote/order liste ve detay UI
- Kapsam:
  `orders/index`, `orders/show`, `promotion-quotes/index`, `promotion-quotes/show`, controller destekleri
- Mevcut davranış:
  Teklif listesi `active / converted / archived / all` görünümüne ayrılmış.
  Sipariş listesi `open / completed / all / in_operation / delivery_pending / payment_pending` görünümüne ayrılmış.
  Sipariş detay sağ panelinde `revizyon oluştur` ve `tekrar sipariş oluştur` aksiyonları eklenmiş.
  Teklif detayında send modal, WhatsApp link akışı, source order banner, revision compare linki ve daha kompakt ürün/baskı düzeni var.
- Risk:
  Bu katman en fazla ortak dosya/cross-cutting hunk taşıyan grup.
  `public/css/prodelya-admin.css` 7k+ diff ile bağımsız commit için çok riskli.

### Migration
- `2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
  Repeat/revision metadata alanları için gerekli.
- `2026_07_08_150000_create_order_revisions_tables.php`
  Revizyon compare/apply altyapısı için gerekli.

### Ortak dosya hunk riskleri
- `routes/web.php`
  Revizyon compare/apply route'ları ve revision/repeat draft route'ları bu faza ait.
  `/catalog/search` hunk'u Product Hub tarafına ait; bu commit prep kapsamından ayrı tutulmalı.
- `config/admin_menu.php`
  `Finans` label/perms ve Türkçe karakter fixleri revision/public approval commit zinciriyle doğrudan bağlı değil.
  `Ürün Veri Merkezi` Türkçe karakter düzeltmesi Product Hub checkpoint sonrası kalmış görünüyor; ayrı tutulmalı.
- `public/css/prodelya-admin.css`
  Design token, order detail, quote detail compact, modal, revision compare ve başka eski/yan görünümler tek dosyada karışmış.
  Bu dosyada hunk staging şart.
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
  Aynı dosyada:
  UI liste filtresi
  revision compare/apply
  source order context
  send channel email/whatsapp hotfix
  Product Hub warning metni/Türkçe cleanup
  Bu nedenle en yüksek staging riski burada.

## 4. Migration Analizi

### copy metadata migration
- Dosya: `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
- Özellik:
  Repeat order ve revision draft için `source_order_id`, `copy_type`, `revision_number`, `copied_by_user_id`, `copied_at`
- Schema tipi:
  Additive. Mevcut `orders` tablosuna nullable alanlar ekliyor.
- Güvenlik:
  `hasColumn` guard içeriyor.
- Commit grubu:
  Commit A ile aynı grupta olmalı.

### order revisions migration
- Dosya: `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
- Özellik:
  Revizyon kayıt başlığı ve satır bazlı değişim/audit altyapısı
- Schema tipi:
  Additive. Yeni iki tablo oluşturuyor.
- Güvenlik:
  Tenant scoped foreign key ve index yapısı var.
- Commit grubu:
  Commit C ile aynı grupta olmalı.

### migrate status
- `php artisan migrate:status` sonucunda iki migration da `Ran` görünüyor:
  `2026_07_08_120000_add_order_copy_metadata_to_orders_table`
  `2026_07_08_150000_create_order_revisions_tables`
- Pending migration görülmedi.

### risk
- Rollback analizi bu fazda execute edilmedi.
- İki migration da additive olduğu için forward-safe görünüyor.
- Ancak revizyon apply ve ilgili testlerin bir kısmı ikinci migration olmadan anlamlı çalışmaz.
- Commit sırası önerisi:
  Metadata migration + model
  Clone service
  Revision tables + models + services
- `migration` ile ona bağımlı model/service aynı checkpoint zincirinde olmalı; migration'ı çok sonra bırakmak apply ekranını yarım bırakır.

## 5. Güvenlik ve Hassas Veri Kontrolü

Sonuç özeti:
- Kritik sızıntı: Public approval blade üzerinde doğrudan tespit edilmedi.
- Orta risk: Notification log preview/meta alanlarında public approval URL tokenı kalabiliyor.
- Düşük risk: Ortak UI dosyalarında çok geniş diff olduğu için istemeden teknik alan basma regresyonu ihtimali artıyor; ama mevcut testler bu yüzeyi kısmen koruyor.

Kontroller:
- Public quote approval ekranı:
  Snapshot tabanlı.
  `customer_unit_price`, `customer_line_total`, müşteriye gösterilecek baskı fiyatı kullanılıyor.
  `purchase/supplier/raw/group_code/file_path/physical_path` filtreleri controller seviyesinde engellenmiş.
- Public graphic approval ekranı:
  Bu fazda temel route/UI regresyon testi görüldü, fakat detay kod analizi sınırlı kaldı.
- Quote mail template:
  Mail blade sade; quote no, geçerlilik, toplam ve public URL içeriyor.
  Maliyet/alış/raw yok.
- WhatsApp mesajı:
  Link ayrı satırda tam URL olarak üretiliyor.
  Sanitizer maliyet/path/key kelimelerini temizliyor.
  Ancak loglanan `message_preview` ve `meta_json.public_link/url` içinde tokenlı public link kalabiliyor.
- Quote detail / order detail:
  `canViewFinancialData` guard'ları mevcut.
  Çok sayıda `NoSensitiveLeak` testi var.
- Revision compare:
  Compare ekranı kaynak/revizyon ticari değerleri gösteriyor.
  Procurement/production/finance geçmişi metinsel süreç kapısı olarak gösteriliyor; raw payload basılmıyor.

Koruyan testler:
- `OrderRevisionCompareNoSensitiveLeakTest`
- `OrderRevisionRecordServiceNoSensitiveLeakTest`
- `PublicQuoteApprovalNoSensitiveLeakTest`
- `PromotionQuoteDetailNoSensitiveLeakTest`
- `QuoteOrderListNoSensitiveLeakTest`
- `RevisionRepeatOrderNoSensitiveLeakTest`
- `PublicQuoteApprovalCustomerPriceDisplayTest`
- `WhatsappLinkUsesNormalizedPhoneTest`
- `CompanyPhoneDisplayFormatTest`

Test eksiği / kalan not:
- Notification log içine tokenlı public URL düşmemesi için özel bir regression testi görünmüyor.
- Email preview loglarında HTML içindeki public URL'nin sanitize edildiğini doğrulayan ayrı bir test görünmüyor.

## 6. Commit Planı

### Commit A
- Mesaj:
  `orders: add revision and repeat order metadata`
- Dosyalar:
  `app/Models/Order.php`
  `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
  yalnız metadata ilişkileri/testleri
- Hunk notu:
  `Order.php` içinden `sourceOrder`, `copiedQuoteDrafts`, `copiedByUser`, copy type sabitleri ve helper metotları alınmalı.
  Quote list scopes aynı commit'e alınmamalı.
- Risk:
  Düşük-orta
- Test önerisi:
  `php artisan test --filter="RepeatOrder|RevisionAndRepeatOrderSourceReference|OrderRevisionMigrationSafety"`

### Commit B
- Mesaj:
  `orders: add repeat order draft cloning`
- Dosyalar:
  `app/Services/OrderQuoteDraftCloneService.php`
  `app/Http/Controllers/Admin/OrderController.php` içinden repeat/revision draft create hunks
  `routes/web.php` içinden `/revision-draft` ve `/repeat-order-draft`
  repeat/revision draft testleri
- Hunk notu:
  `OrderController.php` index/show UI değişimleri alınmamalı; yalnız draft create aksiyonları seçilmeli.
- Risk:
  Orta
- Test önerisi:
  `php artisan test --filter="RepeatOrder|RevisionRepeatOrder|OrderRevisionDraft"`

### Commit C
- Mesaj:
  `quotes: add revision compare and apply flow`
- Dosyalar:
  `app/Models/OrderRevision.php`
  `app/Models/OrderRevisionChange.php`
  `app/Services/OrderRevisionComparisonService.php`
  `app/Services/OrderRevisionRecordService.php`
  `app/Services/OrderRevisionApplyService.php`
  `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
  `resources/views/admin/promotion-quotes/revision-compare.blade.php`
  `PromotionQuoteController.php` içinden revision compare/apply/source order revision URL hunks
  revision compare/apply testleri
- Hunk notu:
  `PromotionQuoteController.php` içinde send channel veya Product Hub hunks alınmamalı.
  `public/css/prodelya-admin.css` içinden yalnız `.order-revision-compare` bloğu seçilmeli.
- Risk:
  Orta-yüksek
- Test önerisi:
  `php artisan test --filter="OrderRevision|Revision"`

### Commit D
- Mesaj:
  `quotes: add public approval flow and mail template`
- Dosyalar:
  `app/Http/Controllers/PublicQuoteApprovalController.php`
  `app/Services/QuoteApprovalService.php`
  `app/Mail/QuoteCustomerApprovalMail.php`
  `resources/views/public/quotes/approval/show.blade.php`
  `resources/views/emails/quote-customer-approval.blade.php`
  `routes/web.php` içinden public approval route hunks
  public approval testleri
- Hunk notu:
  `QuoteApprovalService.php` içindeki send/cancel/respond zinciri bu commit'te bir arada kalmalı.
- Risk:
  Orta
- Test önerisi:
  `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`

### Commit E
- Mesaj:
  `notifications: add phone normalization and whatsapp link support`
- Dosyalar:
  `app/Services/PhoneNumberNormalizer.php`
  `app/Services/Notifications/TenantWhatsappLinkService.php`
  `app/Services/Notifications/TenantSmtpMailerService.php`
  `app/Services/Notifications/NotificationDispatchService.php`
  `app/Services/Notifications/NotificationEventService.php`
  ilgili notification/whatsapp/phone testleri
- Hunk notu:
  Public link log leak notu raporda kalsın; bu fazda düzeltme yok.
- Risk:
  Orta
- Test önerisi:
  `php artisan test --filter="QuoteNotification|Whatsapp|Phone"`

### Commit F
- Mesaj:
  `orders: refine quote and order list/detail ux`
- Dosyalar:
  `resources/views/admin/orders/index.blade.php`
  `resources/views/admin/orders/show.blade.php`
  `resources/views/admin/promotion-quotes/index.blade.php`
  `resources/views/admin/promotion-quotes/show.blade.php`
  `OrderController.php` içinden index/show UI hunks
  `PromotionQuoteController.php` içinden index/show/send modal/UI hunks
  ilgili UI testleri
- Hunk notu:
  En yüksek staging ihtiyacı burada.
  `public/css/prodelya-admin.css` bu commit için ayrı ve dikkatli parçalanmalı.
- Risk:
  Yüksek
- Test önerisi:
  `php artisan test --filter="PromotionQuote|Order"`

### Commit G
- Mesaj:
  `docs: add revision and public approval checkpoint reports`
- Dosyalar:
  Bu rapor ve varsa yalnız ilgili revision/public approval dokümanları
- Hunk notu:
  `FULL-SYSTEM-SCAN`, `SAFE-ROLLBACK-AUDIT`, `MASTER-PLAN` gibi başka audit belgeleri bu commit'e otomatik dahil edilmemeli.
- Risk:
  Düşük
- Test önerisi:
  Test gerekmez

## 7. Commit’e Alınmayacak Dosyalar
- `.env`, `.env.*`
- `database/database.sqlite`
- `.tmp/*`
- log/debug/cookie dump dosyaları
- `storage/*`
- `vendor/*`
- `node_modules/*`
- `docs/FULL-SYSTEM-SCAN-20260709.md`
- `docs/PRODUCT-HUB-AND-TEMPLATE-INTEGRATION-MASTER-PLAN-20260709.md`
- `docs/SAFE-ROLLBACK-AUDIT-20260709.md`
- `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md`
- Product Hub checkpoint'e ait ama burada kalan hunks:
  `routes/web.php` içindeki `/catalog/search`
  `config/admin_menu.php` içindeki `Ürün Veri Merkezi` Türkçe karakter/Product Hub menü hunks
  `PromotionQuoteController.php` içindeki Product Hub warning metni/Türkçe cleanup hunk'ları
- Ortak CSS içindeki ilgisiz eski/yan faz stilleri

## 8. Test Sonuçları

Çalıştırılan komutlar:
- `php artisan test --filter="OrderRevision|RepeatOrder|Revision"`
  Sonuç: 60 test geçti, 480 assertion
- `php artisan test --filter="PublicQuoteApproval|QuoteApproval|QuoteNotification"`
  Sonuç: 37 test geçti, 362 assertion
- `php artisan test --filter="PromotionQuote|Order|Whatsapp|Phone"`
  Sonuç: 346 test geçti, 2974 assertion
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest|ProductHub|TenantCatalog"`
  Sonuç: 95 test geçti, 655 assertion
- `php artisan test`
  Sonuç: timeout. Bu fazda düzeltilmedi; rapora not düşüldü.

Toplam odaklı geçen test:
- 538 test geçti
- 4471 assertion geçti
- Odaklı gruplarda fail görülmedi

## 9. Smoke Planı / Smoke Sonucu

Manuel smoke sonucu:
- Bu fazda browser/manual smoke execute edilmedi.

Önerilen smoke listesi:
- `/admin/orders`
- `/admin/orders/{id}`
- `/admin/promotion-quotes`
- `/admin/promotion-quotes/{id}`
- `/admin/promotion-quotes/{id}/revision-compare`
- public quote approval link
- public graphic approval link
- mail preview / quote approval mail template
- WhatsApp link oluşturma
- repeat order draft oluşturma
- revision draft oluşturma
- completed order list
- active quote list
- converted quote list

Kontrol başlıkları:
- sayfa 200 açılıyor mu
- tenant scope korunuyor mu
- guest public route yalnız token ile açılıyor mu
- hassas veri sızıntısı var mı
- snapshot fiyat/KDV görünümü korunuyor mu
- revision apply ikinci kez engelleniyor mu
- repeat order yeni quote draft mı oluşturuyor
- WhatsApp linkte URL tam ve ayrı satır mı

## 10. Kalan Riskler
- Ortak dosya riski:
  `PromotionQuoteController.php`, `OrderController.php`, `routes/web.php`, `public/css/prodelya-admin.css` dosyalarında hunk staging şart.
- Full suite timeout:
  Genel suite 124 saniyede timeout oldu; tam repo genel sağlık garantisi bu fazda tamamlanmadı.
- Public route güvenliği:
  Public approval ekranı iyi korunuyor; fakat tokenlı public URL'nin internal log preview/meta alanlarına düşmesi orta risk.
- Migration commit sırası:
  Migration'ları aşırı geciktirmek revizyon UI/apply commitlerini yarım bırakır.
- Product Hub ile karışma riski:
  `routes/web.php`, `config/admin_menu.php`, `PromotionQuoteController.php` içinde Product Hub sonrası kalan hunks var.
- Finans/cari sızıntı riski:
  Public ekran tarafında düşük; notification log tarafında orta.
- Tenant isolation riski:
  İncelenen revizyon/public approval servislerinde guard mevcut; testler de tenant isolation koruyor. Mevcut risk düşük-orta.

## 11. Net Karar
- Önce hunk planı gözden geçirilmeli.

Gerekçe:
- Mantıksal commit planı çıkarılabiliyor.
- Odaklı testler temiz.
- Ancak ortak controller/CSS/routes/menu dosyaları dosya bazlı staging için fazla karışık.
- Ayrıca public approval linkinin notification log'a düşme notu görünür şekilde kayıt altına alınmalı.

## 12. Sonraki Adım
- Belirli bir alt grubun önce ayrılması.

Net öneri:
- Önce `Commit A + Commit B + Commit C` için hunk staging planı uygulanmalı.
- Sonra `Commit D + Commit E`.
- En son yüksek riskli UI paketi `Commit F`.
- Kullanıcı onayı sonrası revision/public approval checkpoint commit apply aşamasına geçilebilir.
