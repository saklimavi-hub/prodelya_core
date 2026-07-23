# PRODELYA M12-B0 Grafik Önizleme ve Müşteri Onayı / E-Posta Akışı Raporu

Tarih: 2026-07-20
Durum: READY — GRAPHIC PREVIEW AND CUSTOMER APPROVAL FLOW — MANUAL SMOKE REQUIRED

## Kapsam
Bu turda yalnız grafik detay önizlemesi, public müşteri onay sayfası ve `Müşteri Onayına Gönder` akışının e-posta/doğruluk sözleşmesi ele alındı.

Dokunulan alanlar:
- `app/Http/Controllers/Admin/GraphicCustomerApprovalController.php`
- `app/Http/Controllers/PublicGraphicApprovalController.php`
- `resources/views/admin/graphics/show.blade.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `tests/Feature/AdminGraphicCustomerApprovalActionTest.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`

Dokunulmayan alanlar:
- Grafik index/list ekranları
- İlgisiz modüller
- Global CSS
- Migration / schema
- Staging / commit

## Root Cause Kanıtı

### 1. Ana önizlemede ürün resmi yanlışlıkla grafik gibi kullanılıyordu
Admin grafik detay Blade içinde ana preview değişkenleri ürün fallback’i içeriyordu:
- `selectedAttachment['original_url'] ?? productPreview.original_url`
- `selectedAttachment['preview_url'] ?? productPreview.preview_url`

Bu nedenle attachment yokken ya da yanlış bağlandığında ürün referans görseli ana "Grafik Çalışması" alanına düşüyordu.

### 2. Public grafik önizlemesi secure route yerine inline/ikincil yol kullanıyordu
Public approval controller payload’ı mevcut secure attachment route yerine inline preview akışına yaslanıyordu. Bu da exact customer-visible attachment yerine kırılgan preview davranışı üretiyordu.

### 3. E-posta gönderimi yanlış olumlu mesaj verebiliyordu
Varsayılan mailer yapılandırması:
- `config/mail.php` → `MAIL_MAILER`, default `log`

Notification dispatch zinciri gerçek mailbox teslimi yerine log/preview/pending/failed durumları üretebilir. Buna rağmen admin aksiyonu tek tip başarı mesajı döndürüyordu. Bu, gerçek teslim kanıtı olmadan "gönderildi" algısı üretiyordu.

## Uygulanan Düzeltmeler

### Admin grafik detay
- Ana büyük önizleme yalnız canonical seçili grafik attachment’ından besleniyor.
- Ürün resmi artık ayrı `Ürün Referansı` alanında gösteriliyor.
- Grafik attachment yoksa boş durum gösteriliyor; ürün görseli grafik yerine kullanılmıyor.
- `Büyük Önizleme` artık büyük lightbox/modal akışıyla açılıyor.
- Özet kartlarında müşteri/firma görünür hale getirildi.
- Disabled feature modunda kalan sabit `Müşteri Onayı` label sızıntıları kapatıldı; `Onay Durumu` fallback’i kullanılıyor.

### Public müşteri approval sayfası
- Exact customer-visible graphic, mevcut secure file route üzerinden gösteriliyor.
- Kırık görselde temiz fallback/empty state gösteriliyor.
- Müşteri/şirket adı, sipariş no, iş formu ve ürün bilgileri görünür hale getirildi.
- `Ürün Referansı` ve `Grafik Çalışması` net biçimde ayrıldı.
- Public sayfa Prodelya public visual standardına yakın yeni kart düzeniyle yeniden kuruldu.
- `Orijinal Boyutta Aç` ve büyük modal/lightbox eklendi.

### E-posta / notification doğruluk sözleşmesi
`Admin\GraphicCustomerApprovalController@send` artık notification log sonucuna göre kullanıcıya doğru mesaj veriyor:
- public URL yoksa: warning
- email log yoksa: warning
- skipped: warning
- failed: warning
- pending queue: warning
- `MAIL_MAILER=log` ve preview: warning
- yalnız güvenli preview/sent durumunda kontrollü success

Böylece sistem artık log mailer, eksik recipient, pending queue veya failed send varken gerçek teslim olmuş gibi davranmıyor.

### Public text sanitization
Public approval controller içindeki kırılgan regex bazlı filtre sadeleştirildi:
- hassas terimler string tabanlı taranıyor
- `storage/`, `work-forms/` ve Windows path sızıntısı ayrıca bloklanıyor
- regex compile 500 hatası giderildi

## Test Güncellemeleri

### Güncellenen hedefli test kapsamı
- `PublicGraphicApprovalRouteTest`
  - public sayfa müşteri adını gösteriyor
  - exact secure attachment route response içine bağlanıyor
- `AdminGraphicCustomerApprovalActionTest`
  - `MAIL_MAILER=log` benzeri durumda send aksiyonu success yerine warning davranışını kabul ediyor
- disabled feature guard görünürlüğü korunuyor

## Çalıştırılan Testler
2026-07-20 tarihinde çalıştırıldı:

1. `php artisan test --filter=PublicGraphicApprovalRouteTest --stop-on-failure`
- PASS

2. `php artisan test --filter=PublicGraphicApprovalSecurityTest --stop-on-failure`
- PASS

3. `php artisan test --filter=AdminGraphicCustomerApprovalActionTest --stop-on-failure`
- PASS

## Manuel Smoke İçin Beklenenler
Aşağıdaki manuel doğrulama henüz kullanıcı tarafından yapılmadı:
- Admin grafik detayda ana büyük önizleme yalnız son grafik attachment olmalı
- Ürün resmi yalnız `Ürün Referansı` alanında görünmeli
- Attachment yoksa temiz empty state görünmeli
- `Büyük Önizleme` büyük modal/lightbox açmalı
- Public approval sayfasında exact müşteri-visible grafik secure route ile açılmalı
- Public sayfada broken image olmamalı
- Müşteri/firma adı admin ve public tarafta görünmeli
- `Müşteri Onayına Gönder` sonrası message mailer/log/recipient/queue sonucuyla uyumlu olmalı
- `MAIL_MAILER=log` ise gerçek teslim iddiası olmamalı

## Kalan Notlar
- Bu fazda grafik task list/index çalışmasına geçilmedi.
- Global CSS refactor yapılmadı.
- Staging ve commit intentionally yapılmadı.

## Faz Kapanış Kararı
READY — GRAPHIC PREVIEW AND CUSTOMER APPROVAL FLOW — MANUAL SMOKE REQUIRED
