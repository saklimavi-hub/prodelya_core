# Worktree / Checkpoint Stabilization Prep Raporu — 2026-07-10

## 1. Faz Özeti

- Bu faz read-only worktree audit, hunk sınıflandırması ve güvenli checkpoint planı için yürütüldü.
- Uygulama kodu değiştirilmedi.
- Var olan değişiklikler geri alınmadı.
- Dosya silinmedi, taşınmadı, rename edilmedi.
- `git add`, `git commit`, `git reset`, `git restore`, `git checkout`, `git clean`, `git stash` çalıştırılmadı.
- Staged alan audit başında boştu ve audit sonunda da boş kaldı.
- Audit sırasında oluşan tek yeni dosya bu rapordur.

## 2. Ground Truth Özeti

### 2.1 Git durumu

- Branch: `feature/master-restructure-phase-2-order-flow`
- HEAD: `0069275` `docs: finalize Prodelya master implementation roadmap audit`
- `git diff --cached --stat`: boş
- `git diff --cached --name-status`: boş
- `git diff --stat`: 8 modified dosya, büyük churn ağırlığı özellikle `public/css/prodelya-admin.css`
- `git status --short`: 8 modified, 22 untracked öğe tespit edildi

### 2.2 Referans checkpoint doğrulaması

- Master audit checkpoint doğrulandı:
  - `00692759d2357ebbf62b8ffe38e86209a25ab434`
  - `docs: finalize Prodelya master implementation roadmap audit`
- Tamamlanmış alanlar git log üzerinden doğrulandı:
  - `bf053ca` `quotes: refine quote list views and filters`
  - `fd141db` `orders: refine order list views and completed filters`
  - `523743e` `orders: add revision and repeat order metadata`
  - `7e4689d` `orders: add repeat order draft cloning`
  - `ba873d3` `quotes: add revision compare and apply flow`
  - `21753a6` `quotes: wire quote send channel controller actions`
  - `bfb4382` `quotes: refine quote detail unified decision surface`
  - `dfbce43` `public-graphics: use named routes in approval actions`
  - `d03461e` `docs: add public graphic approval cleanup report`
  - `8bdac82` `ui: add order detail operation center styles`
  - `0fd49be` `docs: add order detail css report`
  - `f0e9910` `routes: add admin catalog search route`
  - `8e5f558` `menu: polish product hub and finance menu labels`

### 2.3 Ana bulgular

- `OrderController.php` ve `Order.php` worktree diff'i `-w` ile sıfırlanıyor; açık davranış değişikliği yok, satır sonu/encoding kalıntısı gibi davranıyor.
- `config/admin_menu.php` ve `routes/web.php` worktree diff'i yalnız BOM + trailing blank line temizliği seviyesinde.
- `PromotionQuoteController.php` worktree diff'inde tek anlamlı değişiklik Product Hub warning metinlerinin mojibake temizliği ve copy düzeltmesi.
- Modified iki test dosyası, production HEAD içinde zaten var olan liste başlıklarıyla hizalanıyor.
- `public/css/prodelya-admin.css` dosya bazında commit edilemez; içinde hem shared primitive genişlemesi hem quote detail/send modal artık hunkları karışık duruyor.
- `.tmp_*` blade dosyaları geçici çalışma kopyaları; biri bozuk/stale, diğeri gerçek worktree yedeği niteliğinde.
- `docs/ui-previews/` altında fiziksel sayım `146` HTML dosya verdi; master audit'teki `145 benzersiz preview` kararıyla birlikte yorumlanmalı.

## 3. Master Audit Dosya Adı Normalizasyon Kontrolü

### 3.1 Sonuç

- Git index içinde bulunan canonical yol:
  - `docs/PRODELYA-MASTER-UYGULAMA-YOL-HARİTASI-AUDIT-RAPORU-20260710.md`
- `00692759` commit'i bu Türkçe `İ` içeren yolu içeriyor.
- ASCII `I` ile yazılan alternatif yol (`...HARITASI...`) worktree'de yok.
- İki ayrı dosya oluşmamış.
- Ayrı untracked kopya görülmedi.

### 3.2 Kanıt

- Worktree dosya varlığı:
  - `TURKISH_EXISTS=True`
  - `ASCII_EXISTS=False`
- Karakter kodu:
  - kritik karakter `İ` için kod `304`
- `git ls-tree -r --name-only 00692759 -- docs` çıktısı da aynı Türkçe `İ` içeren yolu gösteriyor.

## 4. WORKTREE MASTER ENVANTERİ

