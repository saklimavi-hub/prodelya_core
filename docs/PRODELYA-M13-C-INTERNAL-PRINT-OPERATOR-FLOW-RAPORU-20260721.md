# PRODELYA M13-C Internal Print Operator Flow Raporu

Tarih: 2026-07-21
Durum: READY — INTERNAL PRINT OPERATOR FLOW — MANUAL SMOKE REQUIRED

## Kapsam

Bu fazda yalnız iç üretim işleri için sade operatör ekranı eklendi. Canonical iş birimi exact `order_item_print_productions.id` ve `order_item_print_id` olarak korundu. Fason/dış üretim, public/mobile ayrı ekran, Work Form PDF, schema, global CSS, fiyat/cari ve staging/commit kapsam dışı bırakıldı.

## Uygulanan Dar Hunklar

- `routes/web.php`
  - `GET /admin/productions/{production}/operator`
  - Named route: `admin.productions.operator`
  - `/{production}` show route'undan önce konumlandırıldı.

- `app/Http/Controllers/Admin/ProductionController.php`
  - `operator()` read-only ekran action'ı eklendi.
  - Tenant scope, internal-only guard ve mevcut tenant permission/assigned-user erişimi uygulandı.
  - Pool iç üretim CTA'ları hazır/devam/kısmi durumlarda operator route'a yönlendirildi.
  - `updateStatus()` mevcut workflow route olarak kaldı; yalnız `return_to=operator` ile işlem sonrası operatör ekranına dönebilme eklendi.
  - ProductionWorkflowService quantity/status semantiği değiştirilmedi.

- `resources/views/admin/productions/operator.blade.php`
  - Header, tek aktif odak, adet özeti, exact baskı bilgileri, onaylı grafik, production photo upload ve kısa geçmiş yüzeyleri eklendi.
  - Tek görünür primary action kuralı korundu.
  - Fotoğraf yükleme existing `admin.work-forms.attachments.store` route'unu kullanıyor.
  - Mobile camera input: `accept="image/*" capture="environment"`.
  - Fiyat, maliyet, kâr, cari, raw file path ve public token gösterilmedi.

- `public/css/prodelya-admin.css`
  - Yalnız `.pd-ui-v1-internal-operator*` scoped CSS eklendi.
  - Global `:root`, `.pd-btn`, `.pd-card`, input, sidebar, modal veya sticky primitive değişmedi.

- `tests/Feature/InternalOperatorFlowTest.php`
  - Internal pool CTA operator route kontratı.
  - Exact print identity/data attribute kontratı.
  - Outsourced job operator route reject kontratı.
  - Existing update-status route ile start + operator redirect kontratı.
  - Quantity semantics ve production photo camera input kontratı.
  - Completed job read-only kontratı.

## Kanıtlanan Sözleşmeler

| Alan | Sonuç |
|---|---|
| Canonical job | Exact `OrderItemPrintProduction` ve exact `order_item_print_id` |
| Internal-only route | Fason/outsourced operator route 404 |
| Workflow write | Existing `admin.productions.update-status` reused |
| Photo write | Existing Work Form attachment flow reused |
| Tek primary CTA | Operator HTML hedef testte 1 primary action |
| Sensitive leak | `price_snapshot`, `subcontractor_cost`, `current_account` operatör HTML'inde yok |
| Scoped UI | Sadece `.pd-ui-v1-internal-operator*` CSS |

## Çalıştırılan Testler

PASS:

- `php -l app/Http/Controllers/Admin/ProductionController.php`
- `php -l routes/web.php`
- `php -l tests/Feature/InternalOperatorFlowTest.php`
- `php artisan route:list --name=admin.productions.operator`
- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test tests/Feature/InternalOperatorFlowTest.php --stop-on-failure` — 5 test / 42 assertion
- `php artisan test tests/Feature/ProductionPoolRouteMethodGroupingTest.php --stop-on-failure` — 5 test / 33 assertion
- `php artisan test tests/Feature/ProductionReadinessPerPrintGraphicTest.php --stop-on-failure` — 6 test / 61 assertion
- `php artisan test tests/Feature/ProductionUiTest.php --stop-on-failure` — 6 test / 81 assertion
- `php artisan test tests/Feature/AdminSmokeTest.php --stop-on-failure` — 59 test / 214 assertion

Residual / M13-C dışı drift:

- `php artisan test --filter=Production --stop-on-failure` — FAIL: `ProductionEndToEndSmokeTest::test_production_end_to_end_smoke_flow_is_safe_and_complete`
  - Beklenti: `İç Üretimde`
  - Rendered order detail: `Üretim Bekliyor`
  - Yüzey: order detail / end-to-end smoke; M13-C operator route/view dışı.

- `php artisan test --filter=WorkForm --stop-on-failure` — FAIL: `WorkFormPdfTest::test_pdf_service_renders_fiyatsiz_html_with_qr_and_turkish_characters`
  - Beklenti: `Tedarik Bekliyor`
  - Rendered PDF: `Talep Hazırlanacak`
  - Yüzey: Work Form PDF; bu fazda dokunulmaması gereken alan.

## Manuel Smoke Checklist

- `/admin/productions?route=internal` içinde ready internal job CTA `Üretimi Aç` operator route'a gitmeli.
- `/admin/productions/{id}/operator` internal job için 200 dönmeli.
- Outsourced/fason job aynı route'ta 404 dönmeli.
- Pending ready job: tek primary CTA `Üretime Başla`.
- Internal/in-progress job: tek visible primary `Üretim Sonucu Gir`, açılan panelden kısmi/tamamlandı/sorun işlemleri.
- Partial job: tek visible primary `Kalanı Tamamla`.
- Completed job: read-only görünüm.
- Fotoğraf input'u mobil kamerayı açmalı; upload existing Work Form attachment akışına düşmeli.
- Fiyat/cari/maliyet/public token/file path görünmemeli.

## Staging / Commit

Staging ve commit yapılmadı.
## M13-C1 Final Hotfix Addendum

Tarih: 2026-07-21
Durum: READY — M13-C1 HOTFIX — MANUAL BROWSER SMOKE REQUIRED

### Kapanan Dar Problemler

| Problem | Root cause | Uygulanan çözüm |
|---|---|---|
| Operatör `Atanmamış` çelişkisi | İç üretime başlatma mevcut üretim satırında `assigned_to` alanını yazmıyordu; eski başlamış/kısmi işlerde de ekranda yalnız ilişki alanı okunuyordu. | `assignInternal()` mevcut atamayı koruyup yoksa current user ile atıyor. Operator ekranı eski kayıtlar için internal assignment/start/partial activity creator fallback'i kullanıyor. |
| Onaylı grafik spinner | Operator preview görseli explicit yüklenme/hata durumlarını ayırmıyordu. | Exact production-ready graphic attachment secure preview URL'si kullanıldı; product/sibling image kullanılmadı. Load/error fallback eklendi. |
| İngilizce geçmiş label | Eski/stored event text `Production Partially Completed` kullanıcıya raw düşebiliyordu ve raw action filtreleri normalized key'i kaçırabiliyordu. | Activity label resolver normalized key ile Türkçe label döndürüyor; operator history filtresi de normalized production action key kullanıyor. Stored audit kayıtları değiştirilmedi. |

### Korunan Sözleşmeler

- 250 planlanan → 100 tamamlanan / 150 kalan quantity semantiğine dokunulmadı.
- Existing `ProductionWorkflowService` akışı korundu; yalnız internal assignment tutarlılığı daraltıldı.
- Work Form photo upload route/storage akışına dokunulmadı.
- İlk görünümde tek primary CTA kuralı korundu.
- Outsourced/fason, production pool grouping, public/mobile, mail/recipient, schema ve global CSS değiştirilmedi.

### M13-C1 Test Sonuçları

PASS:

- `php -l app/Http/Controllers/Admin/ProductionController.php`
- `php -l app/Services/ProductionWorkflowService.php`
- `php -l app/Support/WorkFormActivityLabelResolver.php`
- `php -l resources/views/admin/productions/operator.blade.php`
- `php -l tests/Feature/InternalOperatorFlowTest.php`
- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=InternalOperator --stop-on-failure` — 7 test / 60 assertion
- `php artisan test --filter=ProductionReadinessPerPrintGraphicTest --stop-on-failure` — 6 test / 61 assertion
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 test / 33 assertion
- `php artisan test --filter=WorkFormAttachment --stop-on-failure` — 5 test / 51 assertion
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 test / 144 assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 test / 214 assertion

### Manuel Smoke Durumu

- Browser acceptance henüz bu addendum içinde PASS olarak işaretlenmedi.
- Kapanış etiketi yalnız gerçek browser doğrulamasından sonra kullanılmalı: `MANUAL PASS — INTERNAL PRINT OPERATOR FLOW`.

### Staging / Commit

- Staging yapılmadı.
- Commit yapılmadı.

## M13-C2 Addendum — 2026-07-21

M13-C2 kapsamında operator start için explicit assignment zorunlu hale getirildi; gizli auto-assignment kaldırıldı. Operator fotoğraf upload'u Work Form attachment store'u kullanmaya devam ederken operator ekranına geri dönecek şekilde tenant/work-form/production guard'lı redirect desteği aldı. Activity/status label'ları normalized central resolver üzerinden Türkçe gösteriliyor.

PASS:

- `php artisan test --filter=InternalOperator --stop-on-failure` — 10 test / 98 assertion
- `php artisan test --filter=WorkFormAttachment --stop-on-failure` — 5 test / 51 assertion
- `php artisan test --filter=Production --stop-on-failure` — 139 test / 1989 assertion
- `php artisan test --filter=Graphic --stop-on-failure` — 114 test / 1568 assertion
- `php artisan view:cache`

Manual browser smoke bu addendumda PENDING bırakıldı. Kapanış etiketi yalnız kullanıcı doğrulamasından sonra `MANUAL PASS — INTERNAL PRINT OPERATOR FLOW` olabilir.

Staging/commit yapılmadı.
## M13-C3 Addendum — 2026-07-21

Production legacy route cleanup tamamlandı. İç üretim canonical operasyon yüzeyi `admin.productions.operator` olarak kilitlendi. Eski detail action tabları artık write surface olarak kullanılmıyor; operator seçimi operator ekranındaki compact panelde yapılıyor. Manual smoke durumu: PENDING.
