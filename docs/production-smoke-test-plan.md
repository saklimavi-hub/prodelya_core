# Canlıya Çıkış Smoke Test Planı

Bu doküman canlıya çıkmadan önce operasyon ekibinin manuel olarak uygulayacağı son smoke adımlarını listeler. Secret, gerçek müşteri verisi ve gerçek sunucu yolu bu dokümana yazılmaz.

## PROD-SMOKE-001 Merkezi Alan Adı

- Test kodu: `PROD-SMOKE-001`
- Test adı: `Merkezi Alan Adı`
- Ön koşul: DNS ve SSL tanımları hazır.
- Adımlar:
  1. `https://saklimavi.net` adresini aç.
  2. `https://www.saklimavi.net` yönlendirmesini kontrol et.
  3. `https://app.saklimavi.net` gerekiyorsa merkezi girişe gidiyor mu kontrol et.
- Beklenen sonuç: Merkezi giriş ekranı açılır ve beklenmeyen tenant çözümlemesi olmaz.
- Not / risk: HTTPS ve yönlendirme farkları sunucu seviyesinde ayrıca doğrulanmalıdır.
- Geçti / Kaldı:

## PROD-SMOKE-002 Super Admin Giriş

- Test kodu: `PROD-SMOKE-002`
- Test adı: `Super Admin Giriş`
- Ön koşul: Platform yöneticisi hesabı hazır.
- Adımlar:
  1. Merkezi hostta giriş yap.
  2. Operasyon Paneli açılıyor mu kontrol et.
  3. Tenant host üzerinden aynı Super Admin adresini açmayı dene.
- Beklenen sonuç: Super Admin yalnız merkezi hostta açılır, tenant hostta erişim engellenir.
- Not / risk: `central.access` ve `super.admin` guard’ları birlikte doğrulanmalıdır.
- Geçti / Kaldı:

## PROD-SMOKE-003 Tenant Panel Giriş

- Test kodu: `PROD-SMOKE-003`
- Test adı: `Tenant Panel Giriş`
- Ön koşul: Aktif bir Abone Firma ve panel kullanıcısı hazır.
- Adımlar:
  1. `{tenant}.saklimavi.net` adresini aç.
  2. Tenant kullanıcı ile giriş yap.
  3. Merkezi hosttan login sonrası doğru panel adresine yönlenmeyi kontrol et.
- Beklenen sonuç: Tenant çözümlemesi doğru çalışır ve yönlendirme HTTPS panel adresine gider.
- Not / risk: Askıda ve süresi dolmuş tenant davranışı ayrı kontrol edilmelidir.
- Geçti / Kaldı:

## PROD-SMOKE-004 Public Başvuru

- Test kodu: `PROD-SMOKE-004`
- Test adı: `Public Başvuru`
- Ön koşul: Public landing erişilebilir.
- Adımlar:
  1. Landing sayfasını aç.
  2. 1 Ay Ücretsiz Dene formunu test verisiyle gönder.
  3. Demo Talep formunu test verisiyle gönder.
- Beklenen sonuç: Başvuru başarıyla oluşur ve Super Admin başvuru listesine düşer.
- Not / risk: Rate limit ve honeypot davranışı da gözlenmelidir.
- Geçti / Kaldı:

## PROD-SMOKE-005 Başvurudan Abone Firmaya Dönüşüm

- Test kodu: `PROD-SMOKE-005`
- Test adı: `Başvurudan Abone Firmaya Dönüşüm`
- Ön koşul: En az bir yeni başvuru mevcut.
- Adımlar:
  1. Super Admin başvuru detayını aç.
  2. Dönüşüm önizlemesini aç.
  3. Success ekranı ve Abone Firma detayını kontrol et.
- Beklenen sonuç: Önizleme ve success akışı çalışır, onboarding checklist görünür.
- Not / risk: Geçici giriş bilgisi değer olarak görünmemelidir.
- Geçti / Kaldı:

## PROD-SMOKE-006 Talep Merkezi

- Test kodu: `PROD-SMOKE-006`
- Test adı: `Talep Merkezi`
- Ön koşul: Aktif bir tenant panel kullanıcısı hazır.
- Adımlar:
  1. Tenant Talep Merkezi ekranını aç.
  2. Paket yükseltme, ek modül veya limit talebi oluştur.
  3. Super Admin Abone Firma Talepleri ekranında kaydı kontrol et.
