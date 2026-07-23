# PRODELYA UI Standardı v1 Mevcut CSS Çakışma ve Entegrasyon Audit Raporu

Tarih: 2026-07-20
Faz: UI-0 Read-Only Audit
Kapsam: `public/css/prodelya-admin.css`, referans `prodelya_ortak_ui_standardi.css`, Blade inline-style yüzeyi, ilk pilot olarak `/admin/orders`

## 1. Amaç

Bu auditin amacı, mevcut Prodelya admin arayüzündeki `pd-*` sınıf ailesini referans UI standardı ile çakıştırmadan nasıl tek tip görsel sisteme taşınabileceğini belirlemektir.

Bu turda:
- Kod, CSS, Blade, JS, route, controller veya config değiştirilmemiştir.
- Staging, commit, yedekleme yapılmamıştır.
- Yalnız inceleme ve entegrasyon sınırı çıkarılmıştır.

## 2. Executive Summary

Sonuç:
- Entegrasyon teknik olarak mümkündür.
- Ancak referans CSS dosyası doğrudan sisteme eklenirse yüksek olasılıkla global çakışma üretir.
- En büyük risk, referans dosyanın global selector kullanması ve mevcut `pd-*` primitive’leriyle aynı isimleri farklı anlamlarda tanımlamasıdır.
- İlk pilot için güvenli sınır `/admin/orders` **liste ekranı** olmalıdır.
- `/admin/orders/show` ilk pilot için güvenli değildir; çünkü hem mevcut inline-style yüzeyi geniştir hem de Process Depth, sticky panel, canonical focus ve CTA katmanları birikmiştir.

Ana karar:
- Prodelya UI Standardı v1, **global override** olarak değil, **scoped entegrasyon** veya **primitive alias katmanı** ile ilerlemelidir.

## 3. İncelenen Kaynaklar

İncelenen ana dosyalar:
- `public/css/prodelya-admin.css`
- Referans: `C:\Users\HP\Desktop\Downloads\prodelya_ortak_ui_standardi.css`

Blade inline-style yüzeyini ölçmek için tüm `resources/views/**/*.blade.php` dosyalarında şu pattern’ler taranmıştır:
- `@push('styles')`
- `<style>`
- `style="..."`

## 4. Mevcut `prodelya-admin.css` Genel Durumu

Mevcut admin CSS yapısı tek katmanlı değildir. Aşağıdaki karakteristikler tespit edilmiştir:

- Erken bölümde genel `:root` tokenları ve temel `pd-*` primitive’leri tanımlı.
- Aynı dosya içinde sonradan eklenmiş, tarih etiketli override blokları bulunuyor.
- Özellikle ürün detay ve local products tarafında çok sayıda ek “hotfix/fidelity/refresh” bloğu mevcut.
- Bu durum cascade sırasını kritik hale getiriyor.

Öne çıkan durumlar:
- Global `body`, `input`, `select`, `textarea`, `button`, `table` seviyesinde temel stil zaten tanımlı.
- Global `pd-*` primitive’ler mevcut:
  - `.pd-btn`
  - `.pd-card`
  - `.pd-summary`
  - `.pd-modal`
  - `.pd-grid-*`
  - `.pd-table`
  - `.pd-badge`
  - `.pd-form*`
  - `.pd-tabs*`
- `orders`, `local products`, `catalog detail`, `stock purchases`, `product hub`, `finance`, `procurement` gibi alanlara sonradan eklenmiş özel override blokları var.

Audit kararı:
- Mevcut CSS dosyası “temel tasarım sistemi + ekran bazlı override deposu” haline gelmiş.
- Bu yüzden yeni standart doğrudan aynı class isimleriyle yüklenirse istemsiz regress üretir.

## 5. Referans UI Standardı Genel Yapısı

Referans CSS aşağıdaki görsel mantığı taşıyor:

- Font: `Inter, "Segoe UI", Arial, sans-serif`
- Body scale: `13px`
- Daha kompakt, düşük density’li input/button/card yapısı
- Basit ama sıkı primitive set:
  - `.pd-shell`
  - `.pd-sidebar`
  - `.pd-main`
  - `.pd-stack`
  - `.pd-layout-2col`
  - `.pd-page-header`
  - `.pd-card`
  - `.pd-field`
  - `.pd-btn`
  - `.pd-summary`
  - `.pd-sticky-actions`
  - `.pd-filter-bar`
  - `.pd-stepper`
  - `.pd-modal`