| Dosya | Durum | Hunk/İçerik | Sınıf | İlgili Faz | Öneri |
| --- | --- | --- | --- | --- | --- |
| `app/Http/Controllers/Admin/OrderController.php` | Modified | `-w` diff boş; satır sonu/encoding churn | `A` | revision/repeat order + order list geçmişi | Dosya bazlı commit yapılmamalı; şimdilik beklet |
| `app/Http/Controllers/Admin/PromotionQuoteController.php` | Modified | Product Hub warning metni mojibake temizliği + copy düzeltmesi | `B` | Product Hub live info / quote product search | Ayrı dar cleanup prep olarak ayrılmalı |
| `app/Models/Order.php` | Modified | `-w` diff boş; satır sonu/encoding churn | `A` | revision/repeat order metadata | Dosya bazlı commit yapılmamalı; şimdilik beklet |
| `config/admin_menu.php` | Modified | BOM kaldırma + son boş satır temizliği | `B` | menu cleanup | Ayrı küçük cleanup checkpoint adayı |
| `public/css/prodelya-admin.css` | Modified | shared primitive + quote detail button/style artık hunkları | `C` + `G` hunk bazlı | CSS stabilization | Mutlaka patch/hunk staging prep gerekir |
| `routes/web.php` | Modified | BOM kaldırma + son boş satır temizliği | `B` | route cleanup | Ayrı küçük cleanup checkpoint adayı |
| `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php` | Modified | başlık label hizalaması | `B` | quote/order list regression | Quote/order list tests-only hardening grubuna alınabilir |
| `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php` | Modified | quote list label hizalaması | `B` | quote/order list regression | Quote/order list tests-only hardening grubuna alınabilir |
| `.tmp_quote_detail_commit_target.blade.php` | Untracked | stale/bozuk commit target kopyası | `D` | quote detail staging artığı | Safe-delete adayı, ayrı prep gerekli |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | Untracked | gerçek worktree yedeği | `D` | quote detail staging artığı | Manual review olmadan silinmemeli |
| `docs/*.md` untracked raporlar | Untracked | kanıt, prep, rollback, plan | `E` | historical docs | Grup bazlı docs kararı gerekli |
| `docs/ui-previews/` | Untracked | 146 HTML fikir arşivi | `E` | preview archive | Ayrı sanitize + manifest kararı olmadan commitlenmemeli |
| `tests/Feature/QuoteOrderList*.php` | Untracked | mevcut HEAD davranışını kilitleyen testler | `B` | quote/order list hardening | Tek tests-only checkpoint adayı |

## 5. MODIFIED HUNK MATRİSİ

| Dosya | Hunk | Davranış Etkisi | Test | Checkpoint | Staging Yöntemi |
| --- | --- | --- | --- | --- | --- |
| `OrderController.php` | revision/repeat draft action blokları ve list index alanlarında yalnız line-ending churn | Hayır | mevcut list/order testleri var, yeni diff üretmiyor | `7e4689d`, `fd141db`, `b07daed` | Stage etme; önce normalize/ignore kararı |
| `PromotionQuoteController.php` | `buildWarningPayload()` içindeki `Kategori eşleşmemiş / Kategori uyarısı / Genel kategori...` metni | Evet, dar UI copy etkisi | `ProductHubLiveProductInfoEndpointTest`, `PromotionQuoteLiveProductInfoUiTest` dolaylı karşılık | `e2ad705` ile ilişkili, ama bu diff commitlenmemiş dar cleanup | Patch staging uygun |
| `Order.php` | copy metadata/helper/relation bloklarında yalnız line-ending churn | Hayır | revision/repeat order testleri HEAD'de mevcut | `523743e`, `ba873d3` | Stage etme |
| `config/admin_menu.php` | BOM + trailing blank line | Hayır | `AdminMenuServiceTest`, `AdminMenuVisibilityTest`, `TenantProductCatalogMenuSimplificationTest`, `FinanceMenuAuthorizationConsistencyTest` | `8e5f558` tamamlanmış; açık diff davranışsız | Dosya bazlı staging güvenli |
| `routes/web.php` | BOM + trailing blank line | Hayır | route smoke ve ilgili feature testleri dolaylı | `f0e9910`, `ba873d3`, `7e4689d`, `dfbce43` zaten HEAD'de | Dosya bazlı staging güvenli |
| `PromotionQuoteAndOrderIndexHeaderPanelTest.php` | `Açık Siparişler -> Aktif Siparişler`, `Müşteri Onayı Bekleyenler -> Açık Teklifler`, `Siparişe Çevrilebilir -> Siparişe Dönüşenler` | Hayır, mevcut UI beklentisini düzeltir | kendisi | `bf053ca`, `fd141db` sonrası mevcut HEAD UI | Dosya bazlı staging güvenli |
| `PromotionQuoteAndOrderIndexUxTest.php` | `Hazırlanan Teklifler -> Açık Teklifler` | Hayır, mevcut UI beklentisini düzeltir | kendisi | `bf053ca` sonrası mevcut HEAD UI | Dosya bazlı staging güvenli |
| `prodelya-admin.css` hunk 1 | `:root`, `.pd-btn--*`, `.pd-chip--*`, `.pd-tabs`, `.pd-form`, `.pd-modal`, `.pd-summary__line` | Evet, shared/global primitive etkisi var | manuel smoke + ilgili feature UI testleri gerekir | ayrı checkpoint yok | Patch/hunk staging zorunlu |
| `prodelya-admin.css` hunk 2 | `.promotion-quote-detail.quote-detail-compact ... .pd-btn` ve alt buton yerleşimleri | Evet, quote detail/send modal UI etkisi | quote detail/send modal test ailesi gerekir | `889f661`, `dce2098` ile yakın | Patch/hunk staging zorunlu |

## 6. Modified Dosya İncelemesi

### 6.1 `app/Http/Controllers/Admin/OrderController.php`

- HEAD'e göre değişiklik özeti:
  - `git diff -w` boş.
  - Görünen raw diff yalnız line-ending/encoding dokunuşu.
- Değişen method bölümleri:
  - `createRevisionDraft()`
  - `createRepeatOrderDraft()`
  - `createCopiedQuoteDraft()`
- Kullanıcı davranışı değiştiriyor mu:
  - Hayır; mevcut worktree diff'i davranış üretmiyor.
- Tenant isolation etkisi:
  - Açık diff'te yok.
