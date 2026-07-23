# Process Depth Order Detail Pilot Implementation Report

## 1. Preflight

- `git status --short`: staged alan boş, worktree kirli ve çok sayıda ilgisiz değişiklik mevcut.
- `git diff --cached --stat`: boş.
- `git log -10 --oneline`: gerekli checkpoint commitleri mevcut olarak doğrulandı.
- `php artisan migrate:status`: Process Depth migration `Ran`.
- Kritik kirli dosyalar nedeniyle `OrderController.php`, `public/css/prodelya-admin.css`, `routes/web.php` değiştirilmedi.

## 2. Mevcut Order Detail Veri Akışı

- Canonical summary kaynağı `app/Services/OrderShowSummaryService.php`.
- Aktif işlem / sıradaki iş hesabı `OrderListSummaryService` üzerinden korunuyor.
- Effective process depth çözümü `TenantProcessDepthResolver` ve `TenantProcessDepthPolicy` ile yapılıyor.
- Pilot bu fazda snapshot yazmıyor; görünüm tenant override → package default → system default zincirinden okunuyor.

## 3. Değişen Dosyalar

- `app/Services/OrderDetailProcessDepthPresenter.php`
- `app/Services/OrderShowSummaryService.php`
- `resources/views/admin/orders/show.blade.php`
- `tests/Feature/ProcessDepth/OrderDetailProcessDepthPilotTest.php`
- `tests/Feature/OrderDetailOperationalFlowUxTest.php`

## 4. Presentation Payload

- `overview.process_depth` bloğu eklendi.
- Payload:
  - `key`
  - `label`
  - `source`
  - `source_label`
  - `is_overridden`
  - `presentation.operation_card_density`
  - `presentation.density_class`
  - `presentation.show_extended_readiness_details`
  - `presentation.show_evidence_sections`
  - `presentation.show_quality_control_section`
  - `presentation.show_advanced_activity_timeline`
  - `presentation.show_batch_operation_controls`
  - `focus.current_label`
  - `focus.next_label`

## 5. Hızlı Akış Davranışı

- Kompakt operasyon kart görünümü kullanılıyor.
- Ana odak alanı sidebar içindeki `Şu an / Sıradaki işlem`.
- Geniş readiness detayları ve gelişmiş faaliyet akışı ana görünümden çıkarılıyor.
- Tekil operasyon CTA korunuyor.

## 6. Standart Akış Davranışı

- Standard görünüm mevcut sipariş detay davranışına yakın tutuldu.
- Operasyon kartları korunurken process depth badge ve odak paneli eklendi.
- Finans hattı operasyon hattından görsel olarak ayrıldı.

## 7. Kontrollü Akış Davranışı

- Readiness detayları görünür hale getirildi.
- Varsa kalite kontrol ve kanıt kapsülleri gösteriliyor.
- `Faaliyet Akışı` bölümü görünür.
- Workflow enforcement eklenmedi.

## 8. Aktif İşlem / Sıradaki İş Entegrasyonu

- Sidebar içindeki odak paneli canonical summary değerlerini kullanıyor.
- Blade içinde paralel aktif işlem hesabı kurulmadı.
- `Şu an`, `Sıradaki işlem`, `Engel`, `Kaynak` alanları aynı payload üzerinden sunuluyor.

## 9. Finans Hattı Ayrımı

- Operasyon hattı: Grafik → Tedarik → Üretim/Fason → Teslimat
- Finans hattı ayrı kart kabuğunda sunuluyor.
- Finans, zorunlu son operasyon adımı gibi gösterilmiyor.
- Yetkisiz kullanıcıda finans hattı görünmüyor.

## 10. Module / Feature ve Permission İzolasyonu

- Yeni module key, feature key veya permission eklenmedi.
- Yetkisiz operasyon kullanıcısında finans görünürlüğü açılmadı.
- Public/customer yüzey değiştirilmedi.

## 11. Sensitive Leak Doğrulaması

- Pilot testlerinde Türkçe bozulma desenleri kontrol edildi.
- Order detail UX testinde tracking token ve hassas alan sızıntısı sözleşmesi korunuyor.
- Raw process depth key yalnız `data-process-depth` attribute içinde tutuluyor; kullanıcı-facing etiketler Türkçe.

## 12. Workflow ve DB Mutation Olmadığı

- Workflow servislerine dokunulmadı.
- Yeni status veya workflow gate eklenmedi.
- Bu fazda GET isteği için mutation üreten bir kod eklenmedi.

## 13. Snapshot Eklenmediği

- `orders` veya `promotion_quotes` tablolarına snapshot alanı eklenmedi.
- Sipariş dönüşüm akışı değiştirilmedi.

## 14. Test Komutları ve Gerçek Sonuçlar

- `php artisan test --filter=OrderDetailProcessDepth --stop-on-failure`
  - PASS, `4` test, `41` assertion
- `php artisan test --filter=OrderDetailOperationalFlowUxTest --stop-on-failure`
  - PASS, `3` test, `37` assertion
- `php artisan test --filter=OrderShowTabbedLayoutTest --stop-on-failure`
  - PASS, `1` test, `15` assertion
- `php artisan test --filter=OrderCompletedDecisionSafetyTest --stop-on-failure`
  - PASS, `1` test, `3` assertion
- `php artisan test --filter=OrderRevision --stop-on-failure`
  - PASS, `41` test, `245` assertion
- `php artisan test --filter=RepeatOrder --stop-on-failure`
  - PASS, `10` test, `69` assertion
- `php artisan test --filter=TenantUserRolePermissionFlowTest --stop-on-failure`
  - PASS, `5` test, `17` assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - PASS, `59` test, `214` assertion
- `php artisan test --filter=ProcessDepth --stop-on-failure`
  - PASS, `32` test, `179` assertion

## 15. Broad ProcessDepth Attribution ve Recovery

- Önce broad filtre bilinçli olarak yeniden koşturuldu:
  - `php artisan test --filter=ProcessDepth --stop-on-failure --debug`
  - İlk broad failure `Tests\Feature\ProcessDepth\SuperAdminPackageProcessDepthSettingsTest::setUp` içinde görüldü.
  - Gerçek exception: `SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: tenant_accounts.panel_subdomain`
- Aynı broad run içinde daha sonra `packages.key` duplicate fixture semptomu da görüldü.
- Tekil aile testleri ayrı ayrı PASS verdiği için broad failure’ın yeni pilot ile sıralı etkileşim kaynaklı olup olmadığı ayrıca kanıtlandı.
- Kanıt için iki yönlü kombinasyon koşturuldu:
  - `OrderDetailProcessDepthPilotTest` -> `SuperAdminPackageProcessDepthSettingsTest`
    - FAIL, ilk pilot testinden sonra `panel_subdomain = demo` duplicate failure üretildi.
  - `SuperAdminPackageProcessDepthSettingsTest` -> `OrderDetailProcessDepthPilotTest`
    - FAIL, ikinci testin `setUp()` aşamasında fixture çözümlemesi bozuldu.
- Kök neden yeni pilot test fixture’ında order-dependent auto-seed davranışıydı:
  - `tests/Feature/ProcessDepth/OrderDetailProcessDepthPilotTest.php`
  - `protected bool $seed = true;` kaldırıldı.
  - Seed işlemi `setUp()` içinde explicit `$this->seed();` ile sabitlendi.
- Recovery sonrası yeniden koşturulan broad filtre sonucu:
  - `php artisan test --filter=ProcessDepth --stop-on-failure`
  - PASS, `32` test, `179` assertion

## 16. Pilot Dışı Mevcut Failure’lar

- `php artisan test --filter=Order --stop-on-failure`
  - FAIL
  - İlk failure:
    - `Tests\Feature\OrderPaymentCurrentAccountTransactionTest::test_order_payments_sync_into_current_account_transactions_safely_and_idempotently`
    - Beklenen `TL`, actual `TRY`
  - Failure `OrderDetailProcessDepthPresenter`, `OrderShowSummaryService` veya `resources/views/admin/orders/show.blade.php` stack/çıktısına bağlanmadı.
  - Sınıflandırma: `UNRELATED KNOWN FAILURE — NOT PART OF PILOT COMMIT`

## 17. Full Suite Sonucu

- `php artisan test` bu fazda çalıştırılmadı.

## 18. Manuel Smoke Durumu

- `MANUAL SMOKE PENDING`

## 19. Git Status

- Worktree kirli.
- Pilot kapsamı dışında çok sayıda mevcut değişiklik var.

## 20. Staged Alan

- Boş.

## 21. Commit Durumu

- Bu fazda staging yapılmadı.
- Bu fazda commit yapılmadı.

## 22. Sonraki Faz Önerisi

- Kullanıcı manuel browser smoke ile:
  - Hızlı Akış
  - Standart Akış
  - Kontrollü Akış
  görünüm farklarını aynı siparişte doğrulamalı.
- Sonraki faz ancak manuel smoke PASS sonrasında değerlendirilmelidir.

## 23. Konsol Özeti

- A) Preflight: PASS
- B) Core/settings UI commitleri doğrulandı mı: Evet
- C) Order detail canonical summary kaynağı: `OrderShowSummaryService`
- D) Yeni presentation service eklendi mi: Evet
- E) Hızlı Akış görünümü: Uygulandı
- F) Standart Akış görünümü: Uygulandı
- G) Kontrollü Akış görünümü: Uygulandı
- H) Aktif işlem / sıradaki iş: Canonical payload ile bağlandı
- I) Finans paralel hat olarak korundu mu: Evet
- J) Module/feature access değişti mi: Hayır
- K) Permission davranışı değişti mi: Hayır
- L) Workflow enforcement eklendi mi: Hayır
- M) Snapshot eklendi mi: Hayır
- N) GET DB mutation üretiyor mu: Bu fazda böyle bir ekleme yapılmadı
- O) Sensitive leak: Pilot kapsamındaki kontroller PASS
- P) OrderDetailProcessDepth test sonucu: PASS
- Q) ProcessDepth broad regresyonu: Önce pilot fixture etkisiyle kanıtlandı, ardından FIX edilip PASS hale getirildi
- R) Order detail regresyonları: PASS
- S) Order test ailesi: FAIL, pilot dışı `TL` / `TRY` currency mismatch
- T) Revision/repeat regresyonu: PASS
- U) AdminSmokeTest: PASS
- V) Full suite: Çalıştırılmadı
- W) Yeni failure: Pilot dosyalarına atfedilen yeni failure kalmadı
- X) Manuel smoke: PENDING
- Y) Değişen dosyalar: 5 pilot dosyası
- Z) Global CSS değişti mi: Hayır
- AA) Staging: Hayır
- AB) Commit: Hayır
- AC) Rapor yolu: `docs/PROCESS-DEPTH-ORDER-DETAIL-PILOT-IMPLEMENTATION-REPORT-20260712.md`
- AD) Sonraki faz: Manual smoke sonrası değerlendirme
- AE) Final karar: `IMPLEMENTED — BROAD PROCESSDEPTH RECOVERY PASS — MANUAL SMOKE PENDING`

## 24. D3 Real Visual Differentiation Fix

- Önce root cause kanıtlandı:
  - Resolver zinciri `TenantProcessDepthResolver -> TenantProcessDepthPolicy -> OrderDetailProcessDepthPresenter -> OrderShowSummaryService -> resources/views/admin/orders/show.blade.php` çalışıyor.
  - Aynı görünümün ana sebebi Blade’in capability payload’ını gerçek branching için yeterince kullanmamasıydı.
  - İkinci sebep `config/process_depth.php` içinde `standard` ve `controlled` presentation capability map’lerinin pratikte aynı görünmesi oldu.