Referans dosya yeni ekran/preview üretimi için uygun; fakat mevcut uygulama üzerine birebir global bind edilmesi güvenli değildir.

## 6. Kesin Token Tablosu

Prodelya UI Standardı v1 için önerilen kesin token seti aşağıdaki gibi dondurulmalıdır.

| Alan | Kesin v1 Kararı | Not |
|---|---|---|
| Font ailesi | `Arial, Helvetica, sans-serif` | Mevcut prod ortamıyla uyumlu, regress riski düşük |
| Body temel boyut | `14px` | Mevcut admin ve son kabul edilen ekranlarla uyumlu |
| Yardımcı küçük metin | `12px` | Caption/help/muted için |
| Çok küçük label | `10px` veya `11px` | Sadece field label / table header |
| Ana metin rengi | `#172033` / `#18263d` bandı | Tek value’a indirilmeli |
| İkincil metin | `#667085` / `#6d7d96` bandı | Tek value’a indirilmeli |
| Sayfa zemini | `#f4f6f8` veya `#f4f6fa` | İki ton çok yakın, birleştirilmeli |
| Kart zemini | `#ffffff` | Korunmalı |
| Border | `#e3e8f0` ana border | `#dce5f0` ile yakın; tek değere indirilmeli |
| Primary | `#2864e8` bandı | `#2563eb` ile semantik olarak aynı aile; birleştirilmeli |
| Başarı rengi | `#168249` / `#16a34a` | Tek value’a indirilmeli |
| Uyarı rengi | `#b87312` / `#d97706` | Tek value’a indirilmeli |
| Tehlike rengi | `#c53e36` / `#dc2626` | Tek value’a indirilmeli |
| Kart radius | `8px` | 12px’e göre daha güvenli ortak zemin |
| Büyük panel radius | `8px` veya `9px` | 12px global yapılmamalı |
| Kontrol radius | `6px` | Input/button için |
| Sayfa gap | `14px` | Zaten her iki ailede de ortak |
| İç gap | `8px` - `10px` | Panel içi |
| Sticky top | `14px` veya `18px` | Pilotta ekran bazlı korunmalı |
| Shadow | hafif kart gölgesi | Ağır shadow global yapılmamalı |

Kesin karar notu:
- V1 entegrasyonu için **font ailesi Arial/Helvetica** tutulmalıdır.
- `Inter` doğrudan temel font yapılmamalıdır; çünkü mevcut kabul edilmiş ekranların önemli bölümü Arial uyumlu revize edilmiştir.
- Referans standardın kompakt ölçü mantığı korunabilir, ancak font ailesi birebir kopyalanmamalıdır.

## 7. 13px ve 14px Font Ölçeği Farkı

Referans dosya:
- body `13px`
- input/button inherited ve daha sıkı compact yapı

Mevcut admin:
- html/body `14px`
- bazı küçük bileşenler `13px`
- birçok ekran kabulü 14px temelli yapılmış

Karar:
- Global body’yi `13px` yapmak yüksek risklidir.
- Güvenli entegrasyon modeli:
  - global body `14px`
  - compact alt primitive’lerde `12px/13px`
  - label ve table head için `10px/11px`

Risk:
- Body’yi `13px`e çekmek:
  - sipariş ekranları
  - procurement UI
  - local product family
  - stock purchase create
  - finance/current account özetlerinde sıkışma üretir

## 8. Selector ve Token Çakışma Matrisi

### 8.1 Doğrudan Çakışan Global Selector’ler

| Selector / Token | Mevcut admin durumu | Referans durumu | Risk |
|---|---|---|---|
| `:root --pd-*` | zaten tanımlı | aynı isimle yeni değerler | Çok yüksek |
| `body` | 14px Arial tabanı | 13px Inter tabanı | Çok yüksek |
| `button,input,select,textarea` | global tanımlı | global inherit + field stili | Çok yüksek |
| `.pd-btn` | mevcut sistemin ana primitive’i | farklı compact semantik | Çok yüksek |
| `.pd-card` | mevcut kart primitive’i | daha kompakt referans kart | Çok yüksek |
| `.pd-summary` | mevcut sticky summary alanı | referans sağ özet | Yüksek |
| `.pd-modal` | mevcut modal primitive’i | referans modal primitive’i | Yüksek |
| `.pd-sidebar` | mevcut sidebar 272px sistem | referans sidebar 232px | Çok yüksek |
| `.pd-main` | mevcut layout primitive | referans layout primitive | Yüksek |
| `.pd-grid` | mevcut grid helper | referans grid helper | Orta |
| `.pd-badge` | mevcut badge sistemi | daha sıkı compact badge | Orta |
| `.pd-sticky-actions` | mevcut bazı ekranlarda sticky action primitive | referans footer action bar | Yüksek |