- Permission etkisi:
  - Açık diff'te yok.
- Finans/maliyet görünürlüğü:
  - Açık diff'te yok.
- İlişkili faz:
  - revision/repeat order çekirdeği ve order detail/list geçmişi.
- İlgili checkpoint:
  - `7e4689d`, `523743e`, `b07daed`, `fd141db`.
- Test karşılığı:
  - revision/repeat order testleri ve order index testleri HEAD'de var.
- Dosya bazlı staging güvenli mi:
  - Hayır; şu an stage edilirse yalnız churn commitlenmiş olur.
- Patch/hunk staging gerekir mi:
  - Hayır; şu an meaningful hunk yok.
- Önerilen sınıf:
  - `A — Tamamlanmış checkpoint kalıntısı`
- Önerilen ayrı commit grubu:
  - tek başına commit önerilmez.
- Şimdi commitlenmeli mi:
  - Hayır.
- Rollback yaklaşımı:
  - Gerekirse ileride yalnız line-ending normalize veya restore prep fazında ele alınmalı.

### 6.2 `app/Http/Controllers/Admin/PromotionQuoteController.php`

- HEAD'e göre değişiklik özeti:
  - `git diff -w` sonrası anlamlı tek hunk `buildWarningPayload()` içinde kalıyor.
  - Mojibake düzeliyor ve warning copy Product Hub mevcut politika diline yaklaşıyor.
- Değişen method bölümleri:
  - `buildWarningPayload(...)`
- Kullanıcı davranışı değiştiriyor mu:
  - Evet, quote create/edit veya live info warning metnini etkiler.
- Tenant isolation etkisi:
  - Hayır.
- Permission etkisi:
  - Hayır.
- Finans/maliyet görünürlüğü:
  - Doğrudan hayır.
- İlişkili faz/preview:
  - Product Hub live product info / quote create-edit yüzeyi.
- İlgili checkpoint:
  - `e2ad705`, `818bb78`, `8321953` ile tematik bağ var.
- Test karşılığı:
  - `ProductHubLiveProductInfoEndpointTest`
  - `PromotionQuoteLiveProductInfoUiTest`
- Dosya bazlı staging güvenli mi:
  - Hayır; controller çok büyük ve geçmişte karışık checkpoint içerdi.
- Patch/hunk staging gerekir mi:
  - Evet.
- Önerilen sınıf:
  - `B — Güvenli küçük cleanup`
- Önerilen ayrı commit grubu:
  - dar `Product Hub warning text cleanup`.
- Şimdi commitlenmeli mi:
  - Bu prep fazında hayır; ayrı prep sonrası evet olabilir.
- Rollback yaklaşımı:
  - Yalnız ilgili hunk patch ile geri alınabilir; controller'ın tamamına dokunulmamalı.

### 6.3 `app/Models/Order.php`

- HEAD'e göre değişiklik özeti:
  - `git diff -w` boş.
- Değişen method bölümleri:
  - `sourceOrder()`, `copiedQuoteDrafts()`, `copiedByUser()`, revision helper'ları raw diff'te görülüyor ama anlamlı içerik farkı yok.
- Kullanıcı davranışı değiştiriyor mu:
  - Hayır.
- Tenant isolation / permission / finans etkisi:
  - Açık diff'te yok.
- İlişkili faz:
  - revision/repeat order metadata.
- İlgili checkpoint:
  - `523743e`, `ba873d3`.
- Test karşılığı:
  - revision/repeat order aile testleri.
- Dosya bazlı staging güvenli mi:
  - Hayır.
- Patch/hunk staging gerekir mi:
  - Hayır; meaningful hunk yok.
- Önerilen sınıf:
  - `A — Tamamlanmış checkpoint kalıntısı`
- Önerilen ayrı commit grubu:
  - yok.
- Şimdi commitlenmeli mi:
  - Hayır.
- Rollback yaklaşımı:
  - İleride normalize/restore prep ile.

### 6.4 `config/admin_menu.php`

- HEAD'e göre değişiklik özeti:
  - Yalnız BOM kaldırma ve son boş satır temizliği.
- Değişen menu bölümleri:
  - Yok; label/order/permission farkı tespit edilmedi.
- Kullanıcı davranışını değiştiriyor mu:
  - Hayır.
- Tenant isolation / permission etkisi:
  - Açık diff'te yok.
- Finans/maliyet görünürlüğü:
  - Açık diff'te yok.
- İlişkili faz:
  - menu cleanup.
- İlgili checkpoint:
  - `8e5f558` tamamlanmış.
- Test karşılığı:
  - `AdminMenuServiceTest`
  - `AdminMenuVisibilityTest`
  - `TenantProductCatalogMenuSimplificationTest`
  - `FinanceMenuAuthorizationConsistencyTest`
- Dosya bazlı staging güvenli mi:
  - Evet.
- Patch/hunk staging gerekir mi:
  - Hayır.
- Önerilen sınıf:
  - `B — Güvenli küçük cleanup`
- Önerilen ayrı commit grubu:
  - `Menu Cleanup`.
- Şimdi commitlenmeli mi:
  - Önce küçük prep ile evet olabilir.
- Rollback yaklaşımı:
  - Tek dosya cleanup commit'i kolay rollback edilir.

### 6.5 `public/css/prodelya-admin.css`

- HEAD'e göre değişiklik özeti:
  - `git diff -w --stat` sonrası 204 ekleme / 35 silme seviyesinde anlamlı CSS farkı kalıyor.
  - İki ana aile var:
    - shared/global primitive genişlemesi
    - quote detail/send modal buton yerleşimi artık hunkları
