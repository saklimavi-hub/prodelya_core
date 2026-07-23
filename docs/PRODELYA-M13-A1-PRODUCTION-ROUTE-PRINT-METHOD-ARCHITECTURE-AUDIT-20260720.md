# PRODELYA M13-A1 — Üretim / Fason Route + Baskı Tekniği Mimari Audit

Tarih: 2026-07-20
Kapsam: Read-only architecture audit + implementation plan
Durum: READY

## 1. Stop-Line Uyumu

Bu turda uygulama kodu, Blade, CSS, JS, route, controller, servis, migration, DB mutation, staging veya commit yapılmadı. Yalnız mevcut production omurgası, tenant baskı ayarları, route/controller/service/model/view/test kanıtları ve M13-B uygulama sınırı raporlandı.

Worktree mevcut durumda çok kirli; bu rapor hiçbir mevcut hunkı stage etmez veya ayrıştırmaz.

## 2. Çalıştırılan Read-Only Kontroller

- `git status --short`
- `php artisan route:list --path=admin/productions`
- `php artisan route:list --path=admin/production`
- `php artisan route:list --name=subcontract`
- `rg -n "tenant_print_settings|standard_print_types|production_mode|default_subcontractor" database app tests -S`
- SAKLImavi tenant print-mode read-only tinker sorgusu
- Production model/controller/service/view/test dosya okumaları

## 3. Mevcut Route / Controller / Service / Model

### Canonical admin route

Mevcut üretim modülü tek route ailesiyle çalışıyor:

| Method | URI | Route name | Controller |
|---|---|---|---|
| GET | `/admin/productions` | `admin.productions.index` | `Admin\ProductionController@index` |
| GET | `/admin/productions/{production}` | `admin.productions.show` | `Admin\ProductionController@show` |
| PATCH | `/admin/productions/{production}/assignment` | `admin.productions.update-assignment` | `Admin\ProductionController@updateAssignment` |
| PATCH | `/admin/productions/{production}/status` | `admin.productions.update-status` | `Admin\ProductionController@updateStatus` |

`route:list --name=subcontract` sonucu: ayrı subcontract/fason route ailesi yok. Bu, "tek Üretim / Fason modülü" kararına uygundur.

### Ana servis/model omurgası

| Katman | Dosya | Rol |
|---|---|---|
| Production record | `app/Models/OrderItemPrintProduction.php` | Exact print-row üretim işi, type/status/quantity/QC/fason/current-account source |
| Exact print row | `app/Models/OrderItemPrint.php` | Order item altındaki exact baskı satırı; method/default/override bağları |
| Creation | `app/Services/ProductionCreationService.php` | Quote→order / work form sonrası production operation yaratır |
| Workflow | `app/Services/ProductionWorkflowService.php` | Assignment, start, partial, complete, cancel, activity, current-account sync |
| Presenter | `app/Services/ProductionDataBuilder.php` | Production snapshot + readiness + safe display fields |
| Readiness | `app/Services/ProductionReadinessResolver.php` | Graphic/procurement/setup start gate |
| Fason cari | `app/Services/SubcontractorProductionCurrentAccountSyncService.php` | Fason maliyeti için idempotent cari hareket |
| Tenant print settings | `app/Models/TenantPrintSetting.php` | Tenant bazlı production mode/default fason firma |
| Standard print type | `app/Models/StandardPrintType.php` | Baskı tekniği kod/ad/default production mode |

## 4. Exact Production Unit

Canonical üretim birimi:

```text
order
+ order item
+ exact order item print row
+ order_item_print_productions row
```

Kanıt:

- `order_item_print_productions.order_item_print_id` zorunlu ve tenant içinde unique: `oipp_tenant_print_unique`.
- `OrderItemPrint::production()` tek exact production kaydına bağlanıyor.
- `ProductionCreationService::createForOrderItemPrint()` her `OrderItemPrint` için en fazla bir production record yaratıyor.
- No-print sipariş testinde print ve production sayısı 0 kalıyor; product/order aggregate üretim truth'u değil.

Karar:

- M13-B üretim havuzu satırları `OrderItemPrintProduction` olmalı.
- Order/product aggregate yalnız grup başlığı veya summary için kullanılmalı.
- 1a / 1b / 1c gibi satırlar ayrı üretim işi olarak korunmalı.

## 5. Production Route Truth

### Mevcut alanlar

