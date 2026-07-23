# Process Depth Architecture Audit and Decision Matrix

Date: 2026-07-12
Scope: Read-only architecture audit for `PRODELYA_V1 10.16.5-A`
Change policy: No application code, migration, database mutation, staging, or commit

## 1. Mevcut Mimari Bulguları

Bu audit sırasında aşağıdaki ana yapılar incelendi:

- `app/Models/Package.php`
- `app/Models/PackageModule.php`
- `app/Models/PackageFeature.php`
- `app/Models/PackageLimit.php`
- `app/Models/TenantAccount.php`
- `app/Models/TenantModule.php`
- `app/Models/TenantSetting.php`
- `app/Services/TenantAccessService.php`
- `app/Services/TenantSubscriptionStatusService.php`
- `app/Services/TenantUsageService.php`
- `app/Services/PackageCatalogService.php`
- `app/Http/Controllers/SuperAdmin/PackageController.php`
- `app/Http/Controllers/SuperAdmin/TenantController.php`
- `resources/views/super-admin/packages/_form.blade.php`
- `resources/views/admin/settings/index.blade.php`
- `app/Models/Order.php`
- `app/Services/OrderQuoteDraftCloneService.php`
- `app/Services/OrderRevisionApplyService.php`
- `app/Services/OrderShowSummaryService.php`
- `app/Services/GraphicWorkflowService.php`
- `app/Services/ProcurementWorkflowService.php`
- `app/Services/ProductionWorkflowService.php`
- `app/Services/ProductionReadinessResolver.php`
- `app/Services/DeliveryWorkflowService.php`
- `app/Services/OrderPaymentService.php`
- `routes/web.php`

Temel bulgular:

| Alan | Bulgular |
| --- | --- |
| Package persistence | Paket erişimi `packages`, `package_modules`, `package_features`, `package_limits` omurgasıyla yönetiliyor. Package tarafında mevcut generic settings JSON veya process-depth benzeri canonical alan görünmüyor. |
| Tenant persistence | Tenant tarafında genel ayarlar için `tenant_settings` yoğun kullanılıyor. `tenant_accounts` üzerinde süreç derinliği benzeri bir kolon yok. |
| Override yapısı | `tenant_modules` erişim ve limit override semantiği taşıyor. Süreç derinliği için bu tabloyu kullanmak anlamsal olarak yanlış olur. |
| Access katmanı | `TenantAccessService` açıkça module/feature erişimini çözüyor. Subscription, package grants, tenant override ve bazı legacy ayarları birleştiriyor. |
| Process depth izi | Repo taramasında `process_depth` veya eşdeğer bir canonical alan bulunmadı. |
| Settings yüzeyleri | Tenant tarafında `resources/views/admin/settings/index.blade.php` merkezi ayar yüzeyi. Super Admin tarafında paket formu `resources/views/super-admin/packages/_form.blade.php`. |
| Order/quote çekirdeği | `Order` modeli quote ve order için ortak çekirdek. Conversion, revision ve repeat order zincirleri zaten mevcut. |
| Snapshot paterni | Currency tarafında `Order` üzerinde snapshot alanları var. Bu, ileride process depth snapshot yaklaşımının mimari olarak uyumlu olduğunu gösteriyor. |
| Operasyon akışları | Grafik, tedarik, üretim ve teslimat servisleri gerçek workflow statüleriyle çalışıyor; bunlar sadece UI gösterimi değil. |
| Finance | Finans ayrı servis ve görünürlük kurallarıyla ilerliyor; operasyon hattının zorunlu son kapısı olarak tasarlanmamış. |

## 2. Process Depth ile Module/Feature Farkı

Net ayrım şu şekilde korunmalıdır:

| Katman | Görev |
| --- | --- |
| Module/feature erişimi | Tenant hangi modüle veya özelliğe erişebilir sorusunu çözer. |
| Process depth | Erişilebilir modüllerin ne kadar kompakt, kontrollü ve detaylı kullanılacağını çözer. |

Kesin karar:

- Process depth lisans yerine geçmemelidir.
- Module/feature erişimini açmamalıdır.
- Permission bypass etmemelidir.
- Baskılı işlerde gerekli operasyonları ortadan kaldırmamalıdır.
- Baskısız işlerde sahte operasyon üretmemelidir.
- `tenant_modules` veya access resolver içine gömülmemelidir.