- Değişen selector aileleri:
  - `:root`
  - `.pd-btn--*`
  - `.pd-chip--*`
  - `.pd-tabs*`
  - `.pd-form*`
  - `.pd-summary__line`
  - `.pd-modal*`
  - `.promotion-quote-detail.quote-detail-compact ... .pd-btn`
- Kullanıcı davranışını değiştiriyor mu:
  - Evet, görünüm ve layout etkisi var.
- Tenant isolation / permission etkisi:
  - Doğrudan yok.
- Finans/maliyet görünürlüğü:
  - Doğrudan yok; dolaylı modal/buton görünürlüğü etkilenebilir.
- İlişkili faz:
  - quote detail send modal, quote detail unified UI, shared design primitive, potansiyel Product Hub/shared UI.
- İlgili checkpoint:
  - `889f661`, `dce2098`, `8bdac82`, `0fd49be`.
- Test karşılığı:
  - quote detail/send modal UI testleri
  - manuel browser smoke
  - responsive smoke
- Dosya bazlı staging güvenli mi:
  - Kesinlikle hayır.
- Patch/hunk staging gerekir mi:
  - Evet, zorunlu.
- Önerilen sınıf:
  - hunk 1 `G — Sonraki faza bırakılacak` (shared primitives)
  - hunk 2 `A — Tamamlanmış checkpoint kalıntısı` veya dar residual UI cleanup
- Önerilen ayrı commit grubu:
  - `Feature-Scoped CSS Checkpoints`
- Şimdi commitlenmeli mi:
  - Hayır.
- Rollback yaklaşımı:
  - Yalnız namespace bazlı patch staging ve gerektiğinde patch revert.

### 6.6 `routes/web.php`

- HEAD'e göre değişiklik özeti:
  - Yalnız BOM kaldırma ve son boş satır temizliği.
- Değişen route bölümleri:
  - Raw diff revision compare / repeat-order satırlarında görünse de `-w` ile fark yok.
- Kullanıcı davranışı değiştiriyor mu:
  - Hayır.
- Tenant isolation / permission / finans etkisi:
  - Yok.
- İlişkili faz:
  - route cleanup.
- İlgili checkpoint:
  - `f0e9910`, `ba873d3`, `7e4689d`, `dfbce43`.
- Test karşılığı:
  - route smoke + ilgili feature testleri.
- Dosya bazlı staging güvenli mi:
  - Evet.
- Patch/hunk staging gerekir mi:
  - Hayır.
- Önerilen sınıf:
  - `B — Güvenli küçük cleanup`
- Önerilen ayrı commit grubu:
  - `Product Hub Route Cleanup` dışı küçük route encoding cleanup, ayrı düşünülebilir.
- Şimdi commitlenmeli mi:
  - Önce prep ile evet olabilir.
- Rollback yaklaşımı:
  - Tek cleanup commit'i kolay rollback edilir.

### 6.7 `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`

- HEAD'e göre değişiklik özeti:
  - Liste başlıklarını mevcut UI diline hizalıyor.
- Değişen test bölümleri:
  - order index header label
  - quote index tab label
- Kullanıcı davranışı değiştiriyor mu:
  - Hayır; yalnız beklenti güncelleniyor.
- Tenant isolation etkisi:
  - Hayır.
- Permission etkisi:
  - Hayır.
- Finans/maliyet görünürlüğü:
  - Hayır.
- İlişkili faz:
  - quote/order list checkpoint sonrası regression hardening.
- İlgili checkpoint:
  - `bf053ca`, `fd141db`.
- Test karşılığı:
  - kendisi.
- Dosya bazlı staging güvenli mi:
  - Evet.
- Patch/hunk staging gerekir mi:
  - Hayır.
- Önerilen sınıf:
  - `B — Güvenli küçük cleanup`
- Önerilen ayrı commit grubu:
  - `Quote / Order List Behavior and Tests`
- Şimdi commitlenmeli mi:
  - Production karşılığı açık olduğu için tests-only grup içinde evet olabilir.
- Rollback yaklaşımı:
  - Ayrı test commit'i kolay geri alınır.

### 6.8 `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`

- HEAD'e göre değişiklik özeti:
  - `Hazırlanan Teklifler` beklentisini `Açık Teklifler` ile hizalıyor.
- Kullanıcı davranışı değiştiriyor mu:
  - Hayır.
- Tenant isolation / permission / finans etkisi:
  - Yok.
- İlişkili faz:
  - quote list regression hardening.
- İlgili checkpoint:
  - `bf053ca`.
- Test karşılığı:
  - kendisi.
- Dosya bazlı staging güvenli mi:
  - Evet.
- Patch/hunk staging gerekir mi:
  - Hayır.
- Önerilen sınıf:
  - `B — Güvenli küçük cleanup`
- Önerilen ayrı commit grubu:
  - `Quote / Order List Behavior and Tests`
- Şimdi commitlenmeli mi:
  - Tests-only grupla evet olabilir.
- Rollback yaklaşımı:
  - Ayrı test commit'i kolay geri alınır.

## 7. Controller / Model Ortak Karar

