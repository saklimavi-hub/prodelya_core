# Canlıya Çıkış Kontrol Listesi

Bu doküman canlı ortama geçmeden önce kontrol edilmesi gereken üretim hazırlık adımlarını özetler. Secret değerler bu dokümana yazılmaz; yalnız hangi alanın doğrulanacağı belirtilir.

## A) `.env` Canlı Ortam Kontrol Listesi

- `APP_ENV=production` doğrulandı mı?
- `APP_DEBUG=false` ayarlı mı?
- `APP_URL` gerçek merkezi alan adına işaret ediyor mu?
- `ASSET_URL` canlı alan adıyla uyumlu mu?
- `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` canlı kullanım için uygun mu?
- `SESSION_DOMAIN` üretim alan adıyla uyumlu mu?
- `SESSION_SECURE_COOKIE=true` açık mı?
- `MAIL_MAILER=log` yerine gerçek SMTP sürücüsü tanımlandı mı?
- `DB_*` değerleri canlı veritabanı için tanımlı mı?

## B) Domain / DNS / SSL Kontrol Listesi

- Merkezi alan adı DNS kaydı hazır mı?
- `www` ve `app` varyasyonları doğru hedefe yönleniyor mu?
- SSL sertifikası merkezi alan adlarında aktif mi?
- Zorunlu HTTPS davranışı kontrol edildi mi?
- Reverse proxy veya load balancer varsa `https` başlıkları doğru geçiyor mu?

## C) Super Admin Merkezi Alan Adı Kontrolü

- Super Admin yalnız merkezi alan adlarında açılıyor mu?
- Tenant panel adreslerinden Super Admin ekranları açılmıyor mu?
- `central.access` koruması canlı host matrisiyle uyumlu mu?

## D) Abone Firma Panel Adresi Kontrolü

- Yeni Abone Firma için panel alt alan adı doğru çalışıyor mu?
- Panel giriş yönlendirmesi merkezi host üzerinden doğru tenant adresine gidiyor mu?
- Ayrılmış hostlar tenant olarak çözümlenmiyor mu?

## E) Customer Portal Alan Adı Kontrolü

- `portal_domain` tanımlı tenantlarda müşteri portalı doğru alan adından açılıyor mu?
- `custom_domain` ve `portal_domain` çakışmıyor mu?
- Müşteri portalı linkleri canlı hostlarla uyumlu mu?

## F) Queue / Zamanlayıcı Notu

- Queue worker canlı sunucuda ayrı süreç olarak tanımlandı mı?
- `schedule:run` veya eşdeğer cron satırı eklendi mi?
- Product Data Hub senkron zamanlaması doğrulandı mı?
- Başarısız işler izleme prosedürü hazır mı?
- Örnek cron satırı yalnız referans olarak doğrulandı mı?
  - `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`
- Queue worker supervisor örneği operasyon notuna eklendi mi?
  - `php artisan queue:work --sleep=3 --tries=3 --timeout=120`
- `prodelya:heartbeat-scheduler` sinyali dashboard’da son çalışma zamanı ile izleniyor mu?
- `prodelya:heartbeat-queue-worker` sinyali worker sürecinden bağımsız izleme amaçlı tanımlandı mı?
- Product Data Hub saatlik / günlük / haftalık akışlarının heartbeat karşılıkları gözden geçirildi mi?
- Başarısız işler için retry işlemi otomatik değil, manuel onaylı prosedür olarak belirlendi mi?
- Local, Plesk ve production ortamlarda gerçek PHP binary ve gerçek proje yolu ayrıca doğrulandı mı?

## G) Yedekleme Notu