## 3. Önerilen Kullanıcı-Facing Seviye Adları

Önerilen adlar:

| Canonical | Kullanıcı-facing ad | Gerekçe |
| --- | --- | --- |
| `fast` | Hızlı Akış | Küçük ekip, az CTA, kompakt operasyon görünümü. |
| `standard` | Standart Akış | Mevcut sistem davranışına en yakın, güvenli varsayılan. |
| `controlled` | Kontrollü Akış | QC, evidence, ayrıntılı readiness ve onay ağırlıklı yapı. |

Gerekçe:

- `Hızlı / Standart / Kontrollü` adları operasyon davranışını `Temel / Standart / Gelişmiş`e göre daha iyi anlatıyor.
- Tenant kullanıcısı için davranış farkı anlaşılır.
- Mevcut operasyon terimleriyle uyumlu.

## 4. Canonical Key/Value Önerisi

Öneri:

- Canonical key: `process_depth`
- Canonical values: `fast`, `standard`, `controlled`

Neden:

- Mevcut repo naming stiliyle uyumlu.
- Hem package default hem tenant override için aynı canonical değerler kullanılabilir.
- UI label ile persistence value ayrımı temiz kalır.

## 5. Paket Varsayılanı Persistence Kararı

Karar: V1 implementation fazında package default için `packages` tablosunda doğrudan canonical bir alan tercih edilmelidir.

Gerekçe:

- Mevcut package mimarisinde generic settings JSON omurgası görünmüyor.
- `package_modules` ve `package_features` erişim ve enablement yapılarıdır; process depth için anlamsal olarak uygun değildir.
- Ayrı `process_depth_definitions` tablosu V1 için gereksiz karmaşıklık olur.
- Package default, package kavramının kendisine aittir; tenant settings içine taşınmamalıdır.

Bu audit fazında migration önerisi kavramsal düzeydedir; uygulanmamıştır.

## 6. Abone Firma Override Persistence Kararı

Karar: V1 implementation fazında tenant override `tenant_settings` içinde saklanmalıdır.

Öneri:

- Key: `process_depth`
- Type: `string`

Gerekçe:

- `TenantSetting::getValue/setValue` zaten tenant-scoped canonical ayar omurgası olarak yoğun kullanılıyor.
- Tenant settings landing ve ayar servisleri bu modelle uyumlu.
- `tenant_accounts` üzerinde yeni kolon yerine V1 için daha düşük riskli ve repo standardına daha yakın çözüm olur.
- User-level override V1 için açılmamalıdır.

## 7. Effective Resolution Sırası

Önerilen sıra:

1. Tenant override
2. Package default
3. System default

System default:

- `standard`

Legacy/invalid davranışı:

- Geçersiz değer güvenli biçimde `standard`a normalize edilmeli.
- Hata sayfası yerine log/fallback tercih edilmeli.

## 8. Shared Resolver Sözleşmesi

Önerilen servis:

- `App\Services\ProcessDepth\TenantProcessDepthResolver`

Sorumluluklar:

- Tenant override değerini çözmek
- Package default değerini çözmek
- `standard` fallback uygulamak
- Invalid/legacy değeri normalize etmek
- Label döndürmek
- Kaynağı belirtmek

Önerilen çıktı sözleşmesi:

```php
[
    'key' => 'standard',
    'label' => 'Standart Akış',
    'source' => 'package_default',
    'is_overridden' => false,
]
```

Array kararı gerekçesi:

- Repo içinde settings ve summary servisleri çoğunlukla array tabanlı sonuç üretiyor.
- V1 için düşük sürtünmeli ve test etmesi kolay.
- İleride value object veya enum destekli DTO’ya geçiş mümkündür.

Sınırlar:

- Blade içinde yeniden hesaplanmamalı.
- Controller bazında farklı sonuç üretmemeli.
- `TenantAccessService` içine gömülmemeli.
- Tenant scope dışına taşmamalı.

## 9. Shared Policy/Matrix Sözleşmesi

Tek resolver yeterli değildir. Davranış matrisi için ikinci bir katman gerekir.