- Beklenen sonuç: Talep oluşur, listede görünür ve detay ekranına gidilir.
- Not / risk: Canlıda apply öncesi manuel operasyon onayı ayrıca alınmalıdır.
- Geçti / Kaldı:

## PROD-SMOKE-007 Product Data Hub Hazırlığı

- Test kodu: `PROD-SMOKE-007`
- Test adı: `Product Data Hub Hazırlığı`
- Ön koşul: En az bir aktif tedarikçi kaynağı tanımlı.
- Adımlar:
  1. Product Data Hub dashboard ve kaynak ekranını aç.
  2. Readiness envanterinde aktif kaynakları kontrol et.
  3. İlk canlı preview ve mapping doğrulama notlarını işaretle.
- Beklenen sonuç: Kaynaklar görünür, secret sızmaz ve “Kontrol Gerekir” uyarıları dürüst biçimde görünür.
- Not / risk: Bu testte sync, apply veya projection çalıştırılmaz.
- Geçti / Kaldı:

## PROD-SMOKE-008 SMTP Hazırlık

- Test kodu: `PROD-SMOKE-008`
- Test adı: `SMTP Hazırlık`
- Ön koşul: Mail ayarları ve tenant SMTP ekranı erişilebilir.
- Adımlar:
  1. Operasyon Paneli uyarılarını kontrol et.
  2. Tenant SMTP özetini doğrula.
  3. SPF, DKIM ve DMARC kontrol notlarını işaretle.
- Beklenen sonuç: Eksik SMTP alanları görünür, secret değerler görünmez.
- Not / risk: Gerçek mail gönderimi bu dokümanın dışında planlanır.
- Geçti / Kaldı:

## PROD-SMOKE-009 PDF / Dosya Erişimi

- Test kodu: `PROD-SMOKE-009`
- Test adı: `PDF / Dosya Erişimi`
- Ön koşul: En az bir teklif, sipariş veya iş formu kaydı mevcut.
- Adımlar:
  1. `public/storage` smoke erişimini kontrol et.
  2. PDF ve print çıktısını aç.
  3. Private attachment erişiminin doğrudan public URL vermediğini doğrula.
- Beklenen sonuç: Görünür dosyalar açılır, private dosyalar korunur.
- Not / risk: Customer visible filtreleri ayrıca gözlenmelidir.
- Geçti / Kaldı:

## PROD-SMOKE-010 Sistem Sağlığı

- Test kodu: `PROD-SMOKE-010`
- Test adı: `Sistem Sağlığı`
- Ön koşul: Operasyon Paneli erişilebilir.
- Adımlar:
  1. Kuyruk Çalışanı kartını kontrol et.
  2. Zamanlayıcı heartbeat kartını kontrol et.
  3. Başarısız işler, yedekleme ve depolama kartlarını incele.
- Beklenen sonuç: Sağlıklı, Kontrol Gerekir, Kritik veya Bilinmiyor durumları dürüst görünür.
- Not / risk: Bu testte queue, scheduler veya backup komutu çalıştırılmaz.
- Geçti / Kaldı:

## PROD-SMOKE-011 Tekliften Siparişe Kısa Akış

- Test kodu: `PROD-SMOKE-011`
- Test adı: `Tekliften Siparişe Kısa Akış`
- Ön koşul: Tenant tarafında sipariş akışı modülü aktif.
- Adımlar:
  1. Yeni bir promosyon teklifi oluştur.
  2. Ürün aramasında Product Data Hub truth fiyat ve stok değerini kontrol et.
  3. Teklifi siparişe dönüştür.
- Beklenen sonuç: Temel tekliften siparişe akış tamamlanır ve iş formu zinciri oluşur.
- Not / risk: Bu testte canlı müşteri verisi kullanılmamalıdır.
- Geçti / Kaldı:

## PROD-SMOKE-012 Müşteri Portalı

- Test kodu: `PROD-SMOKE-012`
- Test adı: `Müşteri Portalı`
- Ön koşul: Portal kullanıcısı ve görünür kayıtlar hazır.
- Adımlar:
  1. Müşteri giriş ekranını aç.
  2. Teklif, sipariş ve dosya görünürlüğünü kontrol et.
  3. Takip ve onay linklerinin beklenen erişim sınırında kaldığını doğrula.
- Beklenen sonuç: Portal erişimi tenant bazında izole çalışır ve müşteri görünürlüğü kuralları korunur.
- Not / risk: Public token ve dosya erişimleri ayrıca güvenlik checklist’iyle eşleştirilmelidir.
- Geçti / Kaldı:
