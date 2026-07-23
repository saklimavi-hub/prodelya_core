# Quote Currency Selective Staging Safety Audit Raporu — 2026-07-11

## 1. Yönetici özeti

- Audit kapsamı, mevcut clean HEAD `bc07ac0` üzerinde kabul edilen `17 failed` baseline'ı tekrar tartışmadan Quote Currency değişikliklerinin dosya ve hunk bazında güvenli ayrıştırılabilirliğini denetlemektir.
- Başlangıçta staged alan boştur.
- Worktree içinde Quote Currency ile doğrudan ilişkili `20` dosya incelenmiştir.
- İncelenen currency kapsamı:
  - tracked dosya: `15`
  - untracked dosya: `5`
  - tracked hunk: `118`
- Sonuç:
  - saf yeni currency dosyası: `4`
  - saf currency diff dosyası: `10`
  - mixed ama patch ile ayrıştırılabilir dosya: `5`
  - mixed ve ayrıştırılamaz dosya: `0`
  - report-only dosya: `1`
- Nihai karar: `SAFE FOR SELECTIVE STAGING`

## 2. Başlangıç Git güvenliği

- HEAD: `bc07ac0`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Başlangıç kanıtı:
  - `git status --short`: staged giriş yok, worktree kirli
  - `git diff --cached --stat`: boş
  - `git diff --cached --name-only`: boş
- Başlangıçta blocker oluşmadı; staged alan dolu olmadığı için audit sürdürüldü.

## 3. Önceki attribution kanıtının kabulü

- Kabul edilen kanıt:
  - global suite: `1832 total / 1815 passed / 17 failed`
  - clean HEAD `bc07ac0` üzerinde aynı seçilmiş failure ailesi tekrar `17 failed`
  - `ONLY_CURRENT` boş
  - `ONLY_BASELINE` boş
  - current observed failure set için `quote-currency-attributed failure = 0`
- Bu audit turunda historical `14 -> 17` yeniden üretimi yapılmamıştır.
- `14 -> 17` attribution sonucunun `UNKNOWN` kalması selective staging kararında tek başına blocker kabul edilmemiştir.

## 4. Ground truth ve rapor/Git karşılaştırması

- Quote Currency implementation raporundaki `Changed files` listesi Git ground truth ile büyük ölçüde uyumlu.
- Doğrulanan ortak dosyalar:
  - `app/Models/Order.php`
  - `app/Models/OrderItemPrint.php`
  - `app/Services/PromotionQuote/QuoteCurrencyAccessService.php`
  - `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Services/QuoteSendSnapshotBuilder.php`
  - `app/Services/PromotionQuotePdfService.php`
  - `app/Services/CustomerFacingPriceDisplayService.php`
  - `app/Services/QuoteApprovalService.php`
  - `app/Http/Controllers/PublicQuoteApprovalController.php`
  - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - `routes/web.php`
  - `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`
  - `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`
- Rapor/Git uyumsuzlukları:
  - Git diff'te ayrıca `app/Http/Requests/Admin/StoreOrderPaymentRequest.php` ve `app/Services/OrderPaymentService.php` var.
  - Git diff'te ayrıca `tests/Feature/AdminSmokeTest.php` ve `tests/Feature/FullOperationalFlowSmokeTest.php` var.
  - Bu dört dosya implementation raporunda ayrı changed-file olarak listelenmemiş, fakat raporun `Module regressions` bölümünde davranışsal olarak açıklanmış.
  - Worktree'de rapor dışı kirli ortak dosyalar da var: `app/Http/Controllers/Admin/OrderController.php`, `config/admin_menu.php`, `public/css/prodelya-admin.css`, `tests/Feature/QuoteOrderManualSmokeRouteTest.php`. Bunlar Quote Currency kapsamı dışındadır.
- Sonuç:
  - Quote Currency kapsamı Git kanıtıyla doğrulanmıştır.
  - Feature alanı, worktree’deki diğer kirli fazlardan ayrıştırılabilir durumdadır.

## 5. Dosya sınıflandırma tablosu

| Dosya | Sınıf | Gerekçe |
|---|---|---|
| `app/Services/PromotionQuote/QuoteCurrencyAccessService.php` | `PURE_NEW_CURRENCY_FILE` | Yeni dosya; yalnız module gate ve permission payload üretir. |
| `app/Services/PromotionQuote/QuoteCurrencyPricingService.php` | `PURE_NEW_CURRENCY_FILE` | Yeni dosya; document/base/source snapshot ve conversion contract çekirdeği. |
| `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php` | `PURE_NEW_CURRENCY_FILE` | Yeni additive migration; yalnız snapshot kolonlarını ekler. |
| `tests/Feature/PromotionQuoteCurrencySnapshotTest.php` | `PURE_NEW_CURRENCY_FILE` | Yeni hedefli regression testi; yalnız quote currency davranışlarını kapsar. |
| `app/Http/Controllers/PublicQuoteApprovalController.php` | `PURE_CURRENCY_DIFF` | Public yüzeyde `TRY -> TL` etiketleme ve money format düzeyi. |
| `app/Http/Requests/Admin/StoreOrderPaymentRequest.php` | `PURE_CURRENCY_DIFF` | `TL/TRY` alias doğrulaması; diff bütünü currency compatibility. |
| `app/Models/OrderItemPrint.php` | `PURE_CURRENCY_DIFF` | `pricing_snapshot` fillable/cast ekleri. |
| `app/Services/CustomerFacingPriceDisplayService.php` | `PURE_CURRENCY_DIFF` | Snapshot tabanlı customer totals ve display currency davranışı. |
| `app/Services/OrderPaymentService.php` | `PURE_CURRENCY_DIFF` | Canonical `TRY` normalization ve payment currency guard. |
| `app/Services/PromotionQuotePdfService.php` | `PURE_CURRENCY_DIFF` | PDF toplamlarını snapshot alanlarından okur; display currency normalize eder. |
| `app/Services/QuoteApprovalService.php` | `PURE_CURRENCY_DIFF` | İlk gönderimde `currency_snapshot_locked_at` kilidi ekler. |
| `app/Services/QuoteSendSnapshotBuilder.php` | `PURE_CURRENCY_DIFF` | Send snapshot içine document totals ve currency metadata taşır. |
| `tests/Feature/AdminSmokeTest.php` | `PURE_CURRENCY_DIFF` | Legacy `TL` beklentisini canonical `TRY` ile hizalar. |
| `tests/Feature/FullOperationalFlowSmokeTest.php` | `PURE_CURRENCY_DIFF` | Payment smoke payload `TRY` ile hizalanır. |
| `docs/QUOTE-CURRENCY-CONVERSION-SNAPSHOT-IMPLEMENTATION-RAPORU-20260710.md` | `REPORT_ONLY` | Feature raporu; kod bağımlılığı yok. |
| `app/Http/Controllers/Admin/PromotionQuoteController.php` | `MIXED_PATCH_SEPARABLE` | Currency hunkları büyük ama ayrık; aynı dosyada unrelated revision/encoding/copy hunks da var. File-level stage edilmemeli. |
| `app/Models/Order.php` | `MIXED_PATCH_SEPARABLE` | Currency alan ekleri temiz, fakat revision/copy-type etrafında whitespace-only kir var. |
| `resources/views/admin/promotion-quotes/_form-workspace.blade.php` | `MIXED_PATCH_SEPARABLE` | Currency select, status, refresh UI ve JS display hunks ayrık; patch ile alınabilir. |
| `resources/views/admin/promotion-quotes/show.blade.php` | `MIXED_PATCH_SEPARABLE` | Currency label ve refresh/ack UI ayrık hunklar halinde. |
| `routes/web.php` | `MIXED_PATCH_SEPARABLE` | Currency refresh/ack route hunkları ayrık; aynı dosyada revision route whitespace kirleri var. |
| `app/Http/Controllers/Admin/OrderController.php` | `OUT_OF_SCOPE` | Revision/repeat-order draft alanı; quote currency checkpointine ait değil. |
| `config/admin_menu.php` | `OUT_OF_SCOPE` | BOM/son satır farkı; currency davranışı yok. |
| `public/css/prodelya-admin.css` | `OUT_OF_SCOPE` | Çok büyük ortak CSS hunkı; quote currency raporunun changed files listesinde yok. |
| `tests/Feature/QuoteOrderManualSmokeRouteTest.php` | `OUT_OF_SCOPE` | Untracked unrelated test. |