### 8.2 Semantik Olarak Yakın Ama Direkt Bind Edilmemesi Gerekenler

| Alan | Mevcut | Referans | Karar |
|---|---|---|---|
| Card border | `#e5e7eb`, `#e3e8f0` | `#dce5f0` | Tekleştirilebilir |
| Button radius | 5/6/8px karışık | 6px | Primitive normalizasyon gerekir |
| Page gap | 14px bandı | 14px | Uyumlu |
| Input height | 38-42px karışık | 35px | Global değil, component bazlı hizalanmalı |
| Sidebar width | 272px | 232px | Pilotta değiştirilmemeli |

## 9. Özellikle Riskli Alanlar

### 9.1 `body`

Risk:
- Font ailesi ve base line-height değişirse tüm admin yüzeyi etkilenir.

Karar:
- İlk pilotta dokunulmamalı.

### 9.2 `button`, `input`, `select`, `textarea`

Risk:
- Form yoğun ekranlar:
  - teklif form workspace
  - procurement request edit
  - stock purchase create
  - settings
  - current account hızlı paneller
  anında etkilenir.

Karar:
- Global override yapılmamalı.
- Yalnız namespaced wrapper içinde kullanılmalı.

### 9.3 `.pd-btn`

Risk:
- Sistemin en yaygın primitive’i.
- Primary/light/success/danger semantiği çok geniş yüzeyde kullanılıyor.

Karar:
- İlk fazda `.pd-btn` global yeniden tanımlanmamalı.

### 9.4 `.pd-card`

Risk:
- Topbar, hero, summary, modal, dashboard, order, finance dahil her yerde kullanılıyor.

Karar:
- Global dokunma yok.
- Gerekirse `pd-ui-v1 .pd-card` gibi scope içinde.

### 9.5 `.pd-summary`

Risk:
- Bazı sayfalarda layout kolonu, bazı yerlerde özet kart semantiği taşıyor.

Karar:
- İlk pilotta yalnız orders index wrapper içinde kontrollü kullanılmalı.

### 9.6 `.pd-modal`

Risk:
- Hızlı işlem, popup ve dialog’larda regress üretebilir.

Karar:
- Pilot dışı tutulmalı.

### 9.7 Sticky action katmanı

Risk:
- Mevcut sistemde hem `.pd-bottom-action-bar` hem `.pd-sticky-actions` hem ekran özel sticky action yüzeyleri var.
- Z-index, left offset, sidebar width bağımlılığı mevcut.

Karar:
- İlk pilotta yeni global sticky primitive yüklenmemeli.

## 10. Inline Style Kullanan Blade Dosyaları

Recursive tarama sonucunda yüksek yoğunluklu dosyalar:

### Çok yüksek inline-style / yüksek risk
- `resources/views/admin/companies/show.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `resources/views/admin/current-accounts/show.blade.php`
- `resources/views/admin/settings/print-settings/edit.blade.php`
- `resources/views/admin/current-account-transactions/account.blade.php`
- `resources/views/admin/deliveries/show.blade.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

### Orta yoğunluk / orta risk
- `resources/views/admin/graphics/show.blade.php`
- `resources/views/admin/work-forms/show.blade.php`
- `resources/views/admin/procurements/show.blade.php`
- `resources/views/admin/stock-purchases/create.blade.php`
- `resources/views/admin/product-data-hub/index.blade.php`
- `resources/views/admin/catalog/local-products*.blade.php`

### Düşük veya sınırlı risk
- `resources/views/admin/orders/index.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/finance/index.blade.php`
- `resources/views/admin/settings/index.blade.php`

Karar:
- Inline-style yoğun ekranlarda global primitive migration yapılmamalı.
- Bu ekranlar daha sonra ayrı “cleanup” fazı gerektirir.

## 11. Risk Matrisi