- `OrderController.php`, `PromotionQuoteController.php`, `Order.php` birbirinden bağımsız değerlendirilmedi.
- `OrderController.php` ve `Order.php` diff'i yeni quote/order list davranışı üretmiyor; tamamlanmış revision/repeat-order ve liste checkpointlerine ait kalıntı görünümünde.
- `PromotionQuoteController.php` diff'i quote/order list zincirine değil, Product Hub warning metni cleanup'ına daha yakın duruyor.
- Bu üç dosyayı tek commit grubuna koymak güvenli değil.
- Özellikle Currency Core ile karışabilecek yeni fiyat davranışı worktree diff'inde görünmüyor.

## 8. Menü Cleanup Özel Kararı

- `config/admin_menu.php` açık diff'i yalnız BOM/trailing blank line.
- `TenantProductCatalogMenuSimplificationTest.php` mevcut.
- `FinanceMenuAuthorizationConsistencyTest.php` mevcut.
- Açık diff menü görünürlüğü, finans yetkisi, tenant/super-admin ayrımı veya Matbaa/Dieline görünürlüğü değiştirmiyor.
- Sonuç:
  - ayrı güvenli `Menu Cleanup` checkpoint'i yapılabilir
  - ama bu fazda yalnız raporlandı, staging yapılmadı

## 9. Routes/Web.php Özel Kararı

- `routes/web.php` içinde açık diff `-w` ile sıfırlanıyor.
- Product Hub route cleanup, `admin.catalog.search`, revision/repeat-order route'ları ve public approval named route cleanup zaten HEAD commitlerinde kanıtlı.
- Public Graphic Approval cleanup yeniden açık iş sayılmamalı.
- Sonuç:
  - route dosyasının tamamı davranış commit adayı değil
  - yalnız küçük encoding cleanup olarak ayrı düşünülebilir

## 10. CSS HUNK HARİTASI

| Selector Ailesi | İlgili Ekran | Checkpoint | Güvenli mi | Ayrı Prep Gerekir mi |
| --- | --- | --- | --- | --- |
| `:root`, `body`, `textarea`, `.pd-btn--*`, `.pd-chip--*` | shared/global primitive | açık checkpoint yok | Hayır | Evet |
| `.pd-tabs*`, `.pd-form*`, `.pd-summary__line`, `.pd-modal*` | ortak UI primitive | açık checkpoint yok | Hayır | Evet |
| `.promotion-quote-detail.quote-detail-compact ... .pd-btn` | quote detail / send modal | `889f661`, `dce2098` ile ilişkili | Kısmen | Evet |
| `.pd-order-*` | order detail operation center | `8bdac82` tamamlanmış | Şu an açık diff'te yok | Hayır, yeniden açılmamalı |
| `.order-revision-compare` | revision compare | `ba873d3` / revision docs zinciri | Şu an açık diff'te yok | Hayır |
| `.pd-product-hub` primitive satırı | Product Hub/shared UI | açık checkpoint yok | Hayır | Evet |

## 11. UNTRACKED DOSYA MATRİSİ

| Dosya | Tür | Değer | Risk | Karar | Önerilen Grup |
| --- | --- | --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | temp blade | stale staging hedefi, gerçek view'dan geri | Orta | `SAFE DELETE CANDIDATE` | Grup 1 |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | temp blade | gerçek worktree yedeği; benzersiz diff taşımıyor ama yedek niteliği var | Orta | `MANUAL REVIEW REQUIRED` | Grup 1 |
| `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md` | plan | gelecekteki teknik plan | Düşük | Keep / arşiv | Grup 6 |
| `docs/CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP-RAPORU-20260710.md` | prep raporu | staging prep kanıtı | Düşük | commitlenmeye değer historical docs | Grup 6 |
| `docs/CSS-TEMPLATE-HUNK-STAGING-PREP-RAPORU-20260710.md` | prep raporu | CSS/template audit kanıtı | Düşük | commitlenmeye değer historical docs | Grup 6 |
| `docs/FULL-SYSTEM-SCAN-20260709.md` | audit | geniş tarihsel tarama | Orta | superseded ama rollback değeri var; arşiv | Grup 6 |
| `docs/ORDER-DETAIL-TEMP-CLEANUP-SAFE-RAPORU-20260710.md` | cleanup raporu | gerçek temp cleanup kanıtı | Düşük | commitlenmeye değer historical docs | Grup 6 |
| `docs/PRODUCT-HUB-AND-TEMPLATE-INTEGRATION-MASTER-PLAN-20260709.md` | plan | Product Hub teknik planı | Düşük | keep / arşiv | Grup 6 |
| `docs/QUOTE-DETAIL-CHECKPOINT-COMMIT-APPLY-RAPORU-20260710.md` | başarısız commit kanıtı | test blokajı ve seçici staging izi | Orta | historical evidence | Grup 6 |
| `docs/QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN-RAPORU-20260710.md` | rollback/scope realign | neden başarısız olduğunu açıklıyor | Orta | historical evidence | Grup 6 |
| `docs/QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP-RAPORU-20260710.md` | prep raporu | send-channel prep kanıtı | Düşük | historical docs | Grup 6 |
| `docs/REVISION-CHECKPOINT-A-B-C-COMMIT-APPLY-RAPORU-20260709.md` | checkpoint kanıtı | gerçek commit hash içeriyor | Düşük | yüksek kanıt değeri, commitlenmeye değer | Grup 6 |
| `docs/REVISION-CHECKPOINT-A-B-C-HUNK-STAGING-PREP-RAPORU-20260709.md` | prep raporu | revision checkpoint hazırlık izi | Düşük | historical docs | Grup 6 |
| `docs/REVISION-PUBLIC-APPROVAL-CHECKPOINT-PREP-RAPORU-20260709.md` | prep raporu | erken worktree kanıtı | Orta | historical docs / arşiv | Grup 6 |
| `docs/SAFE-ROLLBACK-AUDIT-20260709.md` | rollback audit | eski geniş risk fotoğrafı | Orta | superseded ama rollback değeri var | Grup 6 |
| `docs/WORKTREE-TEMP-CLEANUP-SAFE-RAPORU-20260710.md` | cleanup raporu | gerçek temp cleanup kanıtı | Düşük | historical docs | Grup 6 |
| `docs/ui-previews/` | HTML preview arşivi | fikir/ref arşivi | Orta | sanitize + manifest sonrası ayrı karar | Grup 7 |
| `tests/Feature/QuoteOrderListNoSensitiveLeakTest.php` | test | mevcut liste güvenlik davranışını kilitler | Düşük | keep, commitlenmeye değer | Grup 2 |
| `tests/Feature/QuoteOrderListNoTechnicalUiLeakRegressionTest.php` | test | teknik alan sızıntı regresyonu | Düşük | keep, commitlenmeye değer | Grup 2 |
| `tests/Feature/QuoteOrderListTenantIsolationTest.php` | test | tenant isolation koruması | Düşük | keep, commitlenmeye değer | Grup 2 |
| `tests/Feature/QuoteOrderListTurkishTerminologyTest.php` | test | Türkçe label/regression | Düşük | keep, commitlenmeye değer | Grup 2 |
| `tests/Feature/QuoteOrderManualSmokeRouteTest.php` | test | liste route smoke kapsaması | Düşük | keep, commitlenmeye değer | Grup 2 |