## 6. Ortak dosya hunk envanteri

| Dosya / bölüm | Davranış | Bağımlılık | Komşu risk | Patch güvenliği | Hunk alınmazsa |
|---|---|---|---|---|---|
| `PromotionQuoteController::__construct` | `QuoteCurrencyAccessService` ve `QuoteCurrencyPricingService` inject eder | yeni service dosyaları | aynı blokta unrelated yok | güvenli | controller resolve edemez |
| `PromotionQuoteController::quoteCurrencyAccess/resolveRequestedQuoteCurrency/currencySelectOptions/quoteCurrencyViewPayload` | access gate, select seçenekleri, status payload | access service, pricing service, `Order` alanları | ayrı büyük helper bloğu | güvenli | form/show payload eksik, foreign currency validation bozulur |
| `PromotionQuoteController::buildQuoteItemSnapshot/buildQuotePrintSnapshot/refreshDraftQuoteCurrencySnapshots/persistQuoteCurrencyMetadata` | item/print/document snapshot persistence çekirdeği | migration, `Order`, `OrderItemPrint`, pricing service | ayrı helper bloğu | güvenli | refresh/save sonrası snapshot zinciri eksik |
| `PromotionQuoteController::create` | create view'e `quoteCurrency` payload taşır | access helper | aynı create akışında | güvenli | create UI yalnız legacy varsayımda kalır |
| `PromotionQuoteController::edit/show` | edit/show view'e `quoteCurrency` payload taşır | access helper, `Order` casts | revision compare çevresi aynı method içinde ama farklı hunk | güvenli | detail/edit UI currency status ve aksiyonları çalışmaz |
| `PromotionQuoteController::store/update` | currency request normalize, snapshot build, totals persist | migration, `Order`, `OrderItemPrint`, pricing service | update içinde aynı methoda gömülü; file-level stage sakıncalı | güvenli ama patch şart | data write path legacy kalır, testler kırılır |
| `PromotionQuoteController::refreshCurrencySnapshot/acknowledgeCurrencySnapshot` | draft-only refresh/ack endpointleri | routes, access helper, snapshot helpers | ayrı method bloğu | güvenli | route tanımlansa bile method yoksa runtime error |
| `PromotionQuoteController::buildWarningPayload` | kategori copy metni düzeltmesi | yok | currency dışı | hariç tutulmalı | quote currency için etkisiz |
| `Order::$fillable/$casts` | yeni snapshot kolonlarını modele açar | migration | revision whitespace kirleri | güvenli ama patch şart | controller save path `MassAssignment` veya cast kaybı |
| `_form-workspace.blade.php` top PHP prelude | `quoteCurrency`, `currencyDisplay` varsayımları | controller payload | aynı üst blokta unrelated yok | güvenli | view undefined / yanlış etiket |
| `_form-workspace.blade.php` select + status + refresh forms | document currency seçimi ve kur aksiyonları | routes, controller methods | ayrık section | güvenli | UI feature görünmez |
| `_form-workspace.blade.php` summary totals label hunks | `TRY -> TL` display | JS helper | ayrı | güvenli | UI etiket yanlışı |
| `_form-workspace.blade.php` JS `displayCurrencyLabel`, `formatMoney`, `currentQuoteCurrency` | browser display only | view payload | aynı JS alanında | güvenli | label drift, print setup modal hatalı gösterim |
| `show.blade.php` currencyLabel + guide notice | detail screen compact currency status ve refresh/ack | controller payload, routes | ayrık | güvenli | detail tarafı eksik UI |
| `routes/web.php` currency refresh/ack routes | POST endpoint açar | controller methods | revision route whitespace komşuluğu | güvenli ama patch şart | button action 404/route missing |

## 7. Bağımlılık zincirleri

