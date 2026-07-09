# PH-2D Bekleyen Kontroller Karar Ekrani Raporu

Tarih: 2026-07-09

## Kapsam

`resources/views/super-admin/product-data-hub/product-panel.blade.php` ekrani, teknik teshis agirlikli bir urun panelinden karar odakli bir "Bekleyen Kontroller" yuzeyine donusturuldu.

## Yapilanlar

- Ust bolume karar odakli ozet kartlari eklendi:
  - Yeni Kategori
  - Eksik Alan / Bilgi
  - Supheli Fiyat / Stok
  - Yeni Urun
  - Kaybolan / Pasife Dusen
  - Otomatik Guncellenen Fiyat / Stok
  - Kimlik Guven Problemi
- "Review Kuyrugu" dili "Bekleyen Kontrol Kuyrugu" olarak sadeleştirildi.
- Ana tablo kolonlari operasyon diliyle yeniden kurgulandi:
  - Etkilenen Kayit
  - Kaynak
  - Kontrol Tipi
  - Ne Oldu?
  - Ne Yapilacak?
  - Aksiyon
- Ustte hizli sekme/filtre chipleri eklendi.
- Ana ekrandan hassas teknik bilgiler kaldirildi:
  - `group_code`
  - `supplier_source_id`
  - raw/standard/projection fiyat degerleri
- Teknik baglam yalniz `technical_columns` acikken gorunen ikincil detay alanina tasindi.

## Risk Azaltma Notlari

- Controller veya sync/projection servislerinde davranis degisikligi yapilmadi.
- Migration, queue tablosu, yeni veri akisi veya yikici refactor uygulanmadi.
- Mevcut filtre parametreleri ve route yapisi korunarak dusuk riskli Blade odakli entegrasyon tercih edildi.

## Test Etkisi

- Product panel ve sellable truth test beklentileri yeni karar ekrani diline gore guncellendi.
- Fiyat/cost ayrintilarinin ana ekranda gorunmemesi testlerle kilitlendi.

## Beklenen Sonuc

- Operatör, normal otomatik fiyat/stok akisini gormek zorunda kalmadan sadece karar bekleyen istisnalari hizli ayirt edebilir.
- Teknik teshis ihtiyaci tamamen kaybolmadan ikincil, kontrollu bir alana tasinmis olur.