## 12. TEST EŞLEME TABLOSU

| Test | Production Karşılığı | Duplicate mi | Önerilen Grup |
| --- | --- | --- | --- |
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | quote/order list header ve sağ panel label'ları | Kısmi örtüşme var ama aynı değil | Grup 2 |
| `PromotionQuoteAndOrderIndexUxTest` | quote list kompakt satış kuyruğu / UI başlıkları | Kısmi örtüşme var ama aynı değil | Grup 2 |
| `QuoteOrderListNoSensitiveLeakTest` | listelerde hassas alan sızıntısının engellenmesi | Hayır | Grup 2 |
| `QuoteOrderListNoTechnicalUiLeakRegressionTest` | `group_code`, `file_path`, `price_snapshot`, `projection/raw` sızıntı koruması | Kısmi, ama kapsamı geniş | Grup 2 |
| `QuoteOrderListTenantIsolationTest` | tenant-scoped quote/order list görünürlüğü | Hayır | Grup 2 |
| `QuoteOrderListTurkishTerminologyTest` | `Açık Teklifler`, `Siparişe Dönüşenler`, `Aktif Siparişler` dili | Kısmi, ama özel amaçlı | Grup 2 |
| `QuoteOrderManualSmokeRouteTest` | active/converted/archived/completed/all route smoke | Hayır | Grup 2 |
| `TenantProductCatalogMenuSimplificationTest` | menu cleanup davranışının mevcut HEAD kanıtı | Hayır | Grup 3 referans testi |
| `FinanceMenuAuthorizationConsistencyTest` | finans menü görünürlüğü ve route yetkisi | Hayır | Grup 3 referans testi |
| `ProductHubLiveProductInfoEndpointTest` | category pending sellable uyarı payload'ı | Hayır | PromotionQuoteController cleanup referansı |
| `PromotionQuoteLiveProductInfoUiTest` | quote create/edit live info UI güvenliği | Hayır | PromotionQuoteController cleanup referansı |

## 13. Geçici Blade Dosyaları

### 13.1 `.tmp_quote_detail_commit_target.blade.php`

- Gerçek blade karşılığı:
  - `resources/views/admin/promotion-quotes/show.blade.php`
- HEAD ile aynı mı:
  - Hayır.
- Worktree blade ile aynı mı:
  - Hayır.
- Tespit:
  - stale commit target.
  - içinde bozuk satır birleşmeleri var:
    - `: $calculatedPrintTotal; static function ...`
    - `}; ! $isConverted ...`
  - gerçek worktree'deki send modal / source order / revision compare / approval helper bloklarını eksik bırakıyor.
- Quote detail checkpoint ilişkisi:
  - Evet, tamamlanmış/yarım kalmış quote detail staging denemesi artığı.
- Benzersiz, kaybolmaması gereken içerik var mı:
  - Hayır; tersine eksik ve bozuk.
- Karar:
  - `SAFE DELETE CANDIDATE`

### 13.2 `.tmp_quote_detail_show_worktree_backup.blade.php`

- Gerçek blade karşılığı:
  - `resources/views/admin/promotion-quotes/show.blade.php`
- HEAD ile aynı mı:
  - Hayır.
- Worktree blade ile aynı mı:
  - Görünen ilk bloklarda büyük ölçüde aynı mantığı taşıyor; send modal ve phone helper metinleri gibi daha yeni birleşik içerikleri içeriyor.
- Quote detail checkpoint ilişkisi:
  - Evet, quote detail worktree yedeği.
- Benzersiz, kaybolmaması gereken içerik var mı:
  - Mevcut okuma içinde benzersiz logic görünmedi; ama bu dosya açıkça backup niyetli.
- Temp cleanup raporları ne diyor:
  - önceki raporlar bu iki dosyayı worktree'de bırakmış, otomatik silmemiş.
