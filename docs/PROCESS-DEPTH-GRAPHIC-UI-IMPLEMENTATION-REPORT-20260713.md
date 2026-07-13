# PROCESS DEPTH GRAPHIC UI IMPLEMENTATION REPORT — 2026-07-13

## 1. Preflight
- `git status --short`, `git diff --stat`, `git diff --cached --stat`, `git log -12 --oneline` çalıştırıldı.
- Preflight sırasında staged alan boş doğrulandı.
- `bbd3354`, `97ec0d5`, `21e1836`, `ff3627b`, `65d03cb`, `71094f0` dahil son checkpoint zinciri doğrulandı.
- İlgisiz worktree değişikliklerinin korunacağı teyit edildi.

## 2. Mevcut grafik veri akışı
- Canonical veri kaynağı `GraphicModuleDataBuilder::buildShow()` olarak korundu.
- Controller içinde yeni process depth çözümleme zinciri yazılmadı.
- Per-print 1a/1b/1c/1d operasyon modeli korunmaya devam etti.

## 3. Değişen dosyalar
- `app/Services/GraphicModuleDataBuilder.php`
- `resources/views/admin/graphics/show.blade.php`
- `tests/Feature/ProcessDepth/GraphicProcessDepthUiTest.php`

## 4. Presentation payload
- `TenantProcessDepthResolver` + `TenantProcessDepthPolicy` üzerinden presentation-only payload eklendi.
- Payload içinde branch class, history limit, compact/full detail, sidebar görünürlüğü ve primary action üretildi.
- Workflow mutation eklenmedi.

## 5. Fast
- Tek operasyon odak paneli eklendi.
- Operation tabs, step tabs ve full history gizlendi.
- Tek primary CTA disiplini korundu.
- Kısa durum ve kısa görsel/meta görünümü eklendi.

## 6. Standard
- Mevcut grafik ekranına en yakın dengeli görünüm korundu.
- Operation tabs ve action step tabs görünür kaldı.
- Short history ve operation status sidebar korunurken controlled-only readiness sidebar kapalı tutuldu.
- `İş Özeti` ifadesi görünür yüzeyde geri uyumluluk için korundu.

## 7. Controlled
- Visibility split attachment bloğu eklendi.
- Readiness / Onay Özeti sidebar kartı eklendi.
- Son Faaliyetler sidebar bloğu eklendi.
- Ayrıntılı history görünümü ve Türkçe activity labels korundu.

## 8. Per-print integrity
- Selected operation query davranışı korundu.
- Attachment, approval ve readiness bilgileri selected operation bazında kaldı.
- Per-print regresyon `GraphicPerPrintUiTest` ile PASS oldu.

## 9. Sticky panel
- Local layout `330px` sağ panel ve `14px` gap ile güncellendi.
- `data-sticky-layout` ve `data-sticky-sidebar` marker’ları eklendi.
- `1100px` altında stack davranışı korundu.

## 10. Spacing
- Local `pd-page-stack`
- Local `pd-section-stack`
- Local `pd-card-stack`
- Local `pd-two-column-layout`
- Local `pd-inline-stack`
- Local `pd-tight-stack`
- Global `.card` margin hack kullanılmadı.

## 11. Activity labels
- Controlled history ve sidebar görünümünde merkezi Türkçe label çözümlemesi kullanıldı.
- `GraphicShowHistoryTurkishTest` PASS oldu.

## 12. Permission / sensitive leak
- Grafik admin detail ekranında finans/fiyat alanları gösterilmedi.
- `file_path` / `physical_path` ham veri alanları render edilmedi.
- Public/customer katmanındaki token leak ayrı security hotfix kapsamında çözüldü.

## 13. Broad ProcessDepth isolation recovery
- İlk broad failure exact olarak `UNIQUE constraint failed: tenant_accounts.panel_subdomain` ve `UNIQUE constraint failed: packages.key` ile kanıtlandı.
- Exact kombinasyon kanıtı:
  - `GraphicProcessDepthUiTest + OrderDetailApprovedStickyPanelTest` FAIL
  - `OrderDetailApprovedStickyPanelTest + GraphicProcessDepthUiTest` FAIL
  - `GraphicProcessDepthUiTest + TenantProcessDepthResolverTest` PASS
- Root cause test katmanındaydı:
  - `GraphicProcessDepthUiTest` içinde `protected bool $seed = true` kullanılırken diğer ProcessDepth testlerinde explicit `$this->seed()` vardı.
  - `RefreshDatabase` in-memory sqlite state'i suite sırası boyunca yeniden kullanıldığı için seed tekrarları duplicate fixture üretiyordu.