- `Migration -> model casts/fillable -> pricing service -> controller save`
  - gerekli dosyalar: migration, `Order`, `OrderItemPrint`, `QuoteCurrencyPricingService`, `PromotionQuoteController`
  - ayrıştırma durumu: tamamı alınabilir
  - not: migration ile model/controller save aynı feature commit zincirinde olmalı
- `Access service -> form currency seçenekleri -> backend validation/gate`
  - gerekli dosyalar: `QuoteCurrencyAccessService`, `PromotionQuoteController`, `_form-workspace.blade.php`
  - ayrıştırma durumu: alınabilir
- `Item price snapshot -> print pricing snapshot -> PDF/public customer display`
  - gerekli dosyalar: `QuoteCurrencyPricingService`, `OrderItemPrint`, `CustomerFacingPriceDisplayService`, `PromotionQuotePdfService`, `PublicQuoteApprovalController`, `QuoteSendSnapshotBuilder`
  - ayrıştırma durumu: alınabilir
- `Quote approval -> currency lock -> send snapshot builder`
  - gerekli dosyalar: `QuoteApprovalService`, `QuoteSendSnapshotBuilder`, `Order`
  - ayrıştırma durumu: alınabilir
- `Refresh/acknowledge routes -> controller methods -> authorization -> draft-only guard`
  - gerekli dosyalar: `routes/web.php`, `PromotionQuoteController`, `QuoteCurrencyAccessService`, `Order`
  - ayrıştırma durumu: alınabilir
- `TL alias -> canonical TRY normalization -> payment request/service -> smoke tests`
  - gerekli dosyalar: `StoreOrderPaymentRequest`, `OrderPaymentService`, `AdminSmokeTest`, `FullOperationalFlowSmokeTest`
  - ayrıştırma durumu: alınabilir
- Uncommitted başka faz dependency blocker'ı görülmedi.
- Ancak `PromotionQuoteController`, `Order`, `routes/web.php`, Blade dosyaları file-level stage edilirse unrelated hunk sızma riski oluşur.

## 8. Migration ve veri güvenliği

- Migration additive ve reversible'dır.
- `orders` tablosuna nullable alanlar ekliyor; destructive değişiklik yok.
- `order_item_prints.pricing_snapshot` JSON kolonu nullable ekleniyor.
- `Schema::hasColumn` koruması duplicate column riskini azaltıyor.
- `down()` içinde `dropConstrainedForeignId` kullanımı MySQL/SQLite için makul, fakat deployment turunda schema driver davranışı ayrıca smoke edilmelidir.
- Local geçmiş notu:
  - implementation raporu, `migrate` başarılı ama `migrate:status --path=...` halen `Pending` döndü diyor.
  - Bu, staging safety audit açısından blocker değildir.
  - Bu, deploy/runbook açısından ayrıca not edilmelidir.
- Backup dosyası `database/backups/database-before-quote-currency-2026-07-10.sqlite` Git’e alınmamalıdır.
- Migration ayrı docs-only veya tek başına infra commit olmamalı.
- Öneri:
  - migration, `Order`/`OrderItemPrint` model açılımları ve controller/service persistence çekirdeği aynı commit zincirinde tutulmalı.
- Backward compatibility:
  - eski `TL` kayıtları runtime katmanda `TRY` normalize ediliyor.
  - payment compatibility bunun için request/service hunklarıyla desteklenmiş.

## 9. Permission, tenant ve customer-facing güvenlik sınırları

- Tenant izolasyonu:
  - `PromotionQuoteController` refresh/ack methodları tenant check içeriyor.
  - quote access helper tenant tabanlı module gate üretiyor.
- Permission / feature gate:
  - `multi_currency` kapalıysa yalnız `TRY`
  - `can_view_currency_details` yoksa kur status ve aksiyonlar görünmüyor
  - refresh ve acknowledge finance-detail görünürlüğüne bağlı
- Draft-only:
  - refresh/ack methodları `canBeEdited()` guard ile korunuyor.
- Customer-facing leakage:
  - `CustomerFacingPriceDisplayService`, `PromotionQuotePdfService`, `PublicQuoteApprovalController`, `QuoteSendSnapshotBuilder` document-facing snapshot kullanıyor.
  - supplier cost, base cost, margin, raw internal snapshot alanları public payload’a taşınmıyor.
