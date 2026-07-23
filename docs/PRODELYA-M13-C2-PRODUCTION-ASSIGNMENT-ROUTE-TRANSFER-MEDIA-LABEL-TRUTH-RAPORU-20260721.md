# PRODELYA M13-C2 — Production Assignment, Route Transfer, Media ve Label Truth Raporu — 2026-07-21

## Kapsam

Bu faz M13-D başlamadan yalnız üretim atama/rota transferi, operatör medya dönüşü ve canlı Türkçe label truth hotfix kapsamındadır. Fason mobil/public, schema/migration, global CSS, procurement pricing, graphic approval mail/recipient ve public grafik onay akışlarına dokunulmadı. Staging ve commit yapılmadı.

## Read-only Audit Özeti

| Konu | Canonical kaynak | Bulgu | Uygulanan plan |
|---|---|---|---|
| Operatör seçimi | `order_item_print_productions.assigned_to` + `ProductionWorkflowService` | Internal start gizli/current-user assignment ile başlayabiliyordu. | Start öncesi explicit operator şartı eklendi; atama ve start ayrı kaldı. |
| İç → dış / dış → iç transfer | Aynı `OrderItemPrintProduction.id` | Route değişimi yeni production/print row üretmeden mevcut exact row üzerinde yapılmalı. | `updateAssignment()` aynı row'u güncelliyor; completed/cancelled bloklu, başlamış/kısmi işte gerekçe zorunlu. |
| Partial transfer | `completed_quantity`, `remaining_quantity` | Kısmi üretim miktarı rota değişiminde korunmalı. | Quantity alanlarına dokunulmadı; yalnız route/assignment alanları güncelleniyor. |
| Exact grafik | `order_item_print_id` bağlı graphic latest attachment | Operator/production alanında product/sibling görsel kullanılmamalı. | Operator preview exact production-ready graphic secure route üzerinden kaldı; product fallback yok. |
| Operator fotoğrafı | Work Form attachment `production_photo` | Upload sonrası İş Formu'na yönlenme operator akışını kırıyordu. | Attachment store redirect target operator route'unu tenant/work_form/production guard ile destekliyor. |
| Activity label | `WorkFormActivityLabelResolver` | Legacy/English action title'ları raw görünebiliyordu. | Normalized action key + Türkçe title/sentence eklendi. |
| UTF-8 | Controller success flash | `Dosya Ä°ÅŸ...` ve `kaldÄ±rÄ±ldÄ±` mojibake kaldı. | Mesajlar gerçek UTF-8 Türkçe olarak düzeltildi. |

## Route Kanıtı

`php artisan route:list --path=admin/productions`:

- `GET admin/productions` → `admin.productions.index`
- `GET admin/productions/{production}` → `admin.productions.show`
- `PATCH admin/productions/{production}/assignment` → `admin.productions.update-assignment`
- `GET admin/productions/{production}/operator` → `admin.productions.operator`
- `PATCH admin/productions/{production}/status` → `admin.productions.update-status`

`php artisan route:list --name=work-forms`:

- `POST admin/work-forms/{workForm}/attachments` → canonical Work Form attachment store
- `GET admin/work-form-attachments/{attachment}/preview` → secure admin preview
- `GET takip/is-formu/{token}/dosya/{attachment}` → public secure work-form attachment

## Test Sonuçları

PASS:

- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=InternalOperator --stop-on-failure` — 10 test / 98 assertion
- `php artisan test --filter=ProductionReadinessPerPrintGraphicTest --stop-on-failure` — 6 test / 61 assertion
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 test / 33 assertion
- `php artisan test --filter=WorkFormAttachment --stop-on-failure` — 5 test / 51 assertion
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 test / 144 assertion
- `php artisan test --filter=Graphic --stop-on-failure` — 114 test / 1568 assertion
- `php artisan test --filter=Procurement --stop-on-failure` — 131 test / 1836 assertion
- `php artisan test --filter=Order --stop-on-failure` — 263 test / 2371 assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 test / 214 assertion
- `php artisan test --filter=Production --stop-on-failure` — 139 test / 1989 assertion

Test drift düzeltmeleri test katmanında yapıldı: eski `pp-*` production UI class beklentileri güncel `pd-production-*` sözleşmesine taşındı; setup/klişe global default disabled sözleşmesi korundu; doğru Türkçe `Müşteri` label'ı bozuk ASCII kontrolüyle yanlış fail etmeyecek şekilde ham HTML assertion kullanıldı.

## Manuel Smoke Checklist

PENDING — kullanıcı browser smoke bekleniyor:

- Operatör seçmeden internal start mümkün değil.
- Operatör seçimi ayrı activity, start ayrı activity oluşturuyor.
- Internal ↔ outsourced transfer aynı exact production/print row'u koruyor.
- Partial transfer completed/remaining miktarlarını koruyor ve gerekçe istiyor.
- Completed/cancelled route transfer bloklanıyor.
- Exact final graphic operator/production/work-form graphic bölümünde secure route ile görünüyor; product/sibling image kullanılmıyor.
- Operator production photo upload sonrası operator ekranına dönüyor ve aynı attachment Work Form Üretim Fotoğrafları bölümünde görünüyor.
- History/status/readiness label'ları Türkçe; `Production Partially Completed` gibi raw İngilizce label kalmıyor.

## Durum

READY — PRODUCTION ASSIGNMENT ROUTE TRANSFER MEDIA AND LABEL TRUTH — MANUAL SMOKE REQUIRED
## M13-D Addendum — 2026-07-21

Dış Baskı / Fason Atama Akışı ayrı exact production route olarak eklendi: `admin.productions.subcontract-assignment`.

- Atama: readiness beklemeden, yalnız eligible tenant fason firmalarıyla yapılır.
- Gönderim: exact graphic + procurement readiness ve atanmış fason firma gerektirir.
- Current-account/fiyat/maliyet alanları assignment yüzeyinde yoktur; assignment cari hareket üretmez.
- Exact production row korunur; yeni production/print row oluşturulmaz.
- M13-D manual browser smoke PENDING.

Automated gates: Production, Order, CurrentAccount, AdminSmoke, target M13-D, view:cache PASS. WorkForm broad içinde iki out-of-scope eski tedarik label assertion drift'i kaldı.