Öneri:

- `App\Services\ProcessDepth\TenantProcessDepthPolicy`

V1 tasarım kararı:

- Config/code tabanlı capability map
- Sınırlı ve test edilebilir method yüzeyi
- Database-driven workflow builder değil

Önerilen yaklaşım:

- İçeride capability map
- Dışarıya sınırlı sayıda anlamlı method

Örnek methodlar:

- `usesCompactOperationCards()`
- `showsDetailedGraphicWorkflow()`
- `requiresCustomerGraphicApproval()`
- `showsProcurementBatchActions()`
- `requiresProductionQualityControl()`
- `requiresProductionEvidence()`
- `showsDeliveryEvidenceFields()`
- `showsAdvancedActivityTimeline()`

Bu tercih neden doğru:

- Sadece dağınık boolean method seti olursa davranış matrisi zor yönetilir.
- Tamamen DB-driven yapı V1 için aşırı esnek ve riskli olur.
- Capability map + convenience method hibriti en dengeli çözümdür.

## 10. Süreç Bazlı Etki Matrisi

| Alan | Hızlı Akış | Standart Akış | Kontrollü Akış |
| --- | --- | --- | --- |
| Teklif oluşturma | Sade gösterim | İhtiyaca göre | İhtiyaca göre |
| Teklif onayı | Görünür ama isteğe bağlı | İhtiyaca göre | Görünür ama isteğe bağlı |
| Siparişe dönüşüm | Sade gösterim | İhtiyaca göre | İhtiyaca göre |
| Grafik operasyonu | İhtiyaca göre | İhtiyaca göre | İhtiyaca göre |
| Müşteri grafik onayı | Görünür ama isteğe bağlı | İhtiyaca göre | Zorunlu |
| Tedarik | İhtiyaca göre | İhtiyaca göre | İhtiyaca göre |
| Tedarikçi talep formu | Gizli | Görünür ama isteğe bağlı | İhtiyaca göre |
| Kısmi geliş | Görünür ama isteğe bağlı | İhtiyaca göre | Zorunlu |
| Üretim/fason | İhtiyaca göre | İhtiyaca göre | İhtiyaca göre |
| Üretim readiness | Sade gösterim | İhtiyaca göre | Zorunlu |
| Kalite kontrol | Görünür ama isteğe bağlı | Görünür ama isteğe bağlı | Zorunlu |
| Üretim fotoğrafı | Gizli | Görünür ama isteğe bağlı | Zorunlu |
| Teslimat | İhtiyaca göre | İhtiyaca göre | İhtiyaca göre |
| Kısmi teslimat | Görünür ama isteğe bağlı | İhtiyaca göre | Zorunlu |
| Teslimat fotoğrafı/belgesi | Gizli | Görünür ama isteğe bağlı | Zorunlu |
| Müşteri bildirimi | Görünür ama isteğe bağlı | İhtiyaca göre | İhtiyaca göre |
| Activity log | Sade gösterim | İhtiyaca göre | Zorunlu |
| İş Formu | Sade gösterim | İhtiyaca göre | Zorunlu |
| Finans görünürlüğü | Permission’a bağlı | Permission’a bağlı | Permission’a bağlı |
| Raporlama | Sade gösterim | İhtiyaca göre | İhtiyaca göre |
| Müşteri portalı | Modül erişimine bağlı | Modül erişimine bağlı | Modül erişimine bağlı |

Notlar:

- `İhtiyaca göre` ifadesi gerçek iş ihtiyacına bağlı zorunluluğu anlatır; process depth tek başına operasyon üretmez.
- Grafik, tedarik, üretim ve teslimat modülleri disabled ise depth bunları açamaz.
- Finans hiçbir seviyede permission katmanını aşamaz.

## 11. Gerçek İş İhtiyacı Override Kuralları

Audit sonucu kesinleşen güvenli kurallar:

1. Baskılı sipariş kalemi varsa gerekli grafik ve üretim operasyonu devam etmelidir.
2. Baskısız kalemde sahte grafik veya üretim operasyonu oluşturulmamalıdır.
3. Tedarik ihtiyacı gerçek stok/tedarik kararından doğmalıdır; depth sahte procurement kaydı üretmemelidir.
4. İç üretim, dış üretim ve fason kararı gerçek operasyon kararı olarak kalmalıdır.
5. Müşteri linki ve public approval core zorunlu olmamalıdır.
6. Tenant kullanıcısı müşteri linki olmadan manuel teklif onayı yapabilmelidir.
7. Finans ve tahsilat teslimatı zorunlu olarak bloke eden son kapı olmamalıdır.
8. Permission ve tenant isolation hiçbir seviyede gevşememelidir.
9. Customer/public yüzeylerde maliyet, kâr, tedarikçi maliyeti, fason maliyeti, raw hub verisi, token ve file path gösterilmemelidir.
10. Process depth mevcut workflow kayıtlarını silmemeli veya geçmiş davranışı yeniden yazmamalıdır.

## 12. Mevcut Kayıtlar İçin Snapshot Kararı

Karar: Seçenek C, yani hibrit model önerilmelidir.

Önerilen davranış:

- Yeni teklif ve siparişlerde effective process depth snapshot alınır.
- Workflow zorunlulukları ve enforcement snapshot üzerinden okunur.
- Salt UI yoğunluğu ve kart kompaktlığı güncel tenant değerinden gelebilir.
- Mevcut açık kayıtlar için legacy fallback `standard` kabul edilir.

Gerekçe:

- `Order` modelinde currency snapshot paterni zaten var.
- Quote/order conversion, repeat order ve revision zinciri mevcut; süreç ortasında davranış değişimi riskli.
- Açık siparişin ekranını veya gerekliliklerini tenant ayarı değişti diye anlık değiştirmek operasyonel risk üretir.

Bu fazda snapshot alanı eklenmemiştir; yalnız karar verilmiştir.

## 13. İlk Pilot Alan

İlk pilot önerisi:

- Sipariş detay + aktif işlem / sıradaki iş görünümü

Gerekçe:

- `OrderShowSummaryService` zaten operasyon kartlarını merkezi biçimde oluşturuyor.
- Grafik, tedarik, üretim, teslimat ve finans özetleri bu yüzeyde birlikte görünüyor.
- Veri mutation riski düşüktür.
- Üç seviye arasındaki kompaktlık ve yönlendirme farkı burada kolay ölçülür.
- Ağır workflow enforcement eklemeden önce UI/summary düzeyinde güvenli pilot yapılabilir.

## 14. UI ve Yönetim Yüzeyleri Kararı

### Super Admin paket yönetimi

- Paket formunda modüllerden ayrı bir `Süreç Derinliği` alanı olmalı.
- Kısa açıklama eklenmeli: bu alan lisans değil, çalışma şekli varsayılanıdır.
- `resources/views/super-admin/packages/_form.blade.php` uygun yüzeydir.

### Abone Firma ayarları

- `Kurulum Merkezi` içinde ayrı bir `Süreç Derinliği` kartı olmalı.
- Effective değer ve kaynak görünmeli.
- Kaynak metinleri:
  - `Paket varsayılanı`
  - `Abone Firma tercihi`
  - `Sistem varsayılanı`
- Değişikliğin yeni ve mevcut kayıtlara etkisi açıklanmalı.

### Sipariş ve operasyon ekranları

- Sayfa başına dropdown gösterilmemeli.
- Ekranlar effective sonucu kullanmalı.
- Zorunlu davranış sadece CSS ile gizlenmemeli; backend policy ile korunmalı.

## 15. Uygulama Fazları

| Faz | Karar | Gerekçe |
| --- | --- | --- |
| 10.16.5-A Mimari Audit ve Karar Matrisi | GO | Bu rapor ile kapsam netleşti. |
| 10.16.5-B Process Depth Core | GO | Canonical model, resolver ve policy için mimari yeterince net. |
| 10.16.5-C Ayarlar UI | GO | Package default ve tenant override yüzeyleri doğal olarak mevcut ayar ekranlarına oturuyor. |
| 10.16.5-D Sipariş Detay Pilot | GO | En düşük riskli ve en ölçülebilir başlangıç alanı. |
| 10.16.5-E Grafik Entegrasyonu | Şartlı GO | Pilot sonrası capability etkileri doğrulanmalı. |
| 10.16.5-F Tedarik Entegrasyonu | Şartlı GO | Partial receipt ve supplier request davranışları dikkatli ele alınmalı. |
| 10.16.5-G Üretim/Fason Entegrasyonu | Şartlı GO | Readiness, setup ve QC kuralları snapshot ile korunmalı. |
| 10.16.5-H Teslimat Entegrasyonu | Şartlı GO | Partial delivery ve evidence zorunlulukları dikkatli açılmalı. |
| 10.16.5-I Enforcement ve Snapshot | NO-GO şimdilik | Pilot ve önceki fazlar doğrulanmadan geniş enforcement açılmamalı. |

