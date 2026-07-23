# Revision Checkpoint A-B-C Hunk Staging Prep Raporu — 2026-07-09

## 1. Özet
- Yeni kod yazıldı mı: Hayır.
- Staging/commit yapıldı mı: Hayır.
- Migration çalıştırıldı mı: Hayır.
- Product Hub'a dokunuldu mu: Hayır. Sadece kısa regresyon testi çalıştırıldı.
- Sonuç: Commit A, B ve C için hunk sınırları yeterince net. Commit A dosya bazında daha temiz; Commit B ve özellikle Commit C ortak controller/route/CSS dosyaları nedeniyle seçici staging gerektiriyor.

## 2. Commit A Planı

### dosyalar
- `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
- `app/Models/Order.php`
- metadata ile ilişkili testler:
  `RevisionAndRepeatOrderSourceReferenceTest`
  `OrderRevisionMigrationSafetyTest`
  metadata ilişkilerini dolaylı doğrulayan `OrderRevisionDraft*` ve `RepeatOrder*` testleri

### hunklar
- `Order.php` içine dahil edilecek hunks:
  - `COPY_TYPE_REVISION` ve `COPY_TYPE_REPEAT_ORDER` sabitleri
  - `$fillable` içine:
    `source_order_id`, `copy_type`, `revision_number`, `copied_by_user_id`, `copied_at`
  - `$casts` içine:
    `copy_type`, `revision_number`, `copied_at`
  - relation'lar:
    `sourceOrder()`
    `copiedQuoteDrafts()`
    `copiedByUser()`
  - helper'lar:
    `isRevisionDraft()`
    `isRepeatOrderDraft()`
    `copyTypeLabel()`
    `copyTypeWarning()`

### dışarıda bırakılacaklar
- `Order.php` içindeki Commit C hunkları:
  - `revisions()`
  - `latestRevision()`
  - `orderRevision()`
  - `revisionRecord()`
- `Order.php` içindeki Commit F hunkları:
  - `scopeConvertedQuotes()`
  - `scopeArchivedQuotes()`
  - `scopeActiveQuotes()`
- Public approval, Product Hub veya notification ile ilgili hiçbir şey bu commit'e girmemeli.

### testler
- Commit A tek başına testlenebilir:
  - `php artisan test --filter="RevisionAndRepeatOrderSourceReference|OrderRevisionMigrationSafety|OrderRevisionDraft|RepeatOrder"`
- Not:
  Commit A migration ve model düzeyi metadata hazırlığıdır; clone/apply akışları olmadan da anlamlıdır.

### risk
- Risk seviyesi: Düşük
- Gerekçe:
  - migration additive ve `Ran`
  - `Order.php` içinde metadata hunkları açık ayrışıyor
  - tek gerçek dikkat noktası: aynı dosyada Commit C ve Commit F hunks da bulunuyor

### Commit A için kesin değerlendirme
- `Order.php` içinde Commit A'ya ait hunks açık ayrılabiliyor.
- `Order.php` içindeki Commit B/C/F hunks:
  - Commit B: doğrudan yok; yalnız Commit B servis/controller akışı bu metadata'yı kullanıyor
  - Commit C: revision relation blokları
  - Commit F: quote scope blokları
- `2026_07_08_120000_add_order_copy_metadata_to_orders_table` migration'ı DB'de `Ran`.

## 3. Commit B Planı

### dosyalar
- `app/Services/OrderQuoteDraftCloneService.php`
- `app/Http/Controllers/Admin/OrderController.php`
- `routes/web.php`
- testler:
  - `RepeatOrder*`
  - `RevisionRepeatOrder*`
  - `OrderRevisionDraft*`

### hunklar
- `OrderQuoteDraftCloneService.php`
  - tamamı Commit B'ye ait
  - repeat order ve revision draft clone mantığını birlikte taşıyor
- `OrderController.php` içinden dahil edilecek hunks:
  - `use App\Services\OrderQuoteDraftCloneService;`
  - constructor'a `OrderQuoteDraftCloneService $orderQuoteDraftCloneService`
  - `createRevisionDraft()`
  - `createRepeatOrderDraft()`
  - `createCopiedQuoteDraft()` private helper
- `routes/web.php` içinden dahil edilecek hunks:
  - `POST /{order}/revision-draft`
  - `POST /{order}/repeat-order-draft`

### dışarıda bırakılacaklar
- `OrderController.php` içindeki Commit F hunks:
  - `index()` filtre/view/list counters değişiklikleri
  - `show()` içinde `canCreateQuoteDraft` ve detay UI destekleri
- `routes/web.php` içindeki Commit C route'ları:
  - `/revision-compare`
  - `/revision-apply`
- `routes/web.php` içindeki Product Hub route hunkı:
  - `/catalog/search`

### testler
- Bu fazda çalıştırılan hedef test:
  - `php artisan test --filter="RepeatOrder|RevisionRepeatOrder|OrderRevisionDraft"`
  - Sonuç: 15 test geçti, 111 assertion

### risk
- Risk seviyesi: Düşük-orta
- Gerekçe:
  - servis dosyası temiz ve tek amaçlı
  - controller ve route dosyaları karışık olduğu için seçici staging şart

### güvenlik/iş kuralı analizi
- Clone service finans/cari/operasyon geçmişini kopyalıyor mu:
  - Hayır.
  - Yalnız yeni quote draft `orders`, `order_items`, `order_item_prints` katmanında ticari/sunum snapshot'larıyla kuruluyor.
  - `payments`, `current account transactions`, `procurements`, `productions`, `deliveries` kopyalanmıyor.
- Blacklist güvenli mi:
  - Evet, güçlü görünüyor.
  - Temizlenen örnek alanlar:
    `supplier_cost`, `purchase_price`, `profit`, `margin`, `group_code`, `file_path`, `raw`, `projection`, `payload`, `tenant_id`, `current_account_id`, `transaction_id`, `meta_json`
- Tenant dışı clone engelleniyor mu:
  - Controller seviyesinde evet.
  - `createCopiedQuoteDraft()` içinde:
    - current tenant doğrulanıyor
    - `order->tenant_account_id === tenant->id` kontrolü var
    - `create_quotes` yetkisi aranıyor
  - Servis tenant'ı source order'dan alıyor; doğrudan public yüzey yok.

### OrderController.php içinde Commit B'ye ait hunklar
- `use OrderQuoteDraftCloneService`
- constructor injection
- `createRevisionDraft`
- `createRepeatOrderDraft`
- `createCopiedQuoteDraft`

### routes/web.php içinde Commit B'ye ait route hunkları
- `admin.orders.revision-draft.store`
- `admin.orders.repeat-order-draft.store`

## 4. Commit C Planı

### dosyalar
- `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
- `app/Models/OrderRevision.php`
- `app/Models/OrderRevisionChange.php`
- `app/Services/OrderRevisionComparisonService.php`
- `app/Services/OrderRevisionRecordService.php`
- `app/Services/OrderRevisionApplyService.php`
- `resources/views/admin/promotion-quotes/revision-compare.blade.php`
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `routes/web.php`
- `public/css/prodelya-admin.css`
- `OrderRevision*` testleri