- Uygulanan test-only düzeltme:
  - `GraphicProcessDepthUiTest` auto-seed yerine explicit `$this->seed()` kullanacak şekilde güncellendi.
  - `tearDown()` içinde `RefreshDatabaseState::$migrated = false` ve `RefreshDatabaseState::$inMemoryConnections = []` ile global test DB state temizlendi.
- Production unique constraint, workflow veya builder davranışı değiştirilmedi.

## 14. Public security attribution and hotfix
- Exact security failure ayrı scope olarak kanıtlandı: public graphic/public quote approval HTML içinde raw token action URL path segmenti olarak render ediliyordu.
- Attribution sonucu: bu leak grafik Process Depth UI kaynaklı değildi; public approval yüzeyinde önceden var olan gerçek security sözleşmesi ihlaliydi.
- Ayrı hotfix ile:
  - public quote ve public graphic approval show URL'lerine `POST /` respond route eklendi,
  - blade formlarında tokenlı action URL render'ı kaldırıldı,
  - karar türü `decision` hidden input ile taşındı,
  - legacy decision endpoint'leri backward compatibility için korundu,
  - route/view testleri no-raw-token contract ile hizalandı.

## 15. Final test matrix
- `php artisan test tests/Feature/ProcessDepth/GraphicProcessDepthUiTest.php tests/Feature/ProcessDepth/OrderDetailApprovedStickyPanelTest.php --stop-on-failure --debug` PASS
- `php artisan test tests/Feature/ProcessDepth/OrderDetailApprovedStickyPanelTest.php tests/Feature/ProcessDepth/GraphicProcessDepthUiTest.php --stop-on-failure --debug` PASS
- `php artisan test tests/Feature/ProcessDepth/GraphicProcessDepthUiTest.php tests/Feature/ProcessDepth/TenantProcessDepthResolverTest.php --stop-on-failure --debug` PASS
- `php artisan test --filter=ProcessDepth --stop-on-failure --debug` PASS (`46` test, `441` assertion)
- `php artisan test tests/Feature/PublicGraphicApprovalSecurityTest.php tests/Feature/PublicQuoteApprovalSecurityTest.php tests/Feature/PublicGraphicApprovalRouteTest.php tests/Feature/PublicQuoteApprovalRouteTest.php tests/Feature/PublicQuoteApprovalDecisionActionsTest.php tests/Feature/PublicApprovalAndTrackingSecuritySmokeTest.php --stop-on-failure` PASS (`14` test, `235` assertion)
- `php artisan test --filter=GraphicProcessDepthUi --stop-on-failure` PASS (`4` test, `79` assertion)
- `php artisan test --filter=GraphicModuleTest --stop-on-failure --debug` PASS (`5` test, `56` assertion)
- `php artisan test --filter=GraphicPerPrintUiTest --stop-on-failure` PASS (`1` test, `64` assertion)
- `php artisan test --filter=GraphicShowHistoryTurkishTest --stop-on-failure` PASS (`1` test, `9` assertion)
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` PASS (`59` test, `214` assertion)
- Not: bir ara `GraphicModuleTest` paralel koşu sırasında `storage/framework/testing/disks/public/work-forms/1/2` yolu eksikliğiyle FAIL verdi; aynı test tek başına hemen yeniden koşturuldu ve PASS oldu. Failure production regression olarak değerlendirilmedi.

## 16. Manual smoke
- Kullanıcı doğrulaması:
  - Graphic Process Depth Manual Smoke: PASS
  - Public Approval Security Manual Smoke: PASS
  - Raw graphic token in HTML: NONE
  - Raw quote token in HTML: NONE

## 17. Selective commits
- `e7991f4` `security: hide raw tokens from public approval forms`
- `eee9675` `process-depth: add graphic operation presentation modes`
- Docs commit bu rapor güncellemesiyle birlikte oluşturuldu.

## 18. Git status
- Worktree bilinçli olarak kirli kaldı; ilgisiz değişiklikler korunuyor.
- Seçici commit akışı dışında Product Data Hub, TL/TRY ödeme, global CSS, route/menu ve diagnostic artefaktlar commit kapsamına alınmadı.

## 19. Staging / commit
- Security ve graphic feature commitleri seçici patch staging ile alındı.
- Final hedef staged alanı docs commit sonrası boş bırakmaktır.

## 20. Next gate
- E1 kapsamı tamamlandı.
- Sonraki faz için karar: `VERIFIED — GRAPHIC PROCESS DEPTH UI AND PUBLIC APPROVAL SECURITY SELECTIVELY COMMITTED — PROCUREMENT UI GATE OPEN`