| Truth | Mevcut kaynak | Not |
|---|---|---|
| Gerçek üretim yolu | `order_item_print_productions.production_type` | Primary truth |
| Legacy / conversion-time override | `order_item_prints.production_type` | Creation sırasında normalize edilir |
| Fason firma override | `order_item_prints.subcontractor_company_id` | Creation sırasında production company'ye taşınır |
| Tenant default | `tenant_print_settings.production_mode` | Print method default route |
| Tenant default fason firma | `tenant_print_settings.default_subcontractor_company_id` | Outsourced default company |

Mevcut enumlar:

| Enum | Mevcut label | M13 route bucket |
|---|---|---|
| `internal` | İç Üretim | İç Baskı |
| `outsourced` | Dış Üretim / Fason | Dış Baskı / Fason |
| `external` | Dış Üretim / Fason | Audit gerekli; legacy/external ara durum |

`ProductionCreationService` şu sırayla karar veriyor:

1. Exact print-row explicit `production_type`.
2. Exact print-row `subcontractor_company_id`.
3. Tenant print setting `production_mode`.
4. Default subcontractor company.
5. Legacy text normalization.

Karar:

- M13-B route tabı production method adına göre değil, `OrderItemPrintProduction.production_type` üzerinden türetilmeli.
- `order_item_prints.production_type` yalnız creation/legacy fallback olarak kalmalı.
- Kullanıcı-facing `Dış Baskı / Fason` bucket'ı `outsourced` ve kontrollü şekilde `external` kayıtlarını kapsayabilir; ancak `external` için anlam netliği M13-B testinde kilitlenmeli.

## 6. Print Method Truth

| Truth | Kaynak | Öncelik |
|---|---|---|
| Standard method identity | `order_item_prints.standard_print_type_id` → `standard_print_types.id/code/name` | 1 |
| Tenant method display | `order_item_prints.tenant_print_setting_id` → `TenantPrintSetting::displayName()` | 2 |
| Legacy text | `order_item_prints.print_type` | Fallback |
| Snapshot display | `production_snapshot.print_type` | Presenter cache/display |

Kanıt:

- `OrderItemPrint::displayPrintType()` tenant setting display name'i tercih ediyor.
- `ProductionDataBuilder` snapshot içine `print_type`, `print_option`, `print_sequence`, `print_quantity` yazıyor.
- `TenantPrintSettingProductionModeIntegrationTest` print method default route ile exact override davranışını test ediyor.

Karar:

- M13-B grup başlıkları method adına göre görsel grup olabilir, fakat route kararı method adından türetilmemeli.
- Group key mümkünse `standard_print_type_id`; yoksa normalized `print_type` fallback.

## 7. Tenant Default Mapping

Prompt hedef kararı:

| Baskı tekniği | Hedef varsayılan |
|---|---|
| UV Baskı | İç Baskı |
| Lazer Baskı | İç Baskı |
| Tampon Baskı | Dış Baskı / Fason |
| Klişe / Sıcak Baskı | Dış Baskı / Fason |

Read-only canlı SAKLImavi DB sorgusu:

| Code | Display | DB mode | Default company |
|---|---|---|---|
| `UV_PRINT` | UV Baskı | `both` | null |
| `LASER_PRINT` | Lazer Baskı | `internal` | null |
| `PAD_PRINT` | Tampon Baskı | `both` | null |
| `HOT_STAMPING` | Sıcak Baskı | `outsourced` | null |

Sonuç:

- Sistem mimarisi hedef kararı destekliyor.
- Canlı SAKLImavi config prompttaki hedefle tam aynı değil: UV ve Tampon `both`.
- M13-B uygulaması hardcode yapmamalı; tenant print settings gerçekleri kullanmalı.
- Eğer SAKLImavi için prompttaki defaultlar işletme kararı olarak kesinleştiyse, bu sonraki uygulama/manuel admin ayarı fazında config gate olarak ayrıca kapatılmalı.

## 8. Order / Print-Row Override

Kanıt:

- `order_item_prints.production_type` fillable.
- `order_item_prints.subcontractor_company_id` fillable.
- `ProductionCreationService::normalizeLegacyProductionType()` exact print row üzerinde explicit iç/dış/fason kararı varsa tenant default'u ezebiliyor.
- `ProductionController@updateAssignment()` production record üzerinde type/company/operator/unit güncelleyebiliyor.

Karar:

- Quote/order conversion anında gelen exact print-row override korunmalı.
- Production detail assignment, oluşmuş production record üzerindeki son truth olmalı.
- M13-B listesi always current production record'dan okumalı; tenant default yalnız production record oluşurken veya boş/legacy kayıtta fallback olarak kullanılmalı.

## 9. Internal Workflow Audit

Mevcut internal workflow:

| Adım | Service / status | Alan |
|---|---|---|
| Atama / başlat | `assignInternal()` | `production_type=internal`, `production_status=ic_uretimde` |
| Hat / tezgah | `production_unit_name` | Serbest text |
| Operatör | `assigned_to` | `users.id` |
| Kısmi üretim | `markPartiallyCompleted()` | `completed_quantity`, `remaining_quantity` |
| Tamamla | `markCompleted()` | `production_status=tamamlandi`, `completed_at` |
| Sorun | `markIssue()` | `production_status=sorunlu`, `issue_note` |
| Fotoğraf | Work form production photos | Detail photo tab |

Eksik / dikkat:

- Makine/tezgah için canonical machine table yok; `production_unit_name` serbest text.
- Operatör ekranı için ayrı route yok; mevcut detail tabı ve query filter ile başlayabilir.
- Fire/scrap quantity ayrı canonical alan olarak yok; sorun notu ve tamamlanan/kalan üzerinden izleniyor.

M13-B için migration gerekmez. M13-C/D ileride makine modeli veya fire quantity isterse ayrıca kanıtlı migration önerilebilir.

## 10. External / Fason Workflow Audit

Mevcut fason workflow:

| Adım | Service / status | Alan |
|---|---|---|
| Fason atama | `assignExternal()` / `updateAssignment()` | `production_type=outsourced`, `production_company_id` |
| Fasona gönder | `markSentToSubcontractor()` | `production_status=fasona_gonderildi`, `sent_to_subcontractor_at` |
| Fasondan geldi | `markReturnedFromSubcontractor()` | `production_status=fasondan_geldi`, `returned_from_subcontractor_at` |
| Kısmi/tam miktar | `markPartiallyCompleted()` / `markCompleted()` | `completed_quantity`, `remaining_quantity` |
| QC | `markQcStarted/Passed/Failed()` | `qc_status`, `qc_started_at` |
| Fason maliyet | `subcontractor_cost`, `subcontractor_cost_currency` | Yalnız yetkili finance |
| Cari sync | `SubcontractorProductionCurrentAccountSyncService` | `source_type=order_item_print_production`, `source_id=production.id` |

Fason firma eligibility:

- `production_partner`
- `print_fason`

Karar:

- M13-B dış/fason listesi current-account/fiyat göstermemeli.
- Fason maliyet/cari sadece detail + yetkili kullanıcıda kalmalı.
- Fason atama kolaylığı M13-D'de mevcut `updateAssignment` ve eligible company query üstünden geliştirilmeli.

## 11. Supplier-Printed Workflow Audit

Prompt'taki `Tedarikçiden Baskılı` route için mevcut açık canonical enum bulunamadı.

Mevcut olası alanlar:

- `OrderItemPrintProduction::TYPE_EXTERNAL = external`
- `OrderItemPrintProduction::TYPE_OUTSOURCED = outsourced`
- `order_item_prints.subcontractor_company_id`
- Procurement/work-form relation

Bulgu:

- `ProductionCreationService` tenant setting üzerinden yalnız `internal` ve `outsourced` üretir.
- `external` çoğunlukla legacy normalization ara değeri gibi duruyor.
- `supplier_printed` adlı explicit enum/kolon/route yok.

Karar:

- M13-B'de `Tedarikçiden Baskılı` tabı placeholder/empty-safe olabilir, fakat var olmayan işi başka route'tan tahminle taşımamalı.
- Eğer bu route gerçek operasyon olacaksa M13-D/E öncesi canonical karar gerekir:
  - mevcut `external` bu anlam için mi ayrılacak,
  - yoksa dar bir route classification metadata alanı mı gerekecek?
- Bu audit tek başına supplier_printed için schema önermiyor; önce gerçek kayıt/işletme örneği istenmeli.

## 12. Quantity / Status / Archive

Canonical miktar alanları:

| Konu | Kaynak |
|---|---|
| Planlanan | `order_item_print_productions.planned_quantity` |
| Tamamlanan | `order_item_print_productions.completed_quantity` |
| Kalan | `order_item_print_productions.remaining_quantity` |
| Print quantity fallback | `order_item_prints.print_quantity` |
| Kısmi | completed > 0 and remaining > 0 |
| Terminal | `production_status=tamamlandi` veya `remaining_quantity <= 0` |
| Cancel | `production_status=iptal`, `cancelled_at` |
| Problem | `production_status=sorunlu` veya `qc_status=sorunlu` |

Mevcut statuslar:

- `uretim_bekliyor`
- `ic_uretimde`
- `kismi_basildi`
- `fasona_gonderildi`
- `fasondan_geldi`
- `kalite_kontrol`
- `tamamlandi`
- `sorunlu`
- `iptal`

Karar:

- Tamamlananlar için yeni archive kolonu gerekmez.
- Aktif liste terminal olmayan production record'lardan türemeli.
- `approved` grafik statüsü production-ready sayılmamalı; readiness resolver zaten final attachment + `production_ready` istiyor.

## 13. Readiness Rules

`ProductionReadinessResolver` mevcut canonical gate:

| Gate | Kural |
|---|---|
| Grafik | required ise `OrderItemPrintGraphic::STATUS_PRODUCTION_READY` + latest final attachment |
| Approved | Tek başına yeterli değil |
| Revision | Bloklar |
| Procurement | fully received / not required / customer received gibi ready statüler |
| Setup | Feature flag kapalıysa bloklamaz; açıksa pending/requested bloklar |

Test kanıtı:

- `ProductionReadinessPerPrintGraphicTest` per-print final graphic source kullanıldığını kilitliyor.
- `NoPrintOrderSkipsGraphicProductionTest` baskısız işlerin production listesine düşmediğini kilitliyor.

Karar:

- M13-B next action kararları readiness resolver ve production status üstünden verilmeli.
- Blade içinde yeni workflow hesabı yazılmamalı; presenter/helper kullanılmalı.

## 14. Current Production Index UI / Pagination

Mevcut `/admin/productions`:

- Pool tabs: ready/internal/outsourced/preparation/partial/completed/qc.
- Filterlar: q, print_type, type, procurement_status, graphic_status, limit.
- `ProductionController@index` tüm kayıtları relationlarla çekip memory filter uyguluyor.
- Limit whitelist: 25/50/100/250.
- Gerçek paginator yok.
- Grouping route→method şeklinde değil; tablo satırları tek düz liste.
- CSS inline `pp-*` sınıfları view içinde.

M13-B ihtiyacı:

- Route tabs: İç Baskı, Dış Baskı / Fason, Tedarikçiden Baskılı, Tamamlananlar, Tümü.
- Route tab içinde method group cards.
- Per page: 10/20/50 exact production jobs.
- Query string korunmalı.
- Page numbers ve Previous/Next lokalize olmalı.
- Aynı exact iş bölünemez; aynı method başlığı farklı sayfalarda tekrar edebilir.

## 15. Permissions / Visibility

Mevcut korumalar:

- Menü: `module_key=production`, `feature_key=production_queue`.
- Controller tenant scope: production `tenant_account_id` üzerinden.
- Assigned user tenant membership guard var.
- Eligible fason company tenant/role guard var.
- Finance visibility `canViewFinancialData()` ile ayrışıyor.
- `ProductionFinancePermissionTest` fason maliyeti/cari alanını yalnız yetkili kullanıcıya gösteriyor.
- `PublicWorkFormTrackingDataBuilder` fiyat/maliyet/cari/file_path/token gibi forbidden patternleri public data'dan temizliyor.

Karar:

- Üretim havuzu listesinde default olarak finance gösterilmemeli.
- Operatör ve mobil/fason takip yüzeyleri fiyat, maliyet, kar, cari, internal note, token veya physical path göstermemeli.

## 16. Work Form / Public Tracking

Mevcut work-form tracking:

- `PublicWorkFormTrackingController@show(token)`
- `PublicWorkFormTrackingDataBuilder`
- `production_snapshot.public_status_label`
- `prints[]` içinde print type, option, production type, quantity, note
- Secure public attachment route ile customer-visible files

Karar:

- M13-F iş formu uyumu mevcut `ProductionDataBuilder::buildWorkFormSnapshot()` ve `PublicWorkFormTrackingDataBuilder` üzerinden ilerlemeli.
- İş formu exact print row için production route + operator/unit veya fason firma gösterebilir.
- Finance/Product Data Hub teknik alanları mevcut forbidden pattern çizgisine uygun şekilde dışarıda kalmalı.

## 17. Audit Matrisi

| Konu | Mevcut canonical kaynak | Eksik | Planlanan kullanım |
|---|---|---|---|
| Exact baskı satırı | `order_item_prints.id`, `order_item_print_productions.order_item_print_id` | Yok | Her production row bu exact satırdır |
| Üretim yolu | `order_item_print_productions.production_type` | `supplier_printed` explicit değil | Route tab bu alandan türetilir |
| Baskı tekniği | `standard_print_type_id`, `tenant_print_setting_id`, `print_type` | Legacy satırda ID boş olabilir | Group key standard id, fallback print_type |
| İç operatör | `assigned_to` | Operatör ekranı ayrı değil | M13-C'de aynı truth |
| Tezgah / makine | `production_unit_name` | Canonical machine model yok | İlk faz serbest text/hat |
| Fason firma | `production_company_id` | Mobil assignment portal yok | M13-D/E aynı company truth |
| Planlanan miktar | `planned_quantity` | Yok | Row progress |
| Tamamlanan miktar | `completed_quantity` | Yok | Row progress / archive |
| Kısmi miktar | completed/remaining hesap | Ayrı received field yok | Derived kısmi durum |
| Fason gönderilen | `sent_to_subcontractor_at`, status | Gönderilen qty ayrı değil | Planned qty kabul |
| Fason gelen | `returned_from_subcontractor_at`, completed qty | Ayrı received qty yok | completed quantity |
| Kalan | `remaining_quantity` | Yok | Next action |
| Sorun / fire | `production_status=sorunlu`, `issue_note`, `qc_status=sorunlu` | Fire qty yok | Sorun bildir; fire future audit |
| QC | `qc_status`, `qc_started_at` | Yok | QC tab/filter |
| Tamamlanma | `production_status=tamamlandi`, `completed_at`, remaining <=0 | Yok | Archive türetme |
| İş formu | `work_form_id`, `workForm.production_snapshot` | Üretim yolu labelı sadeleştirilebilir | M13-F safe snapshot |

## 18. Required Migrations

M13-B için migration gerekmez.

Mevcut schema şunları taşıyor:

- exact production job
- production route/type
- print method relation/fallback
- operator
- production unit
- fason company
- planned/completed/remaining quantity
- QC
- issue/cancel/complete timestamps
- subcontractor cost/current-account source

Gelecekte ancak şu kanıtlarla migration düşünülebilir:

- `supplier_printed` gerçek ve ayrı lifecycle istiyorsa explicit route classification.
- Makine/tezgah kapasite planı gerekiyorsa machine/station table.
- Fire/scrap quantity finans/rapor için ayrı truth olacaksa quantity columns.
- Fason mobil kullanıcı/token assignment ayrı güvenlik modeli gerektiriyorsa mobile access table.

Blind schema önerilmez.

## 19. M13-B Implementation Boundary

İlk implementation fazı:

```text
M13-B — Üretim Havuzu
```

Önerilen dar dosya sınırı:

| Dosya | İş |
|---|---|
| `app/Http/Controllers/Admin/ProductionController.php` | Index query/filter/paginator ve route/method grouping presenter hazırlığı |
| `app/Services/ProductionDataBuilder.php` veya yeni dar presenter | Existing snapshot üstünden next-action/group DTO |
| `resources/views/admin/productions/index.blade.php` | UI v1 route tabs + method groups + exact rows |
| `public/css/prodelya-admin.css` | Yalnız `.pd-ui-v1-production*` scoped CSS |
| `tests/Feature/...` | Route/method grouping, no finance leak, pagination, readiness, default/override |

Dokunulmaması gerekenler:

- Graphic detail/public/mail/secure file route
- Procurement pricing / Product Hub
- Order detail/index UI
- Global CSS primitives
- Schema/migration
- Work form public tracking, M13-F'ye kadar
- Current-account algorithm

## 20. M13-B Test Plan

Zorunlu targeted testler:

- Internal/outsource route grouping production_type üzerinden yapılır.
- Print method grouping standard id/display name üzerinden yapılır; method adı hardcoded route belirlemez.
- Tenant default `production_mode` production creation sırasında uygulanır.
- Exact print-row explicit override tenant default'u ezer.
- `both` mode default internal davranışı korunur veya M13-B'de açık label ile gösterilir.
- Completed/cancelled terminal işler aktif listeden çıkar; completed tabda görünür.
- Approved graphic production-ready sayılmaz.
- No-print orders production listesine düşmez.
- Operator/limited user finance/fason cost/cari göremez.
- Per-page 10/20/50 query korunur.
- Method group pagination exact job'u bölmez.
- `supplier_printed` route gerçek enum yoksa empty-safe ve yanıltıcı data üretmez.

Broad regression önerisi:

- `ProductionReadinessPerPrintGraphicTest`
- `TenantPrintSettingProductionModeIntegrationTest`
- `NoPrintOrderSkipsGraphicProductionTest`
- `ProductionFinancePermissionTest`
- `AdminSmokeTest`

## 21. Karar Özeti

- Tek modül: `Operasyon > Üretim / Fason`.
- Sol menüde UV/Lazer/Tampon/Klişe/Fason alt modül patlaması yok.
- Birinci seviye üretim yolu: `internal`, `outsourced/external`, ileride kanıtlı `supplier_printed`.
- İkinci seviye baskı tekniği: `standard_print_type_id / tenant_print_setting / print_type`.
- Canonical unit exact `order_item_print_productions` row.
- Tenant default ve exact override mimarisi mevcut.
- SAKLImavi canlı tenant default config prompttaki hedefle kısmen driftli; hardcode yapılmayacak.
- Internal operator, fason atama, mobil takip ve work-form aynı production truth'u kullanmalı.
- M13-B için schema gerekmez; UI/query/presenter/pagination fazıdır.

READY — PRODUCTION ROUTE AND PRINT-METHOD ARCHITECTURE — IMPLEMENTATION PLAN APPROVED

---

## M13-B Implementation Addendum — 2026-07-20

Status: READY — PRODUCTION POOL ROUTE AND METHOD GROUPING UI V1 — MANUAL SMOKE REQUIRED

Implemented the first production pool phase on `/admin/productions` with exact `OrderItemPrintProduction` rows, DB pagination, route tabs, canonical method grouping, supplier-printed empty-safe behavior and scoped `.pd-ui-v1-production` CSS.

Route decisions use `order_item_print_productions.production_type` with a narrow null-route legacy fallback only. No print-method-name hardcoding was added. Historical production records were not rewritten.

SAKLImavi tenant print default alignment remains `TENANT DEFAULT ALIGNMENT PENDING`; it must be performed only through the existing tenant print-setting update path under the correct tenant context.

Test highlights:

- ProductionPoolRouteMethodGroupingTest: PASS
- ProductionUiTest: PASS
- ProductionReadinessPerPrintGraphicTest: PASS
- TenantPrintSettingProductionModeIntegrationTest: PASS
- NoPrintOrderSkipsGraphicProductionTest: PASS
- ProductionFinancePermissionTest: PASS
- OperationsFastActionUxTest: PASS
- Procurement broad: PASS
- Order broad: PASS
- AdminSmoke: PASS

Known broad drift outside this phase:

- Production broad stops at an order-detail label expectation (`İç Üretimde` vs current `Devam Ediyor`).
- Graphic broad stops at an existing graphic work-folder 404 smoke route.

No staging or commit was performed.

---

## M13-B1 Closure Addendum — 2026-07-21

Status: MANUAL PASS — PRODUCTION POOL ROUTE AND METHOD GROUPING UI V1

SAKLImavi tenant print defaults were aligned through the existing tenant-scoped print-setting update controller path:

- `UV_PRINT = internal`
- `LASER_PRINT = internal`
- `PAD_PRINT = outsourced`
- `HOT_STAMPING = outsourced`

No direct SQL, tinker mutation, schema change or historical production rewrite was performed. Tenant 2 production type counts stayed unchanged before/after alignment: `NULL=1`, `internal=21`, `outsourced=4`.

Default landing decision: keep `/admin/productions` on `Tümü` for now. Moving the default to `İç Baskı` is intentionally deferred because current compatibility tests still expect the combined default view.

Acceptance and targeted tests passed:

- `SaklimaviPrintDefaultsAlignmentTest`
- `TenantPrintSettingProductionModeIntegrationTest`
- `ProductionPoolRouteMethodGroupingTest`
- `ProductionReadinessPerPrintGraphicTest`
- `ProductionFinancePermissionTest`
- `ProductionUiTest`
- `AdminSmokeTest`

No staging or commit was performed.