- Browser-authoritative truth yok:
  - report ve controller diff, server-side snapshot/totals kurulduğunu doğruluyor.
- Manual sales price protection:
  - refresh helper, `manual_sales_price_override` üzerinden actual satış fiyatını koruyor.

## 10. Selective staging riskleri

- File-level stage kesinlikle yapılmaması gereken dosyalar:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `routes/web.php`
  - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
- Kapsam dışı ve hiç stage edilmemesi gereken dosyalar:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `tests/Feature/QuoteOrderManualSmokeRouteTest.php`
- Teknik risk:
  - `PromotionQuoteController` içindeki currency hunkları büyük ve method içi olduğundan patch dosyası dikkatli hazırlanmalı.
  - `Order.php` ve `routes/web.php` içinde BOM/whitespace kirleri currency hunklarına komşu.
  - `_form-workspace.blade.php` içinde PHP prelude, Blade markup ve JS helper zinciri birlikte alınmalıdır; parçalanırsa UI kısmi bozulur.
- Buna rağmen blocker yok:
  - currency hunkları konum bazlı ayrık
  - uncommitted unrelated file dependency görünmüyor
  - compile/runtime zinciri tamamlanabilir

## 11. Önerilen commit planı

- Commit 1
  - amaç: currency schema ve pricing domain çekirdeği
  - dahil:
    - `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`
    - `app/Models/Order.php` yalnız currency alan/cast hunkları
    - `app/Models/OrderItemPrint.php`
    - `app/Services/PromotionQuote/QuoteCurrencyAccessService.php`
    - `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
    - `app/Http/Controllers/Admin/PromotionQuoteController.php` yalnız helper/persistence/store-update-refresh-ack currency hunkları
  - hariç:
    - `buildWarningPayload` copy hunkı
    - revision/encoding/whitespace hunks
  - önkoşul: patch staging
  - testler: `PromotionQuoteCurrencySnapshotTest`, quote create/edit hedefli testler
  - önerilen mesaj: `quotes: add currency snapshot persistence`
- Commit 2
  - amaç: customer-facing quote workflow ve UI entegrasyonu
  - dahil:
    - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
    - `resources/views/admin/promotion-quotes/show.blade.php`
    - `routes/web.php` yalnız currency refresh/ack route hunkları
    - `app/Services/QuoteSendSnapshotBuilder.php`
    - `app/Services/PromotionQuotePdfService.php`
    - `app/Services/CustomerFacingPriceDisplayService.php`
    - `app/Services/QuoteApprovalService.php`
    - `app/Http/Controllers/PublicQuoteApprovalController.php`
    - `app/Http/Controllers/Admin/PromotionQuoteController.php` yalnız create/edit/show payload hunkları
  - hariç:
    - revision route whitespace hunks
    - unrelated quote copy/text hunkları
  - önkoşul: Commit 1
  - testler: quote create/edit, PDF, public approval customer price display, quote send/approval
  - önerilen mesaj: `quotes: implement multi-currency pricing snapshots`
- Commit 3
  - amaç: canonical `TRY` payment alias compatibility
  - dahil:
    - `app/Http/Requests/Admin/StoreOrderPaymentRequest.php`
    - `app/Services/OrderPaymentService.php`
    - `tests/Feature/AdminSmokeTest.php`
    - `tests/Feature/FullOperationalFlowSmokeTest.php`
  - hariç: başka order/payment dosyası yok
  - önkoşul: Commit 1 ve 2
  - testler: admin smoke, full operational flow smoke, order payment/current account regresyonları
  - önerilen mesaj: `finance: normalize TRY payment currency aliases`
- Commit 4
  - amaç: hedefli quote currency regression coverage
  - dahil:
    - `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`
  - hariç: unrelated `QuoteOrderManualSmokeRouteTest.php`
  - önkoşul: Commit 1 ve 2
  - testler: `PromotionQuoteCurrencySnapshotTest`
  - önerilen mesaj: `tests: cover quote currency conversion snapshots`
- Commit 5
  - amaç: feature raporu
  - dahil:
    - `docs/QUOTE-CURRENCY-CONVERSION-SNAPSHOT-IMPLEMENTATION-RAPORU-20260710.md`
  - hariç: bu audit raporu
  - önkoşul: feature commitleri tamamlanmış olmalı
  - testler: yok
  - önerilen mesaj: `docs: add quote currency implementation report`

## 12. Staging-apply turu test matrisi

- Quote currency hedefli test:
  - `php artisan test --filter=PromotionQuoteCurrencySnapshotTest`
- Promotion Quote create/edit:
  - `php artisan test --filter=PromotionQuoteCreateEditUiRegressionTest`
- PDF:
  - `php artisan test --filter=PromotionQuotePdfOutputTest`
- Public approval customer price display:
  - `php artisan test --filter=PublicQuoteApprovalCustomerPriceDisplayTest`
- Quote send/approval:
  - `php artisan test --filter=\"QuoteSend|QuoteApproval|PublicQuoteApproval\"`
