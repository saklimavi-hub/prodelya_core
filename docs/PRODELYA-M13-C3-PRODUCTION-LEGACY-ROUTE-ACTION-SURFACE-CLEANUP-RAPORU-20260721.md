# PRODELYA M13-C3 — Production Legacy Route and Action Surface Cleanup Raporu

Tarih: 2026-07-21
Durum: READY — PRODUCTION LEGACY ROUTE AND ACTION SURFACE CLEANUP — MANUAL SMOKE REQUIRED

## Kapsam

Bu fazda M13-E2 secure mobile/public takip akışı başlatılmadı. Yapılan iş yalnız admin production legacy action yüzeylerini canonical operator, fason atama ve fason takip ekranlarına yönlendirmek; production detail ekranını read-only karar/özet yüzeyine indirmek; write action redirectlerini whitelist ve canonical route resolver mantığına bağlamak oldu.

Staging ve commit yapılmadı.

## Canonical Route Matrix

| Üretim durumu | Canonical yüzey | Route name |
|---|---|---|
| İç üretim, aktif veya bekleyen | Operatör ekranı | `admin.productions.operator` |
| Dış/Fason, firma yok veya gönderilmedi | Fason atama ekranı | `admin.productions.subcontract-assignment` |
| Dış/Fason, gönderildi / kısmi / QC / sorun | Fason takip ekranı | `admin.productions.subcontract-tracking` |
| Tamamlandı / iptal / kalan adet 0 | Read-only production detail | `admin.productions.show` |
| Grafik bloklu | Grafik detayı | mevcut grafik route |
| Tedarik bloklu | Tedarik route'u | mevcut procurement route |

## Legacy URL Kararı

| Eski giriş | Yeni davranış |
|---|---|
| `/admin/productions/{id}?tab=islemler` | Duruma göre operator / subcontract-assignment / subcontract-tracking / show redirect |
| `/admin/productions/{id}?tab=ic-uretim` | Duruma göre canonical redirect |
| `/admin/productions/{id}?tab=dis-uretim` | Duruma göre canonical redirect |
| Production detail içindeki `İç Üretim`, `Dış Üretim / Fason`, `İşlemler` tabları | Kaldırıldı; detail artık `Genel Özet`, `Fotoğraflar`, `Geçmiş` |
| Production pool issue/QC eski `?tab=islemler` hedefleri | Canonical route resolver hedeflerine alındı |

Not: `resources/views/admin/productions/partials/_production_actions.blade.php` içinde legacy id/metinler dosya olarak duruyor; ancak `show.blade.php` artık bu partial'ı include etmiyor. Kör silme yapılmadı.

## Operatör Atama

`Operatör Seç` artık eski `?tab=islemler#atama-guncelle` ekranına gitmiyor. Operator ekranında compact `operator-assignment-panel` açılıyor. Atama kaydedilince `admin.productions.operator` ekranına dönüyor ve seçili operatör görünür hale geliyor.

`return_to` whitelist tokenları:

- `operator`
- `subcontract_assignment`
- `subcontract_tracking`
- `show`
- `index`

Raw URL, external URL ve arbitrary path kabul edilmiyor.

## Route Transfer

Internal → outsourced transfer mevcut exact production row üzerinde kalıyor ve `subcontract-assignment` ekranına dönüyor.

Outsourced → internal transfer mevcut exact production row üzerinde kalıyor ve `operator` ekranına dönüyor.

Kısmi miktar, exact print identity, graphic/procurement/photo/history bağları korunacak şekilde mevcut workflow service üzerinden ilerliyor. Workflow semantiği veya schema değiştirilmedi.

## Production Detail Cleanup

Production detail artık read-only summary yüzeyi:

- Genel Özet
- Fotoğraflar read-only inceleme
- Geçmiş read-only inceleme
- Canonical Akış paneli
- State'e göre tek canonical CTA

Detail içinde artık operator assignment, internal status write formu, subcontract assignment/tracking write formu veya duplicate photo upload formu render edilmiyor. Fotoğraf yükleme canonical operator/tracking ekranlarında kalıyor.

## Permission ve Tenant Güvenliği

- Foreign tenant production show hâlâ forbidden.
- Operator route yalnız internal production için açılıyor.
- Subcontract assignment/tracking route'ları yalnız dış/fason production state'leri için açılıyor.
- Return target whitelist testle kilitlendi.
- Canonical redirect permission bypass üretmemek üzere route-level guard'lar korunuyor.

## Değişen Dosya Alanları

Production C3 ile doğrudan ilgili alanlar:

- `app/Http/Controllers/Admin/ProductionController.php`
- `resources/views/admin/productions/operator.blade.php`
- `resources/views/admin/productions/show.blade.php`
- `resources/views/admin/productions/partials/_production_summary.blade.php`
- `resources/views/admin/productions/partials/_production_photos.blade.php`
- `public/css/prodelya-admin.css` içinde scoped production/operator stilleri
- `tests/Feature/ProductionLegacyRouteCleanupTest.php`
- `tests/Feature/ProductionCanonicalRouteResolverTest.php`
- Stale redirect/read-only beklenti hizalamaları: `ProductionUiTest`, `SubcontractorProductionCurrentAccountTransactionTest`, `TenantPrintSettingOperationCreationRulesTest`

## Test Kanıtı

PASS:

- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=ProductionLegacyRouteCleanupTest --stop-on-failure` — 6 test, 42 assertion
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 test, 146 assertion
- `php artisan test --filter=SubcontractorProductionCurrentAccountTransactionTest --stop-on-failure` — 2 test, 40 assertion
- `php artisan test --filter=TenantPrintSettingOperationCreationRulesTest --stop-on-failure` — 3 test, 64 assertion
- `php artisan test --filter=Production --stop-on-failure` — 159 test, 2223 assertion
- `php artisan test --filter=WorkFormAttachment --stop-on-failure` — 5 test, 51 assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 test, 214 assertion

## Manuel Smoke Checklist

PENDING kullanıcı doğrulaması:

- Operator ekranında `Operatör Seç` aynı sayfada panel açıyor.
- Operatör seçimi sonrası aynı operator ekranına dönüyor ve operatör adı anında görünüyor.
- Internal → outsourced transfer sonrası fason atama ekranına gidiyor.
- Outsourced → internal transfer sonrası operator ekranına gidiyor.
- Eski `?tab=islemler`, `?tab=ic-uretim`, `?tab=dis-uretim` URL'leri state'e göre canonical route'a gidiyor.
- Production detail yazma formu göstermiyor; yalnız summary, photo/history inceleme ve canonical CTA var.
- Production pool CTA'ları legacy tab URL'si üretmiyor.

## Sonuç

READY — PRODUCTION LEGACY ROUTE AND ACTION SURFACE CLEANUP — MANUAL SMOKE REQUIRED

## Addendum — 2026-07-21 M13-C4 Status Truth Consolidation
- Production pool/detail UI was consolidated in `docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md`.
- M13-C3 canonical route map was preserved; no M13-E2 mobile/public subcontract flow was started.
- Production detail now uses `Sıradaki İşlem`, compact status truth and no duplicate right sidebar summary.