### hunklar
- Tamamı Commit C'ye ait dosyalar:
  - `OrderRevision.php`
  - `OrderRevisionChange.php`
  - `OrderRevisionComparisonService.php`
  - `OrderRevisionRecordService.php`
  - `OrderRevisionApplyService.php`
  - `resources/views/admin/promotion-quotes/revision-compare.blade.php`
  - `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
- `PromotionQuoteController.php` içinden dahil edilecek hunks:
  - revision service `use` blokları
  - `Schema` ve `DomainException` importları
  - constructor içine `OrderRevisionComparisonService`
  - `buildSourceOrderContext()`
  - `canAccessRevisionCompare()`
  - `show()` içine:
    `sourceOrder` load
    `sourceOrderContext`
    `revisionCompareUrl`
  - `revisionCompare()`
  - `applyRevision()`
  - `buildRevisionApplySummary()`
  - `revisionApplyInfrastructureReady()`
  - `edit()` içine:
    `sourceOrder` load
    `sourceOrderContext`
    `revisionCompareUrl`
- `routes/web.php` içinden dahil edilecek hunks:
  - `GET /{quote}/revision-compare`
  - `POST /{quote}/revision-apply`
- `public/css/prodelya-admin.css` içinden dahil edilecek hunks:
  - yalnız `.order-revision-compare` namespace'i ile başlayan blok
  - ilgili responsive varyantları
  - `orc-*` alt sınıfları

### dışarıda bırakılacaklar
- `PromotionQuoteController.php` içindeki Commit D/E/F ve Product Hub hunks:
  - `buildSendSuccessMessage(...sentChannel)`
  - `normalizeSendRecipientData()` contact fallback hotfixleri
  - `index()` active/converted/archived filtreleri
  - `openWhatsappLink()` WhatsApp quote link hunkı
  - `sendToCustomer()` sent channel / email preview / whatsapp link akışı
  - `buildWarningPayload()` içindeki Product Hub Türkçe metin hunkı
- `routes/web.php` içindeki:
  - `/catalog/search`
  - `/revision-draft`
  - `/repeat-order-draft`
- `public/css/prodelya-admin.css` içindeki:
  - quote detail compact
  - order detail/layout
  - genel design token veya unrelated modal/layout blokları

### testler
- Bu fazda çalıştırılan hedef testler:
  - `php artisan test --filter="OrderRevision|Revision"`
    Sonuç: 56 test geçti, 459 assertion
  - `php artisan test --filter="OrderRevisionMigrationSafety|OrderRevisionCompare|OrderRevisionApply"`
    Sonuç: 16 test geçti, 96 assertion

### risk
- Risk seviyesi: Orta
- Gerekçe:
  - revision service ve blade dosyaları temiz
  - asıl risk `PromotionQuoteController.php` ve `public/css/prodelya-admin.css` içinde karışık hunks

### güvenlik/iş kuralı analizi
- Revizyon apply finans/cari/procurement/production/delivery kayıtlarını mutate ediyor mu:
  - Doğrudan finans/cari kayıtları mutate etmiyor.
  - `applyOrderLevelChanges()` yalnız `delivery_type` ve `delivery_type_id` güncelliyor.
  - Item seviyesinde yalnız güvenli ticari alanlar:
    adet, fiyat, baskı notu, baskı fiyatı
  - Procurement/production/delivery/payment/current account kayıtları apply sırasında kopyalanmıyor veya yeniden yazılmıyor.
- Idempotency koruması var mı:
  - Evet.
  - `guardRevision()` içinde:
    `applied_at` veya `status in [applied, partially_applied]` ise `Bu revizyon daha önce uygulanmış.`
- Tenant isolation nasıl korunuyor:
  - `PromotionQuoteController@revisionCompare` ve `@applyRevision` içinde tenant check var
  - `OrderRevisionApplyService::guardRevision()` içinde revision/order/revisionQuote tenant eşleşmesi kontrol ediliyor
  - permission guard da tenant bazlı

### PromotionQuoteController.php içinde Commit C'ye ait hunklar
- revision service importları
- `buildSourceOrderContext`
- `canAccessRevisionCompare`
- `show()` source order context + revision compare URL
- `revisionCompare()`
- `applyRevision()`
- `buildRevisionApplySummary()`
- `revisionApplyInfrastructureReady()`
- `edit()` source order context + revision compare URL

### routes/web.php içinde Commit C'ye ait route hunkları
- `admin.promotion-quotes.revision-compare`
- `admin.promotion-quotes.revision-apply`

### public/css/prodelya-admin.css içinde revision compare hunks ayrılabiliyor mu
- Evet.
- `rg` çıktısına göre blok net biçimde `.order-revision-compare` namespace'i altında toplanmış.
- Bu nedenle CSS tarafında file-level değil ama block-level ayrıştırma mümkün.

## 5. Ortak Dosya Hunk Risk Tablosu

| Dosya | Commit A hunkları | Commit B hunkları | Commit C hunkları | Dışarıda bırakılacak hunklar | Risk | Staging yöntemi |
|---|---|---|---|---|---|---|
| `routes/web.php` | Yok | `revision-draft`, `repeat-order-draft` | `revision-compare`, `revision-apply` | `/catalog/search`, BOM/boşluk cleanup | Orta | `git add -p` |
| `app/Http/Controllers/Admin/OrderController.php` | Yok | clone service injection + `createRevisionDraft` + `createRepeatOrderDraft` + `createCopiedQuoteDraft` | Yok | `index()` view/filter/tab hunkları, `show()` UI hunkları | Orta | `git add -p` |
| `app/Http/Controllers/Admin/PromotionQuoteController.php` | Yok | Yok | revision compare/apply/source order context hunks | send channel, WhatsApp, quote list filters, Product Hub warning text | Yüksek | geçici patch veya çok dikkatli `git add -p` |
| `app/Models/Order.php` | copy metadata + copy helper'ları + source/copy relations | dolaylı bağımlılık | revision relations | quote list scopes | Orta | `git add -p` |
| `public/css/prodelya-admin.css` | Yok | Yok | yalnız `.order-revision-compare` namespace bloğu | quote detail compact, order detail, genel token/layout değişimleri | Yüksek | elle bölmek gerekiyor veya geçici patch |

Ek not:
- `Order.php` için dosya bazlı safe staging uygun değil; çünkü Commit A ve C aynı dosyada.
- `PromotionQuoteController.php` için en güvenli yöntem:
  önce hedef hunklardan patch üretmek, sonra patch ile stage etmek.
- `public/css/prodelya-admin.css` için en güvenli yöntem:
  `.order-revision-compare` namespace bloğunu ayrı patch olarak stage etmek.

## 6. Migration Durumu

### copy metadata migration
- Dosya:
  `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