- Currency core:
  - `php artisan test --filter=Currency`
- Admin smoke:
  - `php artisan test --filter=AdminSmokeTest`
- Full operational flow smoke:
  - `php artisan test --filter=FullOperationalFlowSmokeTest`
- Order payment/current account normalization:
  - `php artisan test --filter=\"OrderPayment|CurrentAccount\"`
- Product Hub live product info ve catalog currency payload:
  - `php artisan test --filter=\"ProductHubLiveProductInfo|CatalogSearchCurrencyPayloadTest\"`
- Security/public leakage regresyonları:
  - `php artisan test --filter=\"PublicApprovalAndTrackingSecuritySmokeTest|PublicGraphicApprovalSecurityTest|PublicLinkScreensUxPolishTest\"`
- Final full suite:
  - `php artisan test`
- Full suite karar kuralı:
  - clean HEAD’de belgelenen mevcut `17` failure isim kümesi pre-existing baseline’dır
  - commit adayı sonrası yeni failure adı veya failure sayısı artışı kabul edilmez
  - hedefli currency testlerinin tamamı yeşil olmalıdır
  - mevcut `17` failure’dan birinin biçim değişmesi bile ayrıca incelenmelidir

## 13. Hariç tutulan kapsam

- `Order / Procurement Currency Carryover`
- historical `14 -> 17` delta attribution yeniden üretimi
- manual exchange rate
- canlı TCMB
- muhasebe multi-currency genişletmesi
- Matbaa V2
- Dieline V3
- ortak CSS cleanup
- menu/config cleanup

## 14. Final Git güvenliği

- Bu audit turunda staging yapılmadı.
- Commit yapılmadı.
- Uygulama veya test kodunda değişiklik yapılmadı.
- Yalnız bu audit raporu oluşturuldu.
- Audit sonunda staged alan yine boştur.

## 15. Nihai karar: `SAFE FOR SELECTIVE STAGING`

- Gerekçe:
  - Currency kapsamı dosya ve hunk bazında ayrıştırılmıştır.
  - Başka yarım fazın uncommitted değişikliğine zorunlu bağımlılık bulunmamıştır.
  - Mixed dosyalar file-level stage için unsafe olsa da patch-level stage ile güvenli ayrışmaktadır.
  - Migration, model, service, controller, Blade, route ve smoke-test zinciri bütünlüklü şekilde planlanabilmektedir.
  - Customer-facing ve public security sınırında açık sızıntı riski görülmemiştir.
  - Clean HEAD `17` failure baseline’ı current attribution için kabul edilmiş kullanılabilir kanıttır.

## 16. Bir sonraki prompt için kesin öneri

- Sonraki prompt adı:
  - `PRODELYA_V1_10.16.4_V5_QUOTE_CURRENCY_SELECTIVE_STAGING_APPLY`
- Bu promptta:
  - yalnız bu rapordaki commit planı izlenmeli
  - file-level stage yasak olmalı
  - `PromotionQuoteController`, `Order`, `routes/web.php`, `_form-workspace.blade.php`, `show.blade.php` patch tabanlı seçici staging ile alınmalı
  - kapsam dışı dosyalar özellikle korunmalı
  - her committen sonra ilgili hedefli testler ve final full suite çalıştırılmalı