- Uygulama yedekleme kaynağı net tanımlandı mı?
- Son yedek yaşı ve saklama politikası kontrol edildi mi?
- Geri yükleme prosedürü ayrı olarak test edildi mi?
- Veritabanı yedeği canlı plana dahil mi?
- `storage/app` içeriği ayrı olarak yedekleniyor mu?
- Public yüklemeler ve ek dosyalar yedeğe dahil mi?
- Product Data Hub dosyaları ve kategori arşivleri ayrıca kapsanıyor mu?
- Canlıya çıkmadan önce manuel tam yedek prosedürü planlandı mı?
- Yedek şifreleri veya secret değerler bu dokümana yazılmadı mı?

## H) Depolama Notu

- `php artisan storage:link` canlıda çalıştırıldı mı?
- `public/storage` URL smoke testi yapıldı mı?
- PDF dosyaları beklenen görünürlük kuralıyla açılıyor mu?
- Private ek dosya erişimleri doğrudan public URL olmadan korunuyor mu?
- Müşteri görünür dosyalar ve takip linkleri ayrı smoke testlerle doğrulandı mı?
- `storage/logs` yazılabilir mi?
- `bootstrap/cache` yazılabilir mi?
- Disk doluluk uyarısı sistem sağlığında izleniyor mu?
- Product Data Hub image/export disk tanımları kontrol edildi mi?

## I) SMTP Notu

- Tenant bazlı SMTP ayarları girilebiliyor mu?
- SMTP şifreleri maskeli ve şifreli saklanıyor mu?
- Canlı tenant ile ayrı bir teslim testi planlandı mı?
- `MAIL_MAILER` canlıda `smtp` mi?
- `MAIL_HOST`, `MAIL_PORT` ve `MAIL_ENCRYPTION` doğrulandı mı?
- `MAIL_FROM_ADDRESS` canlı alan adıyla uyumlu mu?
- Tenant SMTP ayarları eksiksiz girildi mi?
- Canlı tenant ile kontrollü test gönderimi operasyon planına yazıldı mı?
- Test gönderimi queue/worker üzerinden mi yoksa senkron mu ilerleyecek, not edildi mi?
- SPF / DKIM / DMARC DNS kayıtları ayrıca doğrulandı mı?
- Notification log başarısızlıkları düzenli izleniyor mu?
- WhatsApp link metinleri müşteri-facing güvenli mi?
- Gerçek WhatsApp API kullanılmıyorsa bunun operasyon notu açıkça yazıldı mı?

## J) Product Data Hub İlk Canlı Kontrol Notu

- Kritik kaynak URL ve kimlik doğrulama ayarları manuel gözden geçirildi mi?
- İlk kaynak önizlemesi ve alan eşleştirmesi doğrulandı mı?
- Tedarikçi erişimi verilen tenantlarda kataloga yansıtma beklentisi gözden geçirildi mi?
- İnceleme bekleyen kayıt kuyruğu operasyon ekibi tarafından izleniyor mu?
- Her aktif tedarikçi için canlı önizleme read-only olarak doğrulandı mı?
- Kaynak URL, auth, header ve user-agent ayarları secret göstermeden kontrol edildi mi?
- Zorunlu alan eşlemeleri tamamlandı mı?
- Türkçe karakterler ile fiyat ve stok alanları doğru parse ediliyor mu?
- Kategori bekleyen ürünlerin satış zincirini bloklamadığı teyit edildi mi?
- ProductHubSellableTruthService ile katalog ve teklif fiyatı aynı truth zincirinden okunuyor mu?
- Tedarikçi erişimi açılan Abone Firmalarda projection ve scheduler beklentisi operasyon notuna işlendi mi?
- İlk canlı sync öncesi manuel onay prosedürü hazır mı?
- İlk canlı sync sonrası inceleme kuyruğu ayrıca kontrol edilecek mi?
- Product Data Hub zamanlayıcı heartbeat sinyali dashboard’da görünüyor mu?
- Tedarikçi gizli anahtarları ve tam kaynak yolları bu dokümana yazılmadı mı?

## K) Final Smoke Adımları

