# PRODELYA M12-B0.2 — Grafik Büyük Önizleme Render Hotfix Raporu

Tarih: 20 Temmuz 2026
Durum: READY — GRAPHIC LARGE PREVIEW RENDER HOTFIX — MANUAL SMOKE REQUIRED

## Kapsam
Bu fazda yalnız admin grafik detayındaki büyük önizleme modalının görsel render zinciri düzeltildi. E-posta alıcı çözümü yeniden ele alınmadı.

Dokunulan alanlar:
- `resources/views/admin/graphics/show.blade.php`
- `tests/Feature/GraphicPreviewUsesOriginalSourceTest.php`
- `tests/Feature/GraphicLargePreviewContractTest.php`
- `tests/Feature/PublicGraphicFileRouteTest.php`
- `tests/Feature/GraphicAttachmentPreviewRuntimeTest.php`
- `tests/Feature/GraphicShowCompactLayoutTest.php`

Dokunulmayan alanlar:
- mail / recipient resolver
- graphics index
- schema / migration
- global CSS primitive
- staging / commit

## Exact Root Cause
Admin secure file route doğru çalışıyordu; sorun modal render sözleşmesindeydi.

Kanıtlanan durum:
- Modal açılıyordu.
- Büyük önizleme için ayrı viewport / image class sözleşmesi yoktu.
- JS thumbnail veya preview zincirine açık bir binding mantığıyla çalışıyordu.
- Dedicated modal image scaling olmadığı için görsel modal içinde küçük ikon gibi kalabiliyordu.

## Secure Route Doğrulaması
Admin preview route:
- Named route: `admin.work-forms.attachments.preview`
- Beklenen davranış: raw file stream
- Header sözleşmesi:
  - `200 OK`
  - `Content-Type: image/*`
  - `Content-Disposition: inline`
  - `X-Content-Type-Options: nosniff`
  - `Cache-Control: private, no-store, max-age=0`

Public secure file quick regression:
- Customer-visible attachment route `200` dönüyor.
- `inline` disposition korunuyor.

## Uygulanan Dar Düzeltme
### 1. Canonical source binding
Büyük önizleme artık thumbnail veya preview `img.src` üzerinden değil, yalnız canonical secure original URL üzerinden açılıyor.

Yeni bağ:
- `data-full-src="...original secure url..."`

Kullanılan JS:
- `const fullSrc = trigger.getAttribute('data-full-src');`
- `modalImage.src = fullSrc;`

### 2. Dedicated modal image contract
Yeni dedicated modal contract:
- `.pd-graphic-lightbox__viewport`
- `.pd-graphic-lightbox__image`
- `.pd-graphic-lightbox__status`

Modal image artık thumbnail class mantığını paylaşmıyor.

### 3. Viewport scaling
Modal görseli artık viewport alanını kullanacak şekilde ölçekleniyor:
- `width: 100%`
- `height: 100%`
- `max-width: calc(96vw - 72px)`
- `max-height: calc(92vh - 120px)`
- `object-fit: contain`

### 4. Load / error fallback
Durumlar ayrıştırıldı:
- yükleniyor
- yüklendi
- yüklenemedi

Yüklenememe mesajı:
- `Grafik görseli yüklenemedi. Orijinal dosyayı açmayı deneyin.`

### 5. Original open parity
`Orijinal Boyutta Aç` aynı secure original URL’yi kullanıyor. Modal ile farklı URL üretilmiyor.

## Test Attribution
Broad `Graphic` filtresinde kalan tek failure uygulama bug’ı değildi; stale metin beklentisiydi.

Eski beklenti:
- `Kısayollar`

Mevcut canonical UI metni:
- `Hızlı İşlemler`

Bu nedenle yalnız test katmanı hizalandı.

## Çalıştırılan Testler
Geçen hedefli testler:
- `php artisan view:cache`
- `php artisan test --filter=GraphicLargePreview --stop-on-failure`
- `php artisan test --filter=GraphicPreviewUsesOriginalSourceTest --stop-on-failure`
- `php artisan test --filter=PublicGraphicFile --stop-on-failure`
- `php artisan test --filter=PublicGraphicApproval --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

Broad doğrulamalar:
- php artisan test --filter=Graphic --stop-on-failure PASS
- php artisan test --filter=AdminSmokeTest --stop-on-failure PASS

## Worktree Notu
Worktree zaten geniş çapta dirty idi. Bu fazda yalnız grafik büyük önizleme hotfix kapsamındaki dosyalara dar müdahale yapıldı. Staging veya commit yapılmadı.

## Manuel Smoke Checklist
Admin grafik detayında:
- `Büyük Önizleme` açılır.
- Görsel küçük ikon gibi kalmaz.
- Modal alanını anlamlı biçimde doldurur.
- En-boy oranı korunur.
- `Orijinal Boyutta Aç` secure original URL ile açılır.

Public quick check:
- Grafik görseli açılır.
- Secure file route `200` döner.
- `404` yoktur.