- Karar:
  - `MANUAL REVIEW REQUIRED`

## 14. DOCS KARAR TABLOSU

| Doküman | Kanıt Değeri | Superseded mi | Commit/Arşiv/Safe-delete |
| --- | --- | --- | --- |
| `10.15.18-C-revizyonu-uygula-teknik-karar-plani.md` | Orta | Hayır, teknik plan olarak yaşıyor | Arşiv / keep |
| `CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP...` | Orta | Hayır | Commit |
| `CSS-TEMPLATE-HUNK-STAGING-PREP...` | Orta | Kısmen master audit tarafından gölgelenmiş | Commit / arşiv |
| `FULL-SYSTEM-SCAN-20260709.md` | Orta | Evet, büyük ölçüde master audit ile | Arşiv |
| `ORDER-DETAIL-TEMP-CLEANUP-SAFE...` | Yüksek | Hayır | Commit |
| `PRODUCT-HUB-AND-TEMPLATE-INTEGRATION-MASTER-PLAN...` | Orta | Kısmen | Arşiv |
| `QUOTE-DETAIL-CHECKPOINT-COMMIT-APPLY...` | Yüksek | Hayır, başarısız deneme kanıtı | Commit / arşiv |
| `QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN...` | Yüksek | Hayır | Commit / arşiv |
| `QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP...` | Orta | Hayır | Commit |
| `REVISION-CHECKPOINT-A-B-C-COMMIT-APPLY...` | Çok yüksek | Hayır | Commit |
| `REVISION-CHECKPOINT-A-B-C-HUNK-STAGING-PREP...` | Yüksek | Hayır | Commit |
| `REVISION-PUBLIC-APPROVAL-CHECKPOINT-PREP...` | Orta | Kısmen | Arşiv |
| `SAFE-ROLLBACK-AUDIT-20260709.md` | Orta | Evet, master audit sonrası | Arşiv |
| `WORKTREE-TEMP-CLEANUP-SAFE...` | Yüksek | Hayır | Commit |

## 15. PREVIEW ARŞİV KARARI

| Kontrol | Sonuç | Risk | Öneri |
| --- | --- | --- | --- |
| Fiziksel dosya sayısı | `146` HTML dosya | Orta | Master audit'teki `145 benzersiz preview` kararıyla reconcile edilmeden kör commit yapılmamalı |
| Duplicate varyantlar | en az 5 base isimde `(1)/(2)` kopyaları var | Orta | Manifest ile açıklanmalı |
| Repository boyutu | toplam yaklaşık `5.64 MB` | Düşük/Orta | Tek docs-only commit teknik olarak mümkün |
| Binary ayrı asset | Yok; yalnız `.html` | Düşük | Kabul edilebilir |
| Aşırı büyük öğe | 3 adet ~`517 KB` HTML var | Orta | Inline base64 yüzünden ağır; önce gözden geçir |
| Harici dependency | dış görsel URL'leri, `dummyimage`, tedarikçi hostları, `data:image` var | Orta | Offline/uzun vadeli arşiv için sanitize önerilir |
| Hassas veri / token | API key/token bulunmadı; ama local host ve yerel admin örneği var | Orta | sanitize önerilir |
| Yerel yol / local domain | `saklimavi.prodelya_core.test`, `admin@saklimavi.local` tespit edildi; `file:///C:/` yok | Orta | sanitize edilmeden commitlenmemeli |
| Şifre alanı | bazı preview'larda placeholder password input var | Düşük | sorun değil, ama not düşülmeli |
| Önce manifest gerekir mi | Evet | Orta | ayrı `preview manifest + sanitize` prep sonrası commit |
| Arşiv commit zamanı | Stabilization sonu veya historical docs grubundan sonra | Orta | Uygulama kodu commitlerinden ayrı tutulmalı |

Net karar:

1. Preview arşivi Git'e alınabilir, ama hemen değil.
2. Tek docs-only commit mantıklı olabilir.
3. Önce sanitize + manifest + 145/146 sayım açıklaması gerekir.
4. Uygulama kodu commitleriyle karıştırılmamalıdır.

## 16. ÖNERİLEN COMMIT GRUPLARI

| Sıra | Grup | Dosya/Hunk | Test | Risk | Rollback |
| ---: | --- | --- | --- | --- | --- |
| 1 | Temp File Cleanup | yalnız `.tmp_*` kararları | test gerekmez | Düşük | kolay |
| 2 | Quote / Order List Behavior and Tests | modified 2 test + untracked 5 liste testi | quote/order list test matrisi | Düşük/Orta | kolay |
| 3 | Menu Cleanup | `config/admin_menu.php` + ilgili menu test smoke | menu testleri | Düşük | kolay |
| 4 | Route Encoding Cleanup | `routes/web.php` yalnız küçük cleanup | route smoke | Düşük | kolay |
| 5 | Product Hub Warning Text Cleanup | `PromotionQuoteController.php` dar hunk | `ProductHubLiveProductInfoEndpointTest`, `PromotionQuoteLiveProductInfoUiTest` | Orta | orta |
| 6 | Feature-Scoped CSS Checkpoints | `prodelya-admin.css` hunk bazlı aileler | feature UI testleri + manual smoke | Yüksek | zor |
| 7 | Historical Docs / Checkpoint Reports | gerçek kanıt değeri olan untracked docs | test gerekmez | Düşük | kolay |
| 8 | UI Preview Reference Archive | `docs/ui-previews/` + manifest/sanitize çıktıları | güvenlik/sanitize kontrolü | Orta | orta |