- Durum:
  `Ran`
- Özellik:
  repeat/revision metadata alanları
- Risk:
  Düşük
- Not:
  additive, nullable alanlar ekliyor

### order revisions migration
- Dosya:
  `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
- Durum:
  `Ran`
- Özellik:
  revision header + revision changes tabloları
- Risk:
  Düşük-orta
- Not:
  Commit C altyapısı için gerekli

### migrate status
- `php artisan migrate:status` sonucu iki ilgili migration da `Ran`
- Pending migration görülmedi

### risk
- Migration execute edilmedi; yalnız durum okundu
- Forward-safe/additive görünüyorlar
- Commit A migration'ı Commit A ile, Commit C migration'ı Commit C ile aynı checkpoint içinde tutulmalı

## 7. Güvenlik Kontrolü

### finans/cari mutate riski
- Repeat/revision draft clone:
  finans, cari, ödeme, current account transaction geçmişi clone edilmiyor
- Revision apply:
  cari/tahsilat/payment kayıtlarını mutate etmiyor
  yalnız ticari görünüm alanları ve teslim tipi güncelleniyor

### tenant isolation
- Commit B:
  `OrderController` tenant eşleşmesi + permission guard içeriyor
- Commit C:
  `PromotionQuoteController` tenant guard içeriyor
  `OrderRevisionApplyService` revision/order/revisionQuote tenant eşleşmesini ayrıca doğruluyor

### no-sensitive-leak
- İlgili test yüzeyi mevcut:
  - `OrderRevisionCompareNoSensitiveLeakTest`
  - `OrderRevisionRecordServiceNoSensitiveLeakTest`
  - `RevisionRepeatOrderNoSensitiveLeakTest`
- Clone blacklist ve revision summary sanitizer güçlü görünüyor

### operation history korunması
- Repeat/revision draft:
  operasyon geçmişi yeni draft'a taşınmıyor
- Revision apply:
  procurement/production/delivery/payment satır geçmişini overwrite etmiyor

## 8. Test Sonuçları

Çalıştırılan komutlar:
- `php artisan test --filter="RepeatOrder|RevisionRepeatOrder|OrderRevisionDraft"`
  - 15 test geçti
  - 111 assertion
- `php artisan test --filter="OrderRevision|Revision"`
  - 56 test geçti
  - 459 assertion
- `php artisan test --filter="OrderRevisionMigrationSafety|OrderRevisionCompare|OrderRevisionApply"`
  - 16 test geçti
  - 96 assertion
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
  - 14 test geçti
  - 111 assertion

Toplam:
- 101 test geçti
- 777 assertion geçti
- Fail yok

## 9. Net Karar
- Commit A-B-C uygulanabilir, kullanıcı onayı bekleniyor

Gerekçe:
- Hedef hunks netleşti
- İlgili migration'lar `Ran`
- Çekirdek revizyon/repeat-order testleri temiz geçti
- Product Hub kısa regresyonu da temiz geçti
- Tek uyarı:
  Commit B ve özellikle Commit C dosya bazlı değil, seçici hunk staging ile uygulanmalı

## 10. Sonraki Adım
- Kullanıcı onayı sonrası `REVISION-CHECKPOINT-A-B-C-COMMIT-APPLY`

Uygulama sırası önerisi:
- önce Commit A tek başına uygulanmalı
- sonra Commit B
- sonra Commit C

Neden bu sıra:
- Commit A model/migration metadata temelini kurar
- Commit B clone draft akışını ekler
- Commit C revision compare/apply altyapısını bunun üstüne oturtur