- Merkezi alan adında Super Admin giriş smoke testi
- Tenant panel adresi giriş smoke testi
- Public başvuru gönderim smoke testi
- Başvuru dönüşüm önizlemesi smoke testi
- Talep merkezi ve talep detay smoke testi
- Product Data Hub uyarı ekranları smoke testi
- PDF / dosya / depolama bağlantısı smoke testi
- Sistem sağlığı kartları görünürlük smoke testi
- Scheduler heartbeat görünürlük smoke testi
- Kuyruk heartbeat görünürlük smoke testi

## L) Final Production Domain Smoke

### A) Merkezi Alan Adı Smoke

- `https://saklimavi.net` açılıyor mu?
- `https://www.saklimavi.net` beklenen yönlendirmeyi yapıyor mu?
- `https://app.saklimavi.net` gerekiyorsa merkezi uygulama girişine gidiyor mu?
- Super Admin yalnız merkezi hostta açılıyor mu?
- Tenant host üzerinden Super Admin route’ları açılmıyor mu?

### B) Abone Firma Panel Adresi Smoke

- `{tenant}.saklimavi.net` tenant çözümlemesini yapıyor mu?
- Tenant kullanıcı login olabiliyor mu?
- Merkezi hosttan tenant login sonrası doğru panel adresine yönleniyor mu?
- Pasif, askıda ve süresi dolmuş Abone Firma davranışı beklenen gibi mi?

### C) Public Başvuru Smoke

- Public landing açılıyor mu?
- 1 Ay Ücretsiz Dene formu gönderilebiliyor mu?
- Demo Talep formu gönderilebiliyor mu?
- Başvuru Super Admin listesine düşüyor mu?
- Dönüşüm önizlemesi açılıyor mu?

### D) Tenant Onboarding Smoke

- Tenant create prefill çalışıyor mu?
- Panel Yetkilisi kullanıcı oluşuyor mu?
- Abone Firma success ekranı açılıyor mu?
- Onboarding checklist görünür mü?
- Geçici şifre canlı ekranlarda görünmüyor mu?

### E) Talep Merkezi Smoke

- Tenant Talep Merkezi açılıyor mu?
- Paket yükseltme, ek modül ve limit talebi oluşturulabiliyor mu?
- Super Admin Abone Firma Talepleri listesinde görünüyor mu?
- Approved ve apply zinciri test ortamında doğrulandı mı?
- Canlıda apply öncesi manuel onay kuralı operasyon notuna işlendi mi?

### F) Product Data Hub Smoke

- Product Data Hub dashboard uyarıları görünüyor mu?
- Aktif kaynaklar readiness envanterinde görünüyor mu?
- İlk canlı preview manuel kontrol edildi mi?
- Mapping ve Türkçe karakter kontrolü yapıldı mı?
- Fiyat ve stok truth zinciri tenant katalog ile teklif tarafında aynı mı?
- Sync scheduler heartbeat görünüyor mu?

### G) PDF / Dosya / Depolama Smoke

- `public/storage` erişimi çalışıyor mu?
- PDF üretim ve görüntüleme smoke yapıldı mı?
- Private dosyalar public URL’den açılmıyor mu?
- Müşteri görünür dosyalar doğru filtreleniyor mu?
- Storage, log ve cache yazılabilirlik kontrol edildi mi?

### H) Sistem Sağlığı Smoke

- Scheduler heartbeat görünüyor mu?
- Queue heartbeat görünüyor mu?
- Başarısız işler özeti görünüyor mu?
- Yedekleme tazeliği kartı gerçek politika ile uyumlu mu?
- Disk, veritabanı, önbellek ve depolama kartları görünüyor mu?

### I) Tekliften Siparişe Kısa Smoke

- Yeni firma veya cari seçilebiliyor mu?
- Promosyon teklifi oluşturulabiliyor mu?
- Ürün araması Product Data Hub truth fiyat ve stok gösteriyor mu?
- Teklif siparişe dönüşüyor mu?
- İş formu, grafik, tedarik, üretim ve teslimat zinciri temel smoke geçiyor mu?
- PDF ve print çıktıları açılıyor mu?