### Yüksek Riskli Ekranlar

| Ekran | Risk nedeni |
|---|---|
| `/admin/orders/{order}` | 59 inline-style hit, sticky panel, Process Depth, CTA ve multi-section yapı |
| `/admin/companies/{company}` | 202 inline-style hit, legacy dense markup |
| `/admin/current-accounts/{account}` | yüksek inline ve bilgi yoğunluğu |
| `/admin/current-account-transactions/account` | çok sayıda style override |
| `promotion-quotes/_form-workspace` | yoğun JS ve form selector bağımlılığı |
| `/admin/deliveries/{delivery}` | dense panel + inline style |

### Orta Riskli Ekranlar

| Ekran | Risk nedeni |
|---|---|
| `/admin/stock/purchases/create` | sayfa özel style bloğu ve hesaplama formu |
| `/admin/procurements/*` | süreç kartları + statü paneli |
| `/admin/graphics/show` | sticky özet + süreç gösterimi |
| `/admin/catalog/local-products/*` | son dönemde eklenmiş scoped override birikimi |
| `/admin/product-data-hub/*` | özel layout ve migration hâlindeki görsel aile |

### Düşük Riskli Ekranlar

| Ekran | Risk nedeni |
|---|---|
| `/admin/orders` | liste ekranı, pilot için uygun |
| `/admin/dashboard` | kontrollü kart yüzeyi |
| `/admin/settings` | sınırlı görsel yüzey |
| `/admin/finance` index | temel tablo/kart yüzeyi |

## 12. Korunacak Mevcut Primitive’ler

Aşağıdaki mevcut primitive’ler korunmalı, tamamen yeniden adlandırılmamalıdır:

- `:root --pd-*` ana token sistemi
- `.pd-btn` ve varyant ailesi
- `.pd-card`, `.pd-card-header`, `.pd-card-body`
- `.pd-grid-*`
- `.pd-table`, `.pd-table-wrap`
- `.pd-badge-*`
- `.pd-form`, `.pd-input`, `.pd-select`, `.pd-textarea`
- `.pd-summary__line`
- `.pd-modal*`
- `.pd-bottom-action-bar`
- Orders için:
  - `.pd-orders-index-layout`
  - `.pd-orders-sticky-panel`
  - `.pd-order-*` ailesi

Karar:
- V1 standardı bu primitive’leri **replace** etmemeli.
- En fazla:
  - token alias
  - scoped override
  - yeni helper primitive
  ile ilerlemeli.

## 13. Eklenmesi Gereken Yeni Primitive’ler

Referans dosyadaki mantık korunacaksa, doğrudan çakışan isimlerle değil, aşağıdaki yeni primitive katmanı gerekir:

- `--pd-ui-v1-font-body`
- `--pd-ui-v1-font-small`
- `--pd-ui-v1-border`
- `--pd-ui-v1-surface`
- `--pd-ui-v1-gap`
- `--pd-ui-v1-inner-gap`
- `--pd-ui-v1-radius-card`
- `--pd-ui-v1-radius-control`

Yeni helper primitive önerileri:
- `.pd-ui-v1-stack`
- `.pd-ui-v1-stack-tight`
- `.pd-ui-v1-page-header`
- `.pd-ui-v1-card`
- `.pd-ui-v1-field`
- `.pd-ui-v1-filter-bar`
- `.pd-ui-v1-summary`
- `.pd-ui-v1-callout`
- `.pd-ui-v1-stepper`
- `.pd-ui-v1-sticky-actions`

Karar:
- Referans standarttaki `.pd-card`, `.pd-btn`, `.pd-summary` gibi isimler doğrudan kullanılmamalı.
- Aynı mantık, yeni primitive katmanı veya sayfa wrapper’ı üzerinden bağlanmalı.

## 14. Fazlı Entegrasyon Sırası

### Faz 0
- Audit ve token freeze

### Faz 1
- Ortak token alias katmanı
- Global override yok
- Yeni primitive’ler scoped olarak eklenir

### Faz 2
- Pilot: `/admin/orders` liste ekranı
- Sadece list/index yüzeyi

### Faz 3
- Düşük riskli index sayfaları
- dashboard, finance index, settings index

### Faz 4
- Orta riskli süreç listeleri
- procurements index
- graphics index
- stock purchases index/create

