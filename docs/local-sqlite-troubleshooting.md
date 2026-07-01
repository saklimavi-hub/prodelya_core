# Local SQLite Kilitlenme Notlari

## Sorun Neden Olur?

SQLite tek dosya uzerinden calisir. Local gelistirme ortaminda asagidaki kombinasyon ayni anda kullanildiginda `database is locked` hatasi daha kolay ortaya cikabilir:

- `DB_CONNECTION=sqlite`
- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=database`

Bu durumda `sessions`, `cache`, `jobs`, `failed_jobs` ve uygulama sorgulari ayni SQLite dosyasini paylasir. Super Admin ekranlarinda hata controller sorgularindan once, Laravel session okuma asamasinda bile gorulebilir.

Ozellikle su durumlar kilit riskini artirir:

- coklu tarayici sekmesi acik olmasi
- ayni anda birden fazla istek gonderilmesi
- queue worker veya arka plan islerinin ayni SQLite dosyasini kullanmasi
- cache/session/job tablolari uzerinde es zamanli yazma okunmasi

## Local Icin Onerilen Ayarlar

SQLite ile local gelistirme yaparken su kombinasyon onerilir:

```env
DB_CONNECTION=sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
```

Daha hafif ve tek kullanicili gelistirme icin alternatif:

```env
DB_CONNECTION=sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Notlar:

- `SESSION_DRIVER=file` local SQLite kilit riskini ciddi sekilde azaltir.
- `CACHE_STORE=file` ayni SQLite dosyasi uzerindeki yazma baskisini dusurur.
- `QUEUE_CONNECTION=database` local queue akislarini korur ama hala jobs tablosunu SQLite uzerinden kullanir.
- `QUEUE_CONNECTION=sync` daha hafif gelistirme icin uygundur; arka plan kuyruk davranisini birebir temsil etmez.

Localde merkezi host + tenant subdomain birlikte kullaniliyorsa su ek not onemlidir:

```env
SESSION_DOMAIN=.prodelya_core.test
```

Bu ayar merkezi host (`prodelya_core.test`) ile tenant hostlari (`{tenant}.prodelya_core.test`) arasinda oturum paylasimini kolaylastirir. Aksi halde tenant hostta yapilan giris merkezi Super Admin hostunda gorunmeyebilir.

Eger daha once farkli `SESSION_DOMAIN` ile giris yapildiysa, tarayicida eski `laravel-session` / `XSRF-TOKEN` cookie'leri kalabilir. Bu durumda:

- tarayici cookie'lerini temizle
- oturumu yeniden baslat
- sonra merkezi hosttan yeniden giris yap

## Hangi Kombinasyon Risklidir?

En riskli local kombinasyon:

```env
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Bu kombinasyon local icin calisabilir, ancak Super Admin ekranlari, coklu istek ve session okuma akisi sirasinda `SQLSTATE[HY000]: General error: 5 database is locked` hatasi uretebilir.

## Hangi Kombinasyon Production Icin Kabul Edilebilir?

Production tarafinda beklenen yapi SQLite degil, MySQL / MariaDB gibi gercek bir veritabani motorudur. Bu nedenle su not onemlidir:

- Bu dokuman local gelistirme workaround notudur.
- Production tavsiyesi degildir.
- Production icin `.env.production.example` referans alinmalidir.

## Sorun Yasandiginda Neler Kontrol Edilmeli?

1. `.env` icinde `DB_CONNECTION=sqlite` kullaniliyorsa `SESSION_DRIVER=file` oldugunu dogrula.
2. `CACHE_STORE=file` oldugunu dogrula.
3. Merkezi host ve tenant host birlikte kullaniliyorsa `SESSION_DOMAIN=.prodelya_core.test` benzeri ortak alan adi ayarini kontrol et.
4. Gerekmiyorsa localde tek sekme ile test et.
5. Arka planda ayni veritabanini kullanan ek surec olup olmadigini kontrol et.
6. Laragon veya PHP surecini yeniden baslat.
7. Gerekirse asagidaki komutu kullan:

```bash
php artisan optimize:clear
```

## Beklenen Sonuc

Local SQLite ortaminda session ve cache dosya tabanli kullanildiginda:

- session okuma kaynakli kilitlenme riski azalir
- Super Admin ekranlari daha stabil acilir
- production ayarlari etkilenmeden local gelistirme daha guvenli hale gelir
