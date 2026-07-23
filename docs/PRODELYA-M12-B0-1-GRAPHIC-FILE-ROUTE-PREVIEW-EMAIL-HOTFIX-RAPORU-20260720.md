# PRODELYA M12 B0.1 Graphic File Route / Preview / Email Hotfix Raporu

Tarih: 20 Temmuz 2026
Durum: READY — GRAPHIC FILE DELIVERY AND EMAIL RECIPIENT HOTFIX — MANUAL SMOKE REQUIRED

## Kapsam
Bu hotfix yalnız aşağıdaki exact blocker'ları hedefledi:
- Admin büyük önizlemede küçük thumbnail/preview yerine canonical güvenli grafik kaynağının kullanılması
- Public grafik dosya rotasında yanlış `/takip/is-formu/dosya/{id}` varsayımı yerine gerçek token+attachment named route sözleşmesinin kullanılması
- Müşteri e-posta alıcısı çözümünde yalnız primary contact e-postasına bağımlı kalınması nedeniyle "uygun alıcı yok" durumunun düzeltilmesi
- Mail sonucu mesajlarında `log`, `queued/pending`, `failed`, `sent`, `skipped` ayrımının dürüst ve kullanıcı-facing biçimde gösterilmesi

Kapsam dışı:
- Grafik index
- İlgisiz modüller
- Schema/migration
- Global CSS
- Staging/commit

## Root Cause Özeti

### 1. Büyük önizleme yanlış kaynağı kullanıyordu
`app/Services/GraphicModuleDataBuilder.php` içinde preview/original/open URL alanları tek preview helper'a bağlanmıştı. Bunun sonucu olarak admin detay ve public yüzeylerde büyük önizleme kontratı thumbnail/preview URL ile karışıyordu.

### 2. Public grafik dosya route sözleşmesi yanlış varsayılıyordu
Gerçek route:
- `public.work-forms.attachments.show`
- Parametreler: `token` + `attachment`

Yanlış `/takip/is-formu/dosya/20` yapısı token içermediği için 404 üretir. Güvenli dosya erişimi work form tracking token'ı ile attachment id birlikte ister.

### 3. E-posta alıcısı çözümü fazla dar kalıyordu
`app/Services/GraphicApprovalRequestService.php` request oluştururken yalnız `getPrimaryContact()` üzerinden contact_email dolduruyordu. Primary contact e-postası boş, fakat firma e-postası dolu olduğunda notification katmanına geçerli recipient taşınmıyordu ve kanal "uygun alıcı yok" diye skip oluyordu.

### 4. E-posta durum mesajı fazla iddialıydı
Admin aksiyonu, gerçek SMTP gönderimi yapılmamış olsa bile kullanıcıya başarı/sent benzeri algı oluşturabiliyordu. Özellikle `MAIL_MAILER=log` durumunda gerçek inbox teslimi yokken dürüst durum mesajı gerekiyordu.

## Yapılan Düzeltmeler

### Grafik preview/original/open URL ayrımı
Dosya: `app/Services/GraphicModuleDataBuilder.php`

Ayrılan helper'lar:
- `resolveAttachmentPreviewUrl()`
- `resolveAttachmentOriginalUrl()`
- `resolveAttachmentAdminUrl()`

Düzeltilen alanlar:
- `last_visual_original_url` artık original helper kullanıyor
- operation card `attachment.original_url` artık original helper kullanıyor
- operation card `attachment.open_url` admin/original secure route helper kullanıyor
- attachment list `open_url` admin/original secure route helper kullanıyor

Not:
Aynı güvenli admin route bugün preview/original için ortak fiziksel file delivery noktası olarak kullanılmaya devam ediyor; ancak alan sözleşmeleri artık ayrışmış durumda ve UI doğru semantic alanı tüketiyor.

### Public approval sayfasında doğru grafik kaynağı
Dosya: `resources/views/public/graphics/approval/show.blade.php`

- Ana görsel artık `attachment_original_url ?? attachment_preview_url` ile bağlanıyor
- `Büyük Önizleme` lightbox kaynağı `attachment_original_url` üzerinden açılıyor
- Görsel olmayan durumda da güvenli original/preview fallback zinciri korunuyor

### Recipient çözümünün normalize edilmesi
Dosya: `app/Services/GraphicApprovalRequestService.php`

Yeni çözüm zinciri:
1. Explicit request override
2. Primary contact
3. İlk uygun company contact
4. Company record email/phone
5. Work form customer snapshot
6. `NotificationRecipientResolver` fallback sonucu

