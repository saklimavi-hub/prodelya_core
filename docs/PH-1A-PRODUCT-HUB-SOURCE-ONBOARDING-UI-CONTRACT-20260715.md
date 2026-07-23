# PH-1A Product Hub Source Onboarding UI Contract — 2026-07-15

## Scope

Bu belge yalnız `docs/ui-previews-new/prodelya_product_hub_kaynaklar_ilk_aktarim_onizleme.html` önizlemesinin UI sözleşmesini tanımlar. Production kodu, route, controller, test, DB write, sync/import/apply, staging ve commit bu fazın dışındadır.

## Confirmed foundations

- Kaynak ekranı PH-0 freeze sınıflandırmasına göre yalnız `Kaynaklar ve İlk Aktarım` alanını temsil eder.
- Sağ sticky özet içinde viewport başına tek primary CTA bulunur.
- `İlk Aktarımı Onayla` aksiyonu gerçek mutation değildir; `PLANLANAN PH-1B` disabled durumunda kalır.
- Field Mapping hem kaynak içi aktif step olarak hem de global `Eşlemeler` indeksine bağlanan yardımcı yüzey olarak konumlanır.
- Stage, Apply, Project Dirty ve benzeri ileri aksiyonlar günlük kullanıcı ekranında görünmez; `Gelişmiş İşlemler` altında kalır.

Windsurf’ta şu dosyayı uygula:

docs/prompts/PRODELYA_V1_10.17.2_PH1B1_PRODUCT_HUB_SOURCE_ONBOARDING_PRODUCTION_PROMPT.md

PH-1A3 standalone preview bağımsız headless Chromium kontrolünde console/page error olmadan çalıştı; PH-1B1 production UI entegrasyonuna geç. Ancak yeni first-import approve route/orchestration oluşturma; bu PH-1B2’de exact audit sonrası yapılacak. Gerçek supplier_sources verisini kullan, sahte KPI/source üretme. Source state resolver ile eksik kaynak, bağlantı kontrolü, preview, mapping, aktarıma hazır ve aktif durumlarını üret. Her source için tek primary CTA. İlk aktarıma hazır durumda disabled kullanıcı-facing mesaj göster; internal PH kodunu production UI’da gösterme. Preview/test no-write sözleşmesini ve operation log nüansını koru. Gross liste/net referans/currency ayrımını fiyat haritasına göre göster. Source-specific mapping mevcut route’a bağlansın, global mapping index ayrı kalsın. Stage/Apply/Project Dirty günlük UI’dan advanced accordion’a taşınsın; backend davranışları değişmesin. Standalone CSS’i kopyalama, mevcut Prodelya admin layout/CSS tokenlarını kullan. Source create/edit credential güvenliğini koru. Hedefli+broad testleri ve manuel smoke durumunu raporla. Staging/commit yapma.## Source profile evidence

- `ETKIN`, `AKDENIZ`, `ILPEN` ve `YENI-NESIL` varsayılan XML ailesindedir.
- `POZITRON_JSON` gerçek JSON kaynak profili olarak ayrı gösterilir.
- Akdeniz için preview düzeyinde gross/net ayrımı korunur:
  - `listefiyati -> list_price`
  - `listefiyatkapali -> closed_list_price`
  - `netfiyat -> purchase_price`
  - `kur -> currency`
- Yeni Nesil ve Pozitron fiyat alanları tahmin edilmeden, mevcut config alias kanıtına göre özetlenir.

## Interaction contract

- Arama kutusu kaynak adı, tedarikçi ve format üzerinde filtreler.
- Format filtresi `Tüm formatlar / XML / JSON` seçenekleriyle çalışır.
- Hazırlık filtresi `Tüm / %80+ / %60+` eşiklerini uygular.
- Filtre sonrası seçili kayıt listede yoksa ilk uygun kayıt güvenli biçimde seçilir.
- Sonuç yoksa kaynak listesi, ana panel ve sağ özet crash olmadan boş durum gösterir.

## Stepper contract

- Altı adım sabittir: `Kaynak Bilgileri`, `Bağlantı Kontrolü`, `5-10 Ürün Önizleme`, `Alan Eşleme`, `İlk Aktarım Onayı`, `Güncelleme Ayarları`.
- Yalnız aktif adımın ayrıntısı açılır.
- Tamamlanan adımlar kısa özet kartlarında görünür.
- Kilitli adımlar disabled durumdadır; tıklanabilir değildir.
- Blade veya preview içinde yeni workflow hesabı yoktur; görünüm mevcut PH-1A kararını sunar.

## 17. PH-1A3 runtime recovery

Bu turda PH-1A2 raporundaki görsel kararlar korunarak üç kritik runtime kusuru kapatıldı.

- `dataLabel` her kaynak senaryosunda açıkça `TEMSİLİ SENARYO` olarak tanımlandı ve render tarafında fallback bırakıldı.
- `links` her senaryoda dizi sözleşmesine bağlandı; boş durumda `Yardımcı işlem yok.` metni gösteriliyor.
- Aktif olmayan adımlar için guard’lı ve disabled stepper modeli uygulandı; `detail` yalnız aktif adımda okunuyor, eksik kayıtta fallback detail dönüyor.
- Kullanıcı-facing metinler gerçek UTF-8 Türkçe karakterlerle geri yazıldı: `Tedarikçi`, `İlk Aktarım`, `Önizleme`, `Bağlantı Kontrolü`, `Eşlemeler`, `Güncelleme Ayarları`.
- Arama, format ve hazırlık filtreleri boş sonuç halinde de güvenli kalacak şekilde `renderAll()` akışında guard ile korunuyor.

## Runtime verification

- Static initial-render path audit: PASS
- Undefined/null görünür çıktı guard’ları: PASS
- Source selection fallback: PASS
- Locked step safety: PASS
- Search/filter empty state safety: PASS
- Single primary CTA: PASS
- First-import disabled state: PASS
- Browser console: PENDING

`Browser console` maddesi açıkça `PENDING` bırakıldı çünkü bu oturumda Chrome runtime bağlantısı denendiğinde `windows sandbox failed: helper_unknown_error: apply deny-read ACLs` hatası üretildi; bu nedenle gerçek tarayıcı PASS uydurulmadı.

## Gate result

PREVIEW RUNTIME VERIFIED — PRODUCT HUB SOURCES AND FIRST IMPORT FLOW — PH-1B GATE OPEN

Initial render: PASS
Undefined/null safety: PASS
Source selection: PASS
Stepper safety: PASS
Search/filter: PASS
Turkish UTF-8: PASS
Single primary CTA: PASS
First-import disabled state: PASS
Browser console: PENDING
Staging/commit: NONE