- `PILOT_PRESENTATION_CAPABILITY_ADJUSTMENT` uygulandı:
  - `standard`: `show_extended_readiness_details=true`, `show_evidence_sections=false`, `show_quality_control_section=false`, `show_advanced_activity_timeline=false`, `show_batch_operation_controls=false`
  - `controlled`: detay/evidence/QC/activity görünürlüğü açık bırakıldı.
- `resources/views/admin/orders/show.blade.php` içinde gerçek branching eklendi:
  - `fast` -> `data-depth-branch="fast"`, `pd-order-depth-compact-marker`, kompakt step-chip yüzeyi, tek primary process CTA
  - `standard` -> `data-depth-branch="standard"`, `pd-order-depth-standard-marker`, dengeli özet + standart operasyon kartları
  - `controlled` -> `data-depth-branch="controlled"`, `data-controlled-details="true"`, `Kontrol Ayrıntıları`, `Faaliyet Özeti`, `Faaliyet Akışı`
- Empty-card problemi view-local `pd-order-depth-*` sınıfları ile düzeltildi:
  - `height:auto`
  - `min-height:0`
  - gereksiz stretch ve boş ayrıntı yüzeyleri kaldırıldı
  - global CSS dosyasına bu fix için dokunulmadı
- Canonical active/next action mapping Blade içinde yeniden hesaplanmadı:
  - `currentFocusLabel`
  - `nextFocusLabel`
  - `focusBlocker`
  mevcut summary payload üzerinden bağlandı.

## 25. D3 Runtime Payload ve DOM Sonucu

- `fast`
  - density: `compact`
  - DOM marker: `data-depth-branch="fast"`
  - compact focus + step-chip yüzeyi
- `standard`
  - density: `standard`
  - DOM marker: `data-depth-branch="standard"`
  - balanced summary + standart operasyon kartları
- `controlled`
  - density: `detailed`
  - DOM marker: `data-depth-branch="controlled"`
  - control details + activity summary + activity timeline
- Üç response body hash’i test ile birbirinden farklı doğrulandı.

## 26. D3 Test Sonuçları

- `php artisan test --filter=TenantProcessDepthPolicyTest --stop-on-failure`
  - PASS, `5` test, `16` assertion
- `php artisan test --filter=OrderDetailProcessDepth --stop-on-failure`
  - PASS, `6` test, `75` assertion
- `php artisan test --filter=ProcessDepth --stop-on-failure`
  - PASS, `34` test, `216` assertion
- `php artisan test --filter=OrderDetailOperationalFlowUxTest --stop-on-failure`
  - PASS, `3` test, `37` assertion
- `php artisan test --filter=OrderShowTabbedLayoutTest --stop-on-failure`
  - PASS, `1` test, `15` assertion
- `php artisan test --filter=OrderCompletedDecisionSafetyTest --stop-on-failure`
  - PASS, `1` test, `3` assertion
- `php artisan test --filter=OrderRevision --stop-on-failure`
  - PASS, `41` test, `245` assertion
- `php artisan test --filter=RepeatOrder --stop-on-failure`
  - PASS, `10` test, `69` assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - PASS, `59` test, `214` assertion

## 27. Cache Clear ve Gate

- `php artisan optimize:clear`
  - PASS
- Manual smoke durumu:
  - `MANUAL SMOKE PENDING`
- Bu fazda staging yapılmadı.
- Bu fazda commit yapılmadı.
- D3 ara karar:
  - `IMPLEMENTED — ORDER DETAIL DEPTH PRESENTATION VISUALLY DIFFERENTIATED — MANUAL SMOKE PENDING`