## 16. Riskler ve Rollback Planı

Ana riskler:

- Module/feature lisansı ile process depth’in semantik olarak karışması
- Açık siparişlerin süreç ortasında davranış değiştirmesi
- Workflow zorunluluklarının sadece UI gizleme ile uygulanmaya çalışılması
- Finance’ın yanlışlıkla zorunlu terminal gate haline gelmesi
- Product Data Hub veya currency ile gereksiz coupling
- Tüm operasyon modüllerine tek fazda müdahale edilmesi

Rollback yaklaşımı:

- İlk pilot mutation-free veya minimum mutation olmalı.
- Enforcement en son faza bırakılmalı.
- Snapshot rollout’u pilot sonrasında açılmalı.
- Invalid values her zaman `standard` fallback ile güvenceye alınmalı.
- Package/tenant resolver mantığı feature flag benzeri kapatılabilir çekirdekte tutulmalı.

## 17. Dokunulmaması Gereken Tamamlanmış Alanlar

- Currency settings save/refresh doğrulama checkpoint’i
- Mevcut package/module/feature erişim sözleşmesi
- Permission ve tenant isolation kuralları
- Repeat order ve revision zinciri
- Public/customer approval güvenlik sınırları
- Product Data Hub davranışı
- Finans görünürlük izinleri
- Tamamlanmış operasyon kayıtlarının tarihsel bütünlüğü

## 18. Test Matrisi

### Resolver testleri

- Tenant override doğru çözülüyor mu
- Package default doğru çözülüyor mu
- System default `standard` fallback çalışıyor mu
- Invalid value güvenli normalize ediliyor mu
- Tenant isolation korunuyor mu
- Inactive package/tenant senaryosu güvenli davranıyor mu

### Policy testleri

- `fast` capability map doğru mu
- `standard` capability map doğru mu
- `controlled` capability map doğru mu
- Module disabled ise depth modülü açmıyor mu
- Permission hiçbir seviyede bypass olmuyor mu

### UI testleri

- Super Admin paket selection görünümü
- Tenant setting selection görünümü
- Effective source metni
- Türkçe terminoloji tutarlılığı
- Duplicate menü veya ayar linki oluşmaması
- Compact vs detailed surface farkı

### Workflow regression testleri

- Baskılı item için grafik ve üretim kaydı korunuyor mu
- Baskısız item için sahte operasyon oluşmuyor mu
- Manuel quote approval korunuyor mu
- Finance mandatory gate olmuyor mu
- Sensitive data public/customer yüzeye sızmıyor mu
- Revision ve repeat order zinciri bozulmuyor mu
- Procurement partial receipt akışı korunuyor mu
- Production readiness ve QC akışı bozulmuyor mu
- Delivery partial/full davranışı korunuyor mu

## 19. Son Karar

Sonuç:

- Process depth için ayrı ama erişim katmanından bağımsız bir canonical ayar omurgası kurulmalıdır.
- Package default ve tenant override ayrımı nettir.
- Resolver + policy şeklinde iki katmanlı tasarım tercih edilmelidir.
- İlk uygulama alanı sipariş detay pilotu olmalıdır.
- Geniş enforcement ve snapshot rollout’u pilot sonrası açılmalıdır.

GO / NO-GO:

- 10.16.5-A: GO
- 10.16.5-B: GO
- 10.16.5-C: GO
- 10.16.5-D: GO
- 10.16.5-E ve sonrası: Şartlı GO
- 10.16.5-I geniş enforcement: Şimdilik NO-GO