### Faz 5
- Local products family ve catalog detail
- yalnız mevcut kabul edilmiş görsel aile korunarak

### Faz 6
- Yüksek riskli detail ekranları
- order show
- company show
- current account show
- delivery show

### Faz 7
- Inline-style cleanup fazı

## 15. İlk Pilot: `/admin/orders` İçin Güvenli Entegrasyon Sınırı

İlk pilot için güvenli seçim:
- `/admin/orders` index ekranı

Neden:
- Liste ekranı, detail ekrana göre çok daha kontrollü.
- Order show ekranı şu aşamada Process Depth, sticky summary, CTA ve kabul edilmiş davranışları nedeniyle yüksek riskli.

İlk pilotta yapılabilecek güvenli işler:
- Liste kart/başlık/filtre/tablo spacing uyarlaması
- Font-scale ve muted text dengesi
- Summary/sticky panel yüzey ritmi
- Button yoğunluğu ve badge ritmi

İlk pilotta yapılmaması gerekenler:
- Order show detay paneli
- Process Depth markup
- sticky canonical focus panel
- global `.pd-btn` override
- global `body` veya `input` override

## 16. İlk Pilotta Dokunulabilecek Dosya Listesi

İlk pilot `/admin/orders` için güvenli dosyalar:

- `public/css/prodelya-admin.css`
- `resources/views/admin/orders/index.blade.php`

Gerekirse, ama tercihen ikinci aşamada:
- `resources/views/admin/orders/partials/*` varsa yalnız listeye bağlı olanlar

Not:
- Bu pilotta `show` ekranı dışarıda kalmalıdır.

## 17. İlk Pilotta Dokunulmaması Gereken Dosyalar

İlk pilotta dokunulmaması gerekenler:

- `resources/views/admin/orders/show.blade.php`
- `app/Http/Controllers/Admin/OrderController.php`
- `app/Services/OrderShowSummaryService.php`
- `app/Services/OrderListSummaryService.php`
- `resources/views/layouts/prodelya-admin.blade.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `resources/views/admin/companies/show.blade.php`
- `resources/views/admin/current-accounts/show.blade.php`
- `resources/views/admin/stock-purchases/create.blade.php`
- `resources/views/admin/catalog/local-products*`
- `resources/views/admin/catalog/show.blade.php`

Sebep:
- Bu dosyalar ya yüksek inline-style yüzeyi taşıyor ya da yakın dönemde onaylanmış özel scoped görsel katmanlara sahip.

## 18. Net Sonuç

Prodelya UI Standardı v1 için güvenli entegrasyon modeli şudur:

- Global referans CSS dosyasını doğrudan sisteme yükleme: `HAYIR`
- Mevcut `pd-*` primitive’leri replace etme: `HAYIR`
- Token alias + scoped v1 primitive katmanı: `EVET`
- İlk pilot olarak `/admin/orders` index ekranı: `EVET`
- İlk pilotta `/admin/orders/show` ekranına girme: `HAYIR`

Son karar:
- Entegrasyon yapılabilir.
- Ama doğru yöntem “mevcut sistemi ezmek” değil, “mevcut pd sistemi üzerinde kontrollü, scoped, fazlı standardizasyon” olmalıdır.

## 19. Orders UI v1 Pilot Acceptance Addendum (2026-07-20)

Orders index pilot implementation tamamlandı ve aşağıdaki regression kapıları PASS oldu:
- `php artisan view:cache`
- `PromotionQuoteAndOrderIndexHeaderPanelTest`
- `OrderListTabCountersTest`
- broad `Order`
- broad `PromotionQuote`
- `AdminSmokeTest`

Ancak aynı gün içindeki manual visual acceptance turu `MANUAL PASS` ile kapatılamadı.

Exact blocker:
- controllable authenticated browser session could not be established
- Chrome/browser control path failed with `node_repl kernel exited unexpectedly`
- diagnostic: `windows sandbox failed: helper_unknown_error: apply deny-read ACLs`
- direct HTTP probe to `http://saklimavi.prodelya_core.test/admin/orders` returned `302`, so browserless request could not substitute for authenticated visual proof

Sonuç:
- Pilot teknik olarak scoped ve test-stable durumda
- Ancak screenshot destekli gerçek browser kabulü alınmadan final manual PASS verilemez
- Current status: `CODE / TEST PASS, MANUAL VISUAL ACCEPTANCE BLOCKED`