Ek iyileştirmeler:
- E-posta normalize/validate ediliyor
- Telefon normalize ediliyor
- `meta_json.recipient_source` ile gerçek kazanan kaynak tutuluyor

### Kanal alias ve durum ayrımı
Dosya: `app/Services/Notifications/NotificationEventService.php`

Normalize edilen alias örnekleri:
- `mail`, `e-posta`, `eposta` → email
- `whatsapp`, `whatsapp-link`, `whatsapp_link` → whatsapp link
- `internal`, `bildirim`, `ic/iç` → internal

Bu sayede template/channel tanımı farklı yazılsa da recipient missing guard canonical kanal adıyla çalışıyor.

### Admin gönderim sonucu mesajlarının dürüstleştirilmesi
Dosya: `app/Http/Controllers/Admin/GraphicCustomerApprovalController.php`

Kullanıcı-facing sonuçlar artık ayrışıyor:
- `log` mailer: gerçek inbox'a gitmediği açıkça söyleniyor
- `pending`: kuyrukta bilgisi veriliyor
- `failed`: başarısız gönderim bilgisi veriliyor
- `sent`: gerçek gönderim bilgisi veriliyor
- `skipped/no recipient`: uygun e-posta bulunamadı bilgisi veriliyor

Ek güvenlik:
- Recipient e-posta maskeleniyor

## Route ve Güvenlik Kanıtı

### Güvenli public attachment route
Dosya: `routes/web.php`

Gerçek named route:
- `public.work-forms.attachments.show`

Gerçek path sözleşmesi:
- `/takip/is-formu/{token}/dosya/{attachment}`

Controller:
- `App\Http\Controllers\PublicWorkFormAttachmentController@show`

Guard'lar:
- Work form aktif olmalı
- Public tracking token eşleşmeli
- Attachment aynı work form'a ait olmalı
- Attachment `customer_visible` olmalı

## Test Kanıtları

Aşağıdaki hedefli kapılar 20 Temmuz 2026 tarihinde PASS alındı:

- `php artisan test --filter=GraphicApprovalRequestCoreTest --stop-on-failure`
  - PASS
  - 7 test / 63 assertion

- `php artisan test --filter=AdminGraphicCustomerApprovalActionTest --stop-on-failure`
  - PASS
  - 5 test / 56 assertion

- `php artisan test --filter=PublicGraphicApprovalRouteTest --stop-on-failure`
  - PASS
  - 4 test / 57 assertion

- `php artisan test --filter=PublicGraphicApprovalSecurityTest --stop-on-failure`
  - PASS
  - 2 test / 28 assertion

- `php artisan test --filter=GraphicPreviewUsesOriginalSourceTest --stop-on-failure`
  - PASS
  - 1 test / 7 assertion

## Ek Test Attribution

Bu turda özellikle aşağıdaki senaryolar kapatıldı:
- Primary contact e-postası boşken firma e-postasına fallback
- Public approval route üzerinde token sızıntısı olmadan güvenli attachment erişimi
- Büyük önizleme alanının canonical graphic attachment semantic alanını kullanması
- Log mailer ortamında yanlış `success` algısının test katmanında warning olarak hizalanması

## Değişen Dosyalar
- `app/Services/GraphicModuleDataBuilder.php`
- `app/Services/GraphicApprovalRequestService.php`
- `app/Services/Notifications/NotificationEventService.php`
- `app/Http/Controllers/Admin/GraphicCustomerApprovalController.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `tests/Feature/GraphicApprovalRequestCoreTest.php`
- `tests/Feature/AdminGraphicCustomerApprovalActionTest.php`

## Manuel Smoke İçin Beklenenler
Aşağıdakiler browser ile ayrıca doğrulanmalı:
- Admin büyük önizleme canonical latest graphic attachment'ı açıyor mu
- Public approval sayfasında kırık görsel yerine gerçek secure graphic görünüyor mu
- Public dosya linki gerçek token+attachment route'u ile 404 vermeden açılıyor mu
- Recipient mevcut tenant/customer datasında artık doğru çözümleniyor mu
- `MAIL_MAILER=log`, SMTP, queued ve failed ayrımı kullanıcı mesajında doğru yansıyor mu

## Sonuç
READY — GRAPHIC FILE DELIVERY AND EMAIL RECIPIENT HOTFIX — MANUAL SMOKE REQUIRED