## 17. ÖNERİLEN UYGULAMA SIRASI

### 17.1 Mikro sıra

1. Temp/backup dosyaları için safe cleanup prep
2. Quote/order list tests-only hardening prep
3. Menu cleanup prep
4. Route encoding cleanup prep
5. Product Hub warning-text cleanup prep
6. CSS hunk family prep
7. Historical docs checkpoint prep
8. UI preview archive sanitize/manifest prep
9. Final clean-worktree verification

### 17.2 Gerekçeler

1. Temp dosyalar en düşük riskli ve yanlışlıkla staging kirini azaltır.
2. Quote/order list testleri mevcut HEAD davranışını kilitleyerek regression güveni sağlar.
3. Menü cleanup davranışsız ve küçük.
4. Route cleanup davranışsız ve küçük.
5. Product Hub warning-text cleanup dar ama production UI metni etkiler; test eşliği gerekir.
6. CSS en yüksek karışma riskli alan; önce diğer küçük gruplar ayrılmalı.
7. Historical docs uygulama kodundan tamamen ayrı tutulmalı.
8. Preview archive en son, sanitize/manifest sonrası ele alınmalı.
9. Son kontrol, yeni büyük foundation fazına temiz zemin sağlar.

## 18. TEST PLANI

### 18.1 Quote / order list grubu

- `PromotionQuoteAndOrderIndexHeaderPanelTest`
- `PromotionQuoteAndOrderIndexUxTest`
- `QuoteOrderListNoSensitiveLeakTest`
- `QuoteOrderListNoTechnicalUiLeakRegressionTest`
- `QuoteOrderListTenantIsolationTest`
- `QuoteOrderListTurkishTerminologyTest`
- `QuoteOrderManualSmokeRouteTest`
- `AdminSmokeTest`
- `FullOperationalFlowSmokeTest` seçilmiş smoke

### 18.2 Menü cleanup grubu

- `AdminMenuServiceTest`
- `AdminMenuVisibilityTest`
- `TenantProductCatalogMenuSimplificationTest`
- `FinanceMenuAuthorizationConsistencyTest`
- `TenantAccessServiceTest`
- `TenantUserRolePermissionFlowTest`

### 18.3 Route cleanup grubu

- route smoke
- `ProductHubLiveProductInfoEndpointTest`
- `PromotionQuoteLiveProductInfoUiTest`
- catalog search ilgili smoke'lar

### 18.4 Product Hub warning-text cleanup

- `ProductHubLiveProductInfoEndpointTest`
- `PromotionQuoteLiveProductInfoUiTest`
- gerekirse `ProductDataHubUnmappedCategoryProjectionTest`

### 18.5 CSS grubu

- ilgili quote detail/send modal feature testleri
- ilgili order detail smoke'ları yalnız etkilenirse
- manual browser smoke
- responsive smoke

## 19. RİSK MATRİSİ

| Grup | Davranış | Tenant | Permission | Finans/Maliyet Sızıntısı | Route | CSS | Rollback | Test Maliyeti |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Temp File Cleanup | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük |
| Quote / Order List Behavior and Tests | Orta | Orta | Düşük | Orta | Düşük | Düşük | Düşük | Orta |
| Menu Cleanup | Düşük | Orta | Orta | Orta | Düşük | Düşük | Düşük | Orta |
| Route Encoding Cleanup | Düşük | Düşük | Düşük | Düşük | Orta | Düşük | Düşük | Düşük |
| Product Hub Warning Text Cleanup | Orta | Düşük | Düşük | Düşük | Düşük | Düşük | Orta | Orta |
| Feature-Scoped CSS Checkpoints | Orta | Düşük | Düşük | Düşük | Düşük | Kritik | Orta/Yüksek | Yüksek |
| Historical Docs / Checkpoint Reports | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük | Düşük |
| UI Preview Reference Archive | Düşük | Düşük | Düşük | Orta | Düşük | Düşük | Orta | Düşük |

## 20. DOSYA ADI NORMALİZASYON KONTROLÜ

| Olası Yol | Git’te Var mı | Worktree’de Var mı | Karar |
| --- | --- | --- | --- |
| `docs/PRODELYA-MASTER-UYGULAMA-YOL-HARİTASI-AUDIT-RAPORU-20260710.md` | Evet | Evet | Canonical yol |
| `docs/PRODELYA-MASTER-UYGULAMA-YOL-HARITASI-AUDIT-RAPORU-20260710.md` | Hayır | Hayır | Ayrı dosya yok |

## 21. Net Karar

- Worktree içindeki 8 modified dosyanın tamamı incelendi.
- Modified alan tek bir feature gibi davranmıyor; en az 8 ayrı commit grubu/paket mantığı gerekiyor.
- `OrderController.php` ve `Order.php` mevcut haliyle yeni feature diff'i değil; tamamlanmış checkpoint kalıntısı gibi davranıyor.
- `PromotionQuoteController.php` dar Product Hub warning cleanup'ı olarak ayrılmalı.
- `config/admin_menu.php` ve `routes/web.php` küçük cleanup adayı.
- Quote/order list untracked testleri, mevcut HEAD üretim davranışını kilitleyen yararlı tests-only paket oluşturuyor.
- `public/css/prodelya-admin.css` dosyası en riskli alan; dosya bazlı staging yasaklanmalı.
- Preview arşivi ayrı docs/reference checkpoint olmalı; sanitize + manifest ön koşulu var.
