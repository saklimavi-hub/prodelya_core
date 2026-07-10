# PRODELYA MASTER UYGULAMA YOL HARİTASI AUDIT RAPORU — 2026-07-10

## 1. Yönetici Özeti

- Bu çalışma read-only master audit iç tutarlılık düzeltmesidir.
- Uygulama kodu değiştirilmedi.
- Git staging yapılmadı.
- Git commit yapılmadı.
- Tek değiştirilen dosya bu rapordur.
- `docs/ui-previews/**/*.html` altında doğrulanan gerçek benzersiz preview sayısı `145`tir.
- Preview sınıf dağılımı:
  - `A`: `7`
  - `B`: `21`
  - `C`: `87`
  - `D`: `5`
  - `E`: `5`
  - `F`: `2`
  - `G`: `3`
  - `H`: `6`
  - `I`: `9`
- `V1 adayı = A + B = 28`
- Şimdi yapılan çalışma:
  - `Master Audit internal consistency fix ve audit checkpoint’i`
- Audit sonrası ilk kod fazı:
  - `Worktree / Checkpoint Stabilization`
- İlk gerçek feature fazı:
  - `Currency Core`

## 2. İnceleme Kapsamı

- `docs/ui-previews/**/*.html`
- `docs/**/*.md`
- `routes/web.php`
- `config/admin_menu.php`
- `config/prodelya.php`
- `config/prodelya_modules.php`
- `config/prodelya_permissions.php`
- `config/prodelya_product_data_hub.php`
- `config/prodelya_localization.php`
- `resources/views/admin/**`
- `resources/views/super-admin/**`
- `resources/views/public/**`
- `resources/views/layouts/**`
- `public/css/prodelya-admin.css`
- `app/Http/Controllers/**`
- `app/Services/**`
- `app/Models/**`
- `app/Http/Middleware/**`
- `database/migrations/**`
- `tests/Feature/**`
- `tests/Unit/**`
- `git status`
- `git diff`
- `git log`
- route list

## 3. Git ve Worktree Mevcut Durumu

### 3.1 Repo sinyalleri

- Route sayısı: `394`
- Preview sayısı: `145`
- Production ekran ailesi: `41`

### 3.2 Mevcut worktree yüzeyleri

- Modified:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- Untracked / diğer yüzeyler:
  - geçici `.tmp_*` blade dosyaları
  - untracked docs raporları
  - `docs/ui-previews/`
  - diğer untracked test ve doküman dosyaları
- Staged alan: boş

### 3.3 Stabilization sınıfları

Faz 1 içinde bu worktree yüzeyleri tek committe toplanmamalı; önce şu sınıflara ayrılmalıdır:

- `A. Tamamlanmış checkpoint kalıntısı`
- `B. Güvenli küçük cleanup`
- `C. Davranış değişikliği içeren ayrı feature hunkı`
- `D. Geçici/backup dosya`
- `E. Doküman/preview referansı`
- `F. Production karşılığı belirsiz test değişikliği`
- `G. Bir sonraki faza bırakılacak değişiklik`

Public Graphic Approval cleanup alanı bu stabilization kapsamına tekrar alınmaz.

## 4. Mevcut Production Olgunluk Özeti

- Tenant / paket / modül / feature / usage limit omurgası var
- teklif / sipariş / müşteri onayı / siparişe dönüşüm var
- sipariş revizyonu ve tekrar sipariş çekirdeği var
- grafik / tedarik / üretim / fason / teslimat zinciri var
- current account / finance çekirdeği var
- notification center var
- customer portal ve public site var
- Product Data Hub raw / standard / projection çekirdeği var
- tenant katalog projection + quote search bağlantısı var

Eksik ana foundation’lar:

- `Currency Core`
- `Usage Depth Core`
- `Shared Active Step / Lock Service`

## 5. UI Önizleme Envanteri

### 5.1 Preview sayımı ve benzersizlik doğrulaması

- Gerçek benzersiz HTML preview sayısı: `145`
- Alt klasör bulunmadı; bütün dosyalar `docs/ui-previews/` kökündedir
- Bu nedenle göreli yol, dosya adının kendisidir
- Preview matrisi içinde her dosya yalnız bir kez bulunur
- Her dosya yalnız bir ana sınıfa atanmıştır
- Gerçek tekrar satırları temizlenmiştir

Özellikle doğrulanan tekil kayıtlar:

- `prodelya_forma_preset_editor_onizleme.html`
- `prodelya_hizli_perakende_satis_onizleme.html`
- `prodelya_product_hub_sade_akis_onizleme.html`

### 5.2 Karar toplamları

| Karar | Anlamı | Adet |
| --- | --- | ---: |
| `A` | V1 için güçlü fikir adayı; doğrudan implementation kararı değil | `7` |
| `B` | V1 için kısmi fikir kaynağı; bütünüyle uygulanmaz | `21` |
| `C` | Zaten production’da mevcut | `87` |
| `D` | V1.1’e bırakılmalı | `5` |
| `E` | V2 Matbaa referansı | `5` |
| `F` | V3 Dieline referansı | `2` |
| `G` | Uygulanmamalı | `3` |
| `H` | Birleştirilmeli | `6` |
| `I` | Eski/deprecated/arşiv | `9` |

Matematik:

- `A + B + C + D + E + F + G + H + I = 145`
- `V1 adayı = A + B = 28`

### 5.3 Önizleme envanteri

| Dosya | Ekran Ailesi | Production Karşılığı | Durum | Karar | Hedef Sürüm |
| --- | --- | --- | --- | --- | --- |
| `prodelya_16_sayfa_forma_montaj_duzeltme_onizleme.html` | Matbaa / montaj | Matbaa imposition | Future | `E` | V2 |
| `prodelya_16_sayfa_forma_yon_tasirma_duzeltilmis_onizleme.html` | Matbaa / montaj | Matbaa imposition | Future | `E` | V2 |
| `prodelya_abone_firma_cari_hesap_onizleme.html` | SaaS cari | Tenant SaaS cari hesabı | Deferred | `D` | V1.1 |
| `prodelya_admin_dashboard_kompakt_onizleme.html` | Admin dashboard | Dashboard/work queue | Production | `C` | V1 |
| `prodelya_admin_dashboard_kompakt_prodelya_css_onizleme.html` | Admin dashboard | Dashboard/work queue | Production | `C` | V1 |
| `prodelya_admin_dashboard_ultra_kompakt_onizleme.html` | Admin dashboard | Dashboard UX varyantı | Merge | `H` | V1 |
| `prodelya_admin_yonetim_paneli_dashboard_onizleme.html` | Admin dashboard | Admin dashboard | Production | `C` | V1 |
| `prodelya_baglantili_operasyon_sayfalari_onizleme.html` | Ortak operasyon | Ortak operasyon yönlendirme | Direct candidate | `A` | V1 |
| `prodelya_baski_ayari_detay_kompakt_onizleme.html` | Print settings | Tenant print settings | Production | `C` | V1 |
| `prodelya_baski_ayarlari_genel_liste_kompakt_onizleme.html` | Print settings | Tenant print settings | Production | `C` | V1 |
| `prodelya_basvuru_abone_firmaya_donusturme_onizleme.html` | Tenant lifecycle | Signup conversion | Production | `C` | V1 |
| `prodelya_bicak_kutuphanesi_dieline_eslesme_onizleme.html` | Dieline | Dieline library | Future | `F` | V3 |
| `prodelya_bildirim_merkezi_onizleme.html` | Notifications | Notification center | Production | `C` | V1 |
| `prodelya_cari_detay_buton_tab_onizleme.html` | Cari detail | Current account detail | Production | `C` | V1 |
| `prodelya_cari_detay_ekstre_on_muhasebe_onizleme.html` | Cari detail | Current account statement | Production | `C` | V1 |
| `prodelya_cari_detay_kompakt_tab_onizleme.html` | Cari detail | Current account detail | Production | `C` | V1 |
| `prodelya_cari_detay_sade_tab_onizleme.html` | Cari detail | Current account detail | Production | `C` | V1 |
| `prodelya_cari_ekstre_pdf_referans_onizleme.html` | Cari PDF | Statement export | Production | `C` | V1 |
| `prodelya_cari_firma_bagli_surec_onizleme.html` | Cari/firma | Company/current account bridge | Production | `C` | V1 |
| `prodelya_cari_firma_toparlanmis_onizleme.html` | Cari/firma | Company/current account bridge | Partial | `B` | V1 |
| `prodelya_cari_hareket_fisi_modal_onizleme.html` | Cari hareket | Manual transaction modal | Production | `C` | V1 |
| `prodelya_cari_hesap_hareketleri_on_muhasebe_onizleme.html` | Cari hareket | Current account transactions | Production | `C` | V1 |
| `prodelya_cari_kart_firma_detay_onizleme.html` | Cari detail | Current account detail | Production | `C` | V1 |
| `prodelya_cari_kart_olustur_final_onizleme.html` | Cari create | Current account create | Production | `C` | V1 |
| `prodelya_cari_kartlar_liste_on_muhasebe_onizleme.html` | Cari list | Current account list | Production | `C` | V1 |
| `prodelya_creative_promosyon_teklifleri_preview (1).html` | Quote list | Creative duplicate | Archive | `I` | Arşiv |
| `prodelya_creative_promosyon_teklifleri_preview.html` | Quote list | Quote list creative varyantı | Partial | `B` | V1 |
| `prodelya_fason_uretim_formu_a4_grafik_odakli_onizleme.html` | Fason form | Outsource form | Partial | `B` | V1 |
| `prodelya_fason_uretim_formu_a4_onizleme.html` | Fason form | Outsource form | Production | `C` | V1 |
| `prodelya_fason_uretim_formu_a4_sade_onizleme.html` | Fason form | Outsource form varyantı | Merge | `H` | V1 |
| `prodelya_finans_cari_detay_onizleme.html` | Finans/cari | Finance/current account detail | Production | `C` | V1 |
| `prodelya_finans_tahsilat_modulu_onizleme.html` | Finans | Finance/tahsilat | Production | `C` | V1 |
| `prodelya_forma_preset_editor_onizleme.html` | Matbaa preset | Forma preset editor | Future | `E` | V2 |
| `prodelya_grafik_detay_akis_onizleme.html` | Grafik detail | Graphic flow detail | Production | `C` | V1 |
| `prodelya_grafik_detay_form_uyumlu_onizleme.html` | Grafik detail | Graphic detail | Production | `C` | V1 |
| `prodelya_grafik_detay_harman_onizleme.html` | Grafik detail | Graphic detail polish | Partial | `B` | V1 |
| `prodelya_grafik_detay_hizli_basit_onizleme.html` | Grafik detail | Graphic detail varyantı | Merge | `H` | V1 |
| `prodelya_grafik_detay_kompakt_onizleme.html` | Grafik detail | Graphic detail | Production | `C` | V1 |
| `prodelya_grafik_final_takim_duzenle_onizleme.html` | Grafik final | Graphic final edit polish | Partial | `B` | V1 |
| `prodelya_grafik_final_takim_onizleme.html` | Grafik final | Graphic final | Production | `C` | V1 |
| `prodelya_grafik_genel_liste_form_uyumlu_onizleme.html` | Grafik list | Graphics list | Production | `C` | V1 |
| `prodelya_grafik_genel_liste_kompakt_onizleme.html` | Grafik list | Graphics list | Production | `C` | V1 |
| `prodelya_grafik_hizli_duzenleme_akisi_onizleme.html` | Grafik akışı | Graphic quick action flow | Direct candidate | `A` | V1 |
| `prodelya_grafik_modulu_onizleme.html` | Grafik modülü | Graphics module | Production | `C` | V1 |
| `prodelya_grafik_uretim_final_onizleme.html` | Grafik/üretim | Graphic-production transition | Partial | `B` | V1 |
| `prodelya_hizli_perakende_satis_onizleme.html` | Perakende | Retail flow | Do not apply | `G` | Yok |
| `prodelya_hizli_tahsilat_odeme_popup_onizleme.html` | Finans popup | Advanced tahsilat popup | Deferred | `D` | V1.1 |
| `prodelya_ilk_cari_kompakt_onizleme.html` | Cari create | Old current account create | Archive | `I` | Arşiv |
| `prodelya_ilk_cari_olusturma_onizleme.html` | Cari create | Old current account create | Archive | `I` | Arşiv |
| `prodelya_ilk_cari_resmi_bilgili_kompakt_onizleme.html` | Cari create | Old current account create | Archive | `I` | Arşiv |
| `prodelya_is_formu_html_print_onizleme.html` | İş formu | Work form print/pdf | Production | `C` | V1 |
| `prodelya_kapsamli_web_site_onizleme (1).html` | Public site | Duplicate site preview | Archive | `I` | Arşiv |
| `prodelya_kapsamli_web_site_onizleme (2).html` | Public site | Duplicate site preview | Archive | `I` | Arşiv |
| `prodelya_kapsamli_web_site_onizleme.html` | Public site | Marketing/public site | Production | `C` | V1 |
| `prodelya_kategori_esleme_temizlik_onizleme.html` | Category mapping | Category cleanup/mapping | Production | `C` | V1 |
| `prodelya_kompakt_teklif_pdf_template_onizleme.html` | Quote PDF | Quote PDF | Production | `C` | V1 |
| `prodelya_kurulum_calisma_sekli_ayarlari_onizleme.html` | Tenant settings | Tenant settings polish | Partial | `B` | V1 |
| `prodelya_master_admin_layout_menu_onizleme.html` | Layout/menu | Admin layout/menu | Production | `C` | V1 |
| `prodelya_matbaa_teklif_is_bazli_dieline_onizleme.html` | Matbaa+dieline | Dieline-heavy future work | Future | `F` | V3 |
| `prodelya_matbaa_teklif_tek_is_kurali_montaj_duzeltilmis_onizleme.html` | Matbaa | Print-service quote | Future | `E` | V2 |
| `prodelya_matbaa_teklif_tek_is_kurali_onizleme.html` | Matbaa | Print-service quote | Future | `E` | V2 |
| `prodelya_muadil_urun_gruplari_onizleme.html` | Muadil ürün | Product grouping | Direct candidate | `A` | V1 |
| `prodelya_muadil_varyant_matrisi_onizleme.html` | Muadil varyant | Variant matrix | Deferred | `D` | V1.1 |
| `prodelya_musteri_arama_scroll_dropdown_onizleme.html` | Quote create | Customer search dropdown | Production | `C` | V1 |
| `prodelya_musteri_secimi_komut_paleti_onizleme.html` | Quote create | Alternate customer selector | Do not apply | `G` | Yok |
| `prodelya_on_muhasebe_cari_detay_final_onizleme.html` | Cari detail | Current account detail | Production | `C` | V1 |
| `prodelya_on_muhasebe_cari_kartlar_final_onizleme.html` | Cari list | Current account list | Production | `C` | V1 |
| `prodelya_perakende_modulu_diger_bolumler_onizleme.html` | Perakende | Retail module | Do not apply | `G` | Yok |
| `prodelya_product_data_hub_standart_onizleme.html` | Product Data Hub | PDH overview | Production | `C` | V1 |
| `prodelya_product_hub_sade_akis_onizleme.html` | Product Hub UX | Product hub simplification | Merge | `H` | V1 |
| `prodelya_profile_comparison_preview.html` | Product Data Hub | Profile comparison | Production | `C` | V1 |
| `prodelya_promosyon_teklif_detay_kompakt_onizleme.html` | Quote detail | Unified quote detail | Production | `C` | V1 |
| `prodelya_promosyon_teklif_detay_modal_kompakt_onizleme.html` | Quote send | Quote send modal | Production | `C` | V1 |
| `prodelya_promosyon_teklif_detay_tipografi_kompakt_onizleme.html` | Quote detail | Typography polish | Partial | `B` | V1 |
| `prodelya_promosyon_teklif_kdv_sade_onizleme.html` | Quote detail/PDF | VAT display | Production | `C` | V1 |
| `prodelya_promosyon_teklif_kdv_urun_bazli_onizleme.html` | Quote detail/PDF | VAT breakdown | Production | `C` | V1 |
| `prodelya_promosyon_teklif_layout_revize_onizleme.html` | Quote detail | Quote detail layout variant | Merge | `H` | V1 |
| `prodelya_promosyon_teklif_olusturma_formu_onizleme.html` | Quote create | Promotion quote create | Production | `C` | V1 |
| `prodelya_promosyon_teklifleri_kompakt_liste_onizleme.html` | Quote list | Quote list | Production | `C` | V1 |
| `prodelya_promosyon_teklifleri_preview.html` | Quote list | Quote list | Production | `C` | V1 |
| `prodelya_promosyon_teklifleri_yeni_onizleme (1).html` | Quote list | Duplicate list preview | Archive | `I` | Arşiv |
| `prodelya_promosyon_teklifleri_yeni_onizleme.html` | Quote list | Quote list | Production | `C` | V1 |
| `prodelya_raporlama_finans_onizleme.html` | Reporting | Advanced finance reporting | Deferred | `D` | V1.1 |
| `prodelya_sade_abone_is_akisi_onizleme.html` | Tenant lifecycle | Tenant flow polish | Partial | `B` | V1 |
| `prodelya_sag_sticky_hizli_siparis_paneli.html` | Shared CTA | Sticky order CTA band | Direct candidate | `A` | V1 |
| `prodelya_siparis_detay_akis_merkezi_onizleme.html` | Order detail | Order operation center | Production | `C` | V1 |
| `prodelya_siparis_detay_sekmeli_teslimat_onizleme.html` | Order detail | Delivery tab view | Production | `C` | V1 |
| `prodelya_siparis_detayi_kompakt_onizleme.html` | Order detail | Order detail | Production | `C` | V1 |
| `prodelya_siparis_detayi_kompakt_turkce_onizleme.html` | Order detail | Order detail | Production | `C` | V1 |
| `prodelya_siparis_finans_ozeti_final_onizleme.html` | Order finance | Order finance summary | Production | `C` | V1 |
| `prodelya_siparis_finans_ozeti_onizleme.html` | Order finance | Order finance summary | Production | `C` | V1 |
| `prodelya_siparis_operasyon_omurgasi_onizleme.html` | Order operations | Operation backbone | Production | `C` | V1 |
| `prodelya_siparis_revizyon_karsilastirma_onizleme.html` | Revision | Revision compare/apply | Production | `C` | V1 |
| `prodelya_siparisler_verimli_onizleme.html` | Order list | Order list | Production | `C` | V1 |
| `prodelya_siparisler_verimli_sade_aksiyon_onizleme (1).html` | Order list | Duplicate order list | Archive | `I` | Arşiv |
| `prodelya_siparisler_verimli_sade_aksiyon_onizleme.html` | Order list | Order list | Production | `C` | V1 |
| `prodelya_siparisler_yeni_onizleme.html` | Order list | Order list | Production | `C` | V1 |
| `prodelya_standard_category_attribute_preview.html` | Standard categories | Standard categories | Production | `C` | V1 |
| `prodelya_super_admin_operasyon_dashboard_onizleme.html` | Super Admin dashboard | Super dashboard | Production | `C` | V1 |
| `prodelya_superadmin_tenant_detay_yeni_onizleme.html` | Tenant detail | Super-admin tenant detail | Partial | `B` | V1 |
| `prodelya_tedarik_detay_form_uyumlu_onizleme.html` | Procurement detail | Procurement detail | Production | `C` | V1 |
| `prodelya_tedarik_detay_kompakt_onizleme.html` | Procurement detail | Procurement detail | Production | `C` | V1 |
| `prodelya_tedarik_detay_secimli_form_uyumlu_onizleme.html` | Procurement detail | Procurement detail polish | Partial | `B` | V1 |
| `prodelya_tedarik_detay_secimli_kompakt_onizleme.html` | Procurement detail | Procurement detail polish | Partial | `B` | V1 |
| `prodelya_tedarik_genel_liste_form_uyumlu_onizleme.html` | Procurement list | Procurement list | Production | `C` | V1 |
| `prodelya_tedarik_genel_liste_kompakt_onizleme.html` | Procurement list | Procurement list | Production | `C` | V1 |
| `prodelya_tedarik_malzeme_detay_onizleme.html` | Procurement material | Procurement material detail | Partial | `B` | V1 |
| `prodelya_tedarik_modulu_onizleme.html` | Procurement module | Procurement module | Production | `C` | V1 |
| `prodelya_tedarikci_guncelleme_bilgilendirme_onizleme.html` | Supplier update | Supplier update notice | Partial | `B` | V1 |
| `prodelya_tedarikci_ilk_kurulum_sade_onizleme.html` | Supplier setup | Supplier source setup | Production | `C` | V1 |
| `prodelya_tedarikci_toplu_talep_onizleme.html` | Supplier bulk request | Bulk supplier request | Direct candidate | `A` | V1 |
| `prodelya_teklif_ara_eleman_alternatif_onizleme.html` | Quote search | Alternate search UX | Merge | `H` | V1 |
| `prodelya_teklif_ara_eleman_popup_onizleme.html` | Quote search | Popup search | Direct candidate | `A` | V1 |
| `prodelya_teklif_detay_kompakt_tabli_onizleme.html` | Quote detail | Quote detail | Production | `C` | V1 |
| `prodelya_teklif_detay_urun_baski_oncelikli_onizleme (1).html` | Quote detail | Duplicate quote detail | Archive | `I` | Arşiv |
| `prodelya_teklif_detay_urun_baski_oncelikli_onizleme.html` | Quote detail | Quote detail | Production | `C` | V1 |
| `prodelya_teklif_musteri_arama_hizli_ekle_modal_onizleme.html` | Quote create | Quick customer create | Production | `C` | V1 |
| `prodelya_teklif_olustur_musteri_bilgileri_kompakt_onizleme.html` | Quote create | Quote customer block | Production | `C` | V1 |
| `prodelya_teklif_pdf_baskibilgisi_tek_satir_onizleme.html` | Quote PDF | Compact PDF polish | Partial | `B` | V1 |
| `prodelya_teklif_siparis_formu_hizali_onizleme.html` | Quote/order form | Unified form polish | Partial | `B` | V1 |
| `prodelya_teklif_siparis_olusturma_formu_onizleme.html` | Quote/order form | Unified form polish | Partial | `B` | V1 |
| `prodelya_teklif_yasam_dongusu_onizleme.html` | Quote lifecycle | Quote lifecycle | Production | `C` | V1 |
| `prodelya_teslimat_detay_font_uyumlu_onizleme.html` | Delivery detail | Delivery detail | Production | `C` | V1 |
| `prodelya_teslimat_detay_onizleme.html` | Delivery detail | Delivery detail | Production | `C` | V1 |
| `prodelya_teslimat_detay_yeni_onizleme.html` | Delivery detail | Delivery detail | Production | `C` | V1 |
| `prodelya_teslimat_listesi_font_uyumlu_onizleme.html` | Delivery list | Delivery list | Production | `C` | V1 |
| `prodelya_teslimat_listesi_yeni_onizleme.html` | Delivery list | Delivery list | Production | `C` | V1 |
| `prodelya_teslimat_modulu_onizleme.html` | Delivery module | Deliveries module | Production | `C` | V1 |
| `prodelya_teslimat_paket_koli_takip_onizleme.html` | Package/box | Advanced package/box tracking | Deferred | `D` | V1.1 |
| `prodelya_uretim_detay_operator_form_uyumlu_onizleme.html` | Production detail | Production detail | Production | `C` | V1 |
| `prodelya_uretim_detay_operator_kompakt_onizleme.html` | Production detail | Production detail | Production | `C` | V1 |
| `prodelya_uretim_detay_yeni_onizleme.html` | Production detail | Production detail polish | Partial | `B` | V1 |
| `prodelya_uretim_fason_detay_onizleme.html` | Fason detail | Outsource detail | Production | `C` | V1 |
| `prodelya_uretim_final_takim_eleman_onizleme.html` | Production final | Operator-focused polish | Partial | `B` | V1 |
| `prodelya_uretim_final_takim_onizleme.html` | Production final | Production final | Production | `C` | V1 |
| `prodelya_uretim_genel_liste_form_uyumlu_onizleme.html` | Production list | Production list | Production | `C` | V1 |
| `prodelya_uretim_genel_liste_kompakt_onizleme.html` | Production list | Production list | Production | `C` | V1 |
| `prodelya_uretim_modulu_onizleme.html` | Production module | Production module | Production | `C` | V1 |
| `prodelya_uretim_paneli_yeni_onizleme.html` | Production panel | Production panel | Production | `C` | V1 |
| `prodelya_uretim_per_print_final_takip_onizleme.html` | Per-print tracking | Per-print production | Production | `C` | V1 |
| `prodelya_yeni_cari_kart_formu_onizleme.html` | Cari create | Current account create polish | Partial | `B` | V1 |
| `prodelya_yeni_cari_olustur_onizleme.html` | Cari create | Current account create polish | Partial | `B` | V1 |
| `prodelya_zincirleme_operasyon_akisi_onizleme.html` | Shared op flow | Shared step/lock UX | Direct candidate | `A` | V1 |
| `product_data_hub_preview_standard.html` | Product Data Hub | PDH overview | Production | `C` | V1 |
| `product_data_hub_preview_yeninesil_standard.html` | Product Data Hub | PDH profile preview | Production | `C` | V1 |

## 5.4 UI Önizleme Yönetişimi ve Uygulama Onay Kapısı

- `145` HTML preview bir fikir envanteridir; geliştirme işi sayısı değildir.
- `docs/ui-previews` altındaki eski HTML dosyaları production talimatı, truth source, acceptance kriteri veya otomatik uygulanacak tasarım sayılmaz.
- `A` ve `B` sınıfları doğrudan implementation kararı değildir.
- `A` sınıfı:
  - fikir olarak güçlüdür
  - ilgili faz açıldığında yeniden değerlendirilir
  - gerekirse yeni güncel preview hazırlanır
- `B` sınıfı:
  - yalnız belirli parçaları fikir olarak kullanılabilir
  - tamamı uygulanmaz
- `C` sınıfı:
  - production ilgili fikri büyük ölçüde karşılıyor demektir
  - yeniden uygulama açılmaz
- Eski preview tek başına backend/veri modeli kararı üretmez.
- Production route/controller/service/model/test zinciri preview’dan üst kanıttır.

### 5.4.1 Faz tipi ayrımı

- `Backend / Foundation` fazları:
  - `Worktree / Checkpoint Stabilization`
  - `Currency Core`
  - `Product Data Hub Currency Propagation`
  - quote/order snapshot backend
  - `Usage Depth Core`
  - `Shared Active Step / Lock Service`
  - event/log altyapısı
- Bu tür fazlarda kullanıcı yüzeyi ciddi değişmiyorsa yeni HTML preview zorunlu değildir.
- `UI / Workflow etkili` fazlar:
  - yeni teklif currency görünümü
  - kullanım derinliği ayarları
  - ortak operasyon geçiş görünümü
  - grafik/tedarik/üretim-fason/teslimat depth adaptasyonları
  - finans multi-currency/depth ekranları
  - customer portal
  - reporting
  - V2 Matbaa ekranları
  - V3 Dieline ekranları
- Bu tür fazlarda implementasyondan önce preview gate zorunludur.

### 5.4.2 Preview gate iş akışı

1. Mevcut production ekranını incele.
2. Route/controller/service/model/test gerçekliğini incele.
3. İlgili eski HTML preview'ları yalnız fikir amacıyla karşılaştır.
4. Mevcut preview'ların güncel ihtiyacı karşılayıp karşılamadığını değerlendir.
5. Yeterli değilse `docs/ui-previews` altında yeni standalone preview oluştur.
6. Uygulama koduna dokunma.
7. Yeni preview'ı kullanıcı değerlendirmesine sun.
8. Çalışmayı durdur.
9. Açık kullanıcı onayını bekle.
10. Açık onay olmadan Blade/CSS/controller/service/migration/test implementasyonuna geçme.
11. Preview hazırlığı ve production implementasyonunu aynı çalışma içinde birleştirme.

### 5.4.3 Kullanıcı onayı kuralı

- Açık uygulama onayı sayılabilecek örnekler:
  - `uygula`
  - `bu tasarım uygun`
  - `production'a geçir`
  - `bu önizlemeye göre yap`
  - `bu şekilde devam et`
- Tek başına uygulama onayı sayılmayan örnekler:
  - `incele`
  - `değerlendir`
  - `önizleme oluştur`
  - `alternatif hazırla`
  - `daha sade yap`
  - `bunu karşılaştır`
- Onay yoksa yalnız preview ve değerlendirme yapılır.

### 5.4.4 Yeni preview kalite standardı

- standalone çalışmalı
- uygulama koduna bağımlı olmamalı
- kullanıcı-facing metinler Türkçe olmalı
- Türkçe karakterler doğru olmalı
- Prodelya font ve yoğunluk standardına uymalı
- aşırı border-radius ve aşırı bold kullanmamalı
- gereksiz KPI/kart üretmemeli
- gerçek Prodelya iş kurallarını temel almalı
- hayali backend özelliğini çalışıyormuş gibi göstermemeli
- dummy veri kullanıyorsa örnek olduğu anlaşılmalı
- permission ve finans görünürlüğünü dikkate almalı
- tenant / Abone Firma terminolojisini kullanmalı
- Matbaa V2 ve Dieline V3 kapsamını yanlışlıkla V1'e taşımamalı
- masaüstü ve dar ekran mantığını düşünmeli
- mevcut production ekranını tamamen yeniden yazmak yerine gerekli farkı göstermeli

### 5.4.5 Preview raporu minimum özeti

- ilgili faz
- mevcut production ekranı
- incelenen eski preview'lar
- eski preview'lardan alınan fikirler
- eski preview'lardan alınmayan fikirler
- yeni preview'ın amacı
- production'dan farkı
- gerekli backend karşılığı
- uygulanırsa değişecek dosya aileleri
- kapsam dışı bırakılanlar
- kullanıcıdan beklenen karar

## 6. Önizleme / Production Karşılaştırması

### 6.1 Production’da zaten uygulanmış ana aileler

- teklif listesi
- sipariş listesi
- teklif detay unified surface
- teklif gönderim modalı
- sipariş detay operasyon merkezi
- current account list/detail/statement
- grafik / tedarik / üretim / teslimat liste ve detay aileleri
- Product Data Hub super-admin yüzeyleri
- tenant catalog projection + quote search
- public site

### 6.2 Preview’de olup production’da foundation veya follow-up gerektiren ana aileler

- Currency Core’a bağlanacak fiyat/kur alanları
- Usage Depth Core’a bağlanacak ortak operasyon yüzeyleri
- advanced package/koli/etiket
- gelişmiş reporting

### 6.3 Eski / tekrar / uygulanmamalı aileler

- `(1)` / `(2)` kopya preview’lar
- eski cari create varyantları
- perakende preview’ları
- customer command palette varyantı

### 6.4 V2 Matbaa açık dosya listesi

- `prodelya_16_sayfa_forma_montaj_duzeltme_onizleme.html`
- `prodelya_16_sayfa_forma_yon_tasirma_duzeltilmis_onizleme.html`
- `prodelya_forma_preset_editor_onizleme.html`
- `prodelya_matbaa_teklif_tek_is_kurali_montaj_duzeltilmis_onizleme.html`
- `prodelya_matbaa_teklif_tek_is_kurali_onizleme.html`

Toplam: `5`

### 6.5 V3 Dieline açık dosya listesi

- `prodelya_bicak_kutuphanesi_dieline_eslesme_onizleme.html`
- `prodelya_matbaa_teklif_is_bazli_dieline_onizleme.html`

Toplam: `2`

## 7. Bölüm Bazlı Mevcut ve Eksik Yapılar

## 7.1 Bölüm durum tablosu

| Bölüm | Production Durumu | Önizleme Durumu | Eksik | Hedef Sürüm | Öncelik |
| --- | --- | --- | --- | --- | --- |
| Genel layout ve menü | güçlü | çoklu varyant | menu cleanup churn | V1 | Orta |
| Abone Firma dashboard | güçlü | uygulanmış | küçük polish | V1 | Düşük |
| Super Admin dashboard | güçlü | uygulanmış | küçük polish | V1 | Düşük |
| Abone Firma yaşam döngüsü | güçlü | kısmi | küçük polish | V1 | Düşük |
| Paket/modül/özellik/limit | güçlü | dolaylı | depth yok | V1 | Orta |
| Kullanım Derinliği | yok | kavramsal | foundation eksik | V1 | Kritik |
| Cari/firma kartları | güçlü | uygulanmış | SaaS cari follow-up | V1/V1.1 | Orta |
| Cari hareketler | güçlü | uygulanmış | gelişmiş kur farkı | V1.1 | Orta |
| Ürün ve katalog | güçlü | kısmi | currency propagation | V1 | Yüksek |
| Product Data Hub | güçlü backend | kısmi/uygulanmış | tenant yüzeyi sadeleştirme | V1 | Yüksek |
| Tedarikçi kaynak/import | güçlü | uygulanmış | currency propagation | V1 | Yüksek |
| Kategori eşleme | güçlü | uygulanmış | küçük polish | V1 | Düşük |
| Ürün eşleme/muadil ürün | kısmi | preview var | backend/UX hizası | V1 | Orta |
| Fiyat/stok güncelleme | parçalı | preview dolaylı | currency core yok | V1 | Kritik |
| Tenant katalog projection | güçlü | uygulanmış | currency propagation | V1 | Yüksek |
| Teklif listesi | tamamlanmış | uygulanmış | reopen yok | V1 | Düşük |
| Teklif oluşturma | güçlü | uygulanmış | currency support | V1 | Yüksek |
| Teklif düzenleme | güçlü | uygulanmış | currency support | V1 | Yüksek |
| Teklif detay | tamamlanmış | uygulanmış | küçük polish | V1 | Düşük |
| Teklif gönderme | tamamlanmış | uygulanmış | FX snapshot meta | V1 | Orta |
| Public teklif onayı | güçlü | uygulanmış | reopen yok | V1 | Düşük |
| Sipariş listesi | tamamlanmış | uygulanmış | reopen yok | V1 | Düşük |
| Sipariş detay | tamamlanmış | uygulanmış | küçük polish | V1 | Düşük |
| Sipariş revizyonu | güçlü | uygulanmış | finance/currency carryover | V1 | Orta |
| Tekrar sipariş | güçlü | dolaylı | currency carryover | V1 | Orta |
| İş Formu | güçlü | uygulanmış | currency snapshot görünürlüğü | V1 | Düşük |
| Grafik | güçlü | uygulanmış | depth adaptation | V1/V1.1 | Orta |
| Tedarik | güçlü | uygulanmış | currency + depth | V1 | Yüksek |
| Üretim | güçlü | uygulanmış | depth + actual cost | V1/V1.1 | Yüksek |
| Fason | güçlü | uygulanmış | actual cost + currency | V1/V1.1 | Yüksek |
| Teslimat | güçlü | uygulanmış | package depth | V1/V1.1 | Orta |
| Koli/etiket | kısmi | ileri preview | gelişmiş paket sistemi | V1.1 | Orta |
| Finans/tahsilat | güçlü çekirdek | uygulanmış | multi-currency core eksik | V1 | Kritik |
| Bildirim | güçlü | uygulanmış | activity log genişlemesi | V1/V1.1 | Düşük |
| Müşteri portalı | güçlü çekirdek | kısmi | gelişmiş portal | V1/V1.1 | Orta |
| Public site | güçlü | uygulanmış | küçük polish | V1 | Düşük |
| Raporlama | kısmi | preview önde | advanced reporting | V1.1 | Düşük |
| Canlıya hazırlık | kısmi | dağınık | stabilization + currency + full suite | V1 | Kritik |
| Çoklu para birimi | parçalı | dolaylı | foundation eksik | V1 | Kritik |
| Matbaa V2 | route/modül izi var | preview var | V2 geliştirme | V2 | Deferred |
| Dieline V3 | plan izi var | preview var | V3 geliştirme | V3 | Deferred |

## 8. Çoklu Para Birimi Mevcut Durum Auditi

- `TL` ve `TRY` birlikte kullanılıyor
- `orders.currency` var
- PDH tarafında `currency` sinyali var
- current account tarafında currency özetleri var
- quote send snapshot var
- unified exchange-rate snapshot domain’i yok
- canonical ISO disiplini yok

### Çoklu para birimi propagation tablosu

| Katman | Mevcut Fiyat Alanı | Currency Var mı | Snapshot Var mı | Eksik |
| --- | --- | --- | --- | --- |
| Supplier source | raw supplier fields | Evet | Kısmi | canonical ISO policy |
| Raw product/variant | purchase/list aliases | Evet | Kısmi | normalized source currency |
| Standard product/variant | purchase/list fields | Kısmi | Kısmi | original vs base price |
| Tenant catalog projection | display/meta snapshot | Evet | Kısmi | applied rate meta |
| Quote search | JSON payload | Evet | Evet | suggested/manual split |
| Quote item | price snapshot | Evet | Evet | rate source/date |
| Quote send snapshot | snapshot_json | Evet | Evet | immutable FX metadata |
| Order item | order/item pricing | Evet | Kısmi | disciplined carryover |
| Procurement | purchase entry | Evet | Kısmi | actual cost FX chain |
| Production/fason | subcontractor cost | Kısmi | Kısmi | actual cost policy |
| Payment | payment currency | Evet | Hayır | reconciliation rules |
| Current account transaction | transaction currency | Evet | Hayır | FX movement policy |
| PDF | rendered currency | Evet | Evet | canonical symbol/ISO |
| Reporting | mixed | Kısmi | Hayır | reporting currency model |

## 9. Çoklu Para Birimi Hedef Mimarisi

- Canonical kod: `TRY`, `USD`, `EUR`
- UI gerektiğinde `TRY` yerine `TL` gösterebilir
- Günlük/scheduled kur tablosu olmalı
- TCMB canlı request’i teklif ekranında yapılmamalı
- Kur yoksa sessizce `1` kabul edilmemeli
- Gönderilmiş teklif ve sipariş snapshot’ı değişmemeli
- Manuel satış fiyatı kur güncellemesiyle ezilmemeli

### Currency dependency map

| İş | Bağlı Olduğu İş | Neden | Bağımlılık Türü |
| --- | --- | --- | --- |
| Currency Core | yok | canonical domain | `FOUNDATION` |
| Product Data Hub currency propagation | Currency Core | source→catalog zinciri | `BLOCKER` |
| Quote currency conversion and snapshot | Currency Core + PDH propagation | belge disiplini | `BLOCKER` |
| Order / Procurement currency carryover | Quote currency snapshot | carryover doğruluğu | `FOLLOW-UP` |
| Finance / current account multi-currency | Currency Core + carryover | borç/alacak tutarlılığı | `FOLLOW-UP` |
| Reporting | Finance multi-currency | güvenilir rapor | `FOLLOW-UP` |

## 10. Kullanım Derinliği Mevcut Durum Auditi

- Production’da usage limit vardır
- Production’da process depth policy yoktur
- Paket / tenant / user / record-state temelli ortak depth resolver görünmüyor

### Kullanım Derinliği tablosu

| Süreç | Basit | Ekip | Detaylı | Mevcut Destek | Eksik |
| --- | --- | --- | --- | --- | --- |
| Grafik | kısmi | kısmi | kısmi | status/workflow var | shared depth yok |
| Tedarik | kısmi | kısmi | kısmi | supplier request var | depth-aware validation yok |
| Üretim/Fason | kısmi | kısmi | kısmi | per-print akış var | depth policy yok |
| Teslimat | kısmi | kısmi | zayıf | delivery akışı var | package depth yok |
| Finans | basit | kısmi | zayıf | payment/current account var | depth + FX birlikte yok |

## 11. Kullanım Derinliği Hedef Mimarisi

Çözümleme sırası:

1. module access
2. feature access
3. package maximum depth
4. tenant selected depth
5. user permission
6. record state
7. security policy

### Usage depth dependency map

| İş | Bağlı Olduğu İş | Neden | Bağımlılık Türü |
| --- | --- | --- | --- |
| Package maximum depth | yok | üst sınır | `FOUNDATION` |
| Tenant selected depth | package maximum depth | tenant seviyesi | `FOUNDATION` |
| Shared depth resolver/service | package + tenant depth | ortak karar motoru | `BLOCKER` |
| Validation/action availability | shared depth resolver | UI tek başına yetmez | `BLOCKER` |
| Graphic depth adaptation | resolver | süreç uyarlaması | `FOLLOW-UP` |
| Procurement depth adaptation | resolver | süreç uyarlaması | `FOLLOW-UP` |
| Production/fason depth adaptation | resolver | süreç uyarlaması | `FOLLOW-UP` |
| Delivery depth adaptation | resolver | süreç uyarlaması | `FOLLOW-UP` |
| Finance depth adaptation | resolver + currency core | süreç uyarlaması | `FOLLOW-UP` |

## 12. Ortak Operasyon Akışı ve Kilit Modeli

Operasyon hattı:

- Grafik
- Tedarik
- Üretim/Fason
- Teslimat

Finans paralel akıştır; operasyonu kapatan zorunlu son kapı değildir.

Production’da eksik ortak alanlar:

- `active_step`
- `next_step`
- `lock_reason`
- `target_route`
- `primary_cta`
- `completion_percentage`

Bu nedenle `Shared Active Step / Lock Service` ayrı foundation fazı olmalıdır.

## 13. Zaten Tamamlanmış ve Yeniden Açılmaması Gerekenler

| İş | Kanıt | Yeniden Açılmalı mı? | Kalan Mikro İş |
| --- | --- | --- | --- |
| Teklif listesi ayrımı | `bf053ca` | Hayır | regressions |
| Sipariş listesi tamamlananlar ayrımı | `fd141db` | Hayır | regressions |
| Teklif detay unified decision surface | `bfb4382`, `c567e82` | Hayır | küçük polish |
| Teklif gönderim modal/controller akışı | `21753a6` | Hayır | FX snapshot bağı |
| Public teklif onayı | `PublicQuoteApprovalRouteTest` | Hayır | regressions |
| Sipariş detay operasyon merkezi | `b07daed`, `8bdac82`, `0fd49be` | Hayır | küçük polish |
| Sipariş revizyon çekirdeği | `order_revisions` migration + tests | Hayır | finance/currency carryover |
| Tekrar sipariş çekirdeği | repeat order tests | Hayır | currency carryover |
| Product Data Hub raw/standard/projection | migration + service + tests | Hayır | currency propagation |
| Tenant katalog projection + quote search | `TenantCatalogController`, `CatalogSearchController` | Hayır | currency propagation |
| Public Graphic Approval Cleanup | `dfbce43`, `d03461e`, `PublicGraphicApprovalRouteTest` | Hayır | regressions only |

## 14. V1 Kritik Eksikler

1. Worktree / Checkpoint Stabilization
2. Currency Core
3. Product Data Hub Currency Propagation
4. Quote Currency Conversion and Snapshot
5. Order / Procurement Currency Carryover
6. Usage Depth Core
7. Shared Active Step / Lock Service

## 15. V1.1 İşleri

| İş | V1.1/V2/V3 | Erteleme Nedeni |
| --- | --- | --- |
| Gelişmiş koli/etiket sistemi | V1.1 | temel teslimat var, ileri paketleme sonra |
| Gerçek local stok rezervasyon | V1.1 | satış çekirdeğini bloklamıyor |
| Abone Firma SaaS cari hesabı | V1.1 | core current account var |
| Gelişmiş portal | V1.1 | temel portal var |
| Gelişmiş raporlama | V1.1 | V1 blocker değil |
| Gelişmiş kur farkı hareketleri | V1.1 | önce Currency Core |
| Tedarik/fason otomatik cari entegrasyon kalıntıları | V1.1 | core var, ileri otomasyon sonra |

## 16. V2 Matbaa Referansları

- `prodelya_16_sayfa_forma_montaj_duzeltme_onizleme.html`
- `prodelya_16_sayfa_forma_yon_tasirma_duzeltilmis_onizleme.html`
- `prodelya_forma_preset_editor_onizleme.html`
- `prodelya_matbaa_teklif_tek_is_kurali_montaj_duzeltilmis_onizleme.html`
- `prodelya_matbaa_teklif_tek_is_kurali_onizleme.html`

Toplam: `5`

Net karar:

- V1 eksik işi değildir
- V2’ye ayrılmalıdır

## 17. V3 Dieline Referansları

- `prodelya_bicak_kutuphanesi_dieline_eslesme_onizleme.html`
- `prodelya_matbaa_teklif_is_bazli_dieline_onizleme.html`

Toplam: `2`

Net karar:

- V1 veya V2 blocker’ı değildir
- V3’e ayrılmalıdır

## 18. Master Bağımlılık Haritası

| İş | Bağlı Olduğu İş | Neden | Bağımlılık Türü |
| --- | --- | --- | --- |
| Worktree stabilization | yok | temiz zemin | `FOUNDATION` |
| Currency Core | stabilization | kirli worktree üstüne açılmamalı | `FOUNDATION` |
| PDH currency propagation | Currency Core | catalog fiyat zinciri | `BLOCKER` |
| Quote currency snapshot | Currency Core + PDH propagation | belge disiplini | `BLOCKER` |
| Order/procurement carryover | quote currency snapshot | veri devamlılığı | `FOLLOW-UP` |
| Usage Depth Core | stabilization | shared settings/disiplin | `FOUNDATION` |
| Shared active-step/lock service | Usage Depth Core | ortak operasyon kararı | `BLOCKER` |
| Graphic depth adaptation | Usage Depth Core + lock service | süreç uyarlaması | `FOLLOW-UP` |
| Procurement depth adaptation | Usage Depth Core + lock service | süreç uyarlaması | `FOLLOW-UP` |
| Production/fason depth adaptation | Usage Depth Core + lock service | süreç uyarlaması | `FOLLOW-UP` |
| Delivery depth adaptation | Usage Depth Core + lock service | süreç uyarlaması | `FOLLOW-UP` |
| Finance multi-currency/depth | Currency Core + carryover + Usage Depth Core | finans zinciri | `FOLLOW-UP` |

## 19. Master Uygulama Sırası

### 19.1 Tamamlanan ön faz

- `TAMAMLANAN ÖN FAZ — Master Audit / Currency and Snapshot Inventory`

Bu audit ve currency/snapshot envanteri zaten yapılmıştır; gelecekte yeniden çalıştırılacak implementation fazı değildir.

### 19.2 Gerçek implementation sırası

| Sıra | Faz | Tip | Ön Koşul | Bloke Ettiği İş | Risk | Hedef Sürüm |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | Worktree / Checkpoint Stabilization | Backend / Foundation | Audit tamam | tüm büyük fazlar | Orta | V1 |
| 2 | Currency Core | Backend / Foundation | Faz 1 | 3-7, 28-30 | Kritik | V1 |
| 3 | Product Data Hub Currency Propagation | Backend / Foundation | Faz 2 | 4-7, 28-30 | Yüksek | V1 |
| 4 | Yeni Teklif Currency UI Preview | UI / Workflow Preview | Faz 2-3 | 6 | Orta | V1 |
| 5 | Kullanıcı Onayı | Approval Gate | Faz 4 | 6 | Kritik | V1 |
| 6 | Quote Currency Conversion and Snapshot Implementation | UI + Backend Implementation | Faz 5 | 7, 14, 28-30 | Kritik | V1 |
| 7 | Order / Procurement Currency Carryover | Backend / Workflow | Faz 6 | 28-30 | Yüksek | V1 |
| 8 | Usage Depth Core | Backend / Foundation | Faz 1 | 9-30 | Kritik | V1 |
| 9 | Yeni Kullanım Derinliği Ayarları Preview | UI / Workflow Preview | Faz 8 | 11 | Orta | V1 |
| 10 | Kullanıcı Onayı | Approval Gate | Faz 9 | 11 | Kritik | V1 |
| 11 | Usage Depth Settings Implementation | UI + Backend Implementation | Faz 10 | 12-30 | Yüksek | V1 |
| 12 | Shared Active Step / Lock Service | Backend / Foundation | Faz 8, 11 | 13-30 | Yüksek | V1 |
| 13 | Yeni Ortak Operasyon Akışı Preview | UI / Workflow Preview | Faz 12 | 15 | Orta | V1 |
| 14 | Kullanıcı Onayı | Approval Gate | Faz 13 | 15 | Kritik | V1 |
| 15 | Ortak Operasyon UI Implementation | UI + Backend Implementation | Faz 14 | 16-31 | Yüksek | V1 |
| 16 | Yeni Grafik Depth Preview | UI / Workflow Preview | Faz 11-15 | 18 | Orta | V1 |
| 17 | Kullanıcı Onayı | Approval Gate | Faz 16 | 18 | Kritik | V1 |
| 18 | Graphic Depth Adaptation | UI + Workflow Implementation | Faz 17 | 31, 34-37 | Orta | V1 |
| 19 | Yeni Tedarik Depth Preview | UI / Workflow Preview | Faz 11-15 | 21 | Orta | V1 |
| 20 | Kullanıcı Onayı | Approval Gate | Faz 19 | 21 | Kritik | V1 |
| 21 | Procurement Depth Adaptation | UI + Workflow Implementation | Faz 20 | 28-31, 35-37 | Yüksek | V1 |
| 22 | Yeni Üretim/Fason Depth Preview | UI / Workflow Preview | Faz 11-15 | 24 | Orta | V1 |
| 23 | Kullanıcı Onayı | Approval Gate | Faz 22 | 24 | Kritik | V1 |
| 24 | Production/Fason Depth Adaptation | UI + Workflow Implementation | Faz 23 | 28-31, 35-37 | Yüksek | V1 |
| 25 | Yeni Teslimat Depth Preview | UI / Workflow Preview | Faz 11-15 | 27 | Orta | V1 |
| 26 | Kullanıcı Onayı | Approval Gate | Faz 25 | 27 | Kritik | V1 |
| 27 | Delivery Depth Adaptation | UI + Workflow Implementation | Faz 26 | 31-37 | Orta | V1 |
| 28 | Yeni Finans Multi-Currency/Depth Preview | UI / Workflow Preview | Faz 7, 11, 21, 24 | 30 | Orta | V1 |
| 29 | Kullanıcı Onayı | Approval Gate | Faz 28 | 30 | Kritik | V1 |
| 30 | Finance Multi-Currency and Depth | UI + Backend Implementation | Faz 29 | 31, 35-37 | Kritik | V1 |
| 31 | Notification / Activity Log | Backend / Workflow | Faz 15, 18, 21, 24, 27, 30 | 32-37 | Orta | V1 |
| 32 | Yeni Customer Portal Preview | UI / Workflow Preview | Faz 6, 27, 31 | 34 | Orta | V1 |
| 33 | Kullanıcı Onayı | Approval Gate | Faz 32 | 34 | Kritik | V1 |
| 34 | Customer Portal Extension | UI + Backend Implementation | Faz 33 | 35-38 | Orta | V1 |
| 35 | Yeni Reporting Preview | UI / Workflow Preview | Faz 30-31 | 37 | Düşük | V1 |
| 36 | Kullanıcı Onayı | Approval Gate | Faz 35 | 37 | Kritik | V1 |
| 37 | Operational and Currency Reporting | UI + Backend Implementation | Faz 36 | 38 | Orta | V1 |
| 38 | V1 Go-Live Closure | Closure | Faz 1-37 | V1 | Kritik | V1 |
| 39 | V1.1 | Deferred | Faz 38 | V1.1 | Orta | V1.1 |
| 40 | V2 Matbaa | Deferred | Faz 38 | V2 | Yüksek | V2 |
| 41 | V3 Dieline | Deferred | Faz 38 veya ayrı ürün kararı | V3 | Yüksek | V3 |

## 20. Paralel Yürütülebilecek İşler

- Faz 1 içinde:
  - menü cleanup sınıflandırması
  - docs/test cleanup sınıflandırması
- Faz 2 hazırlığı ile paralel:
  - currency field inventory notları
  - usage-depth persistence design notları

## 21. Paralel Yürütülmemesi Gereken İşler

- Currency Core tamamlanmadan quote/order multi-currency implementasyonu
- Usage Depth Core tamamlanmadan süreç depth adaptasyonları
- Stabilization tamamlanmadan büyük CSS/controller feature fazları
- Matbaa ve Dieline’in V1 eksiği gibi ele alınması

## 22. Risk Matrisi

| Faz | Schema | Tenant | Permission | Finans | Snapshot | UI |
| --- | --- | --- | --- | --- | --- | --- |
| Stabilization | Düşük | Düşük | Düşük | Düşük | Düşük | Orta |
| Currency Core | Yüksek | Orta | Orta | Kritik | Kritik | Orta |
| PDH currency propagation | Orta | Orta | Düşük | Yüksek | Orta | Düşük |
| Quote currency snapshot | Orta | Orta | Orta | Yüksek | Kritik | Orta |
| Order/procurement carryover | Orta | Orta | Orta | Yüksek | Yüksek | Orta |
| Usage Depth Core | Orta | Orta | Yüksek | Düşük | Düşük | Orta |
| Shared lock service | Düşük | Orta | Orta | Düşük | Orta | Orta |
| Finance multi-currency/depth | Orta | Orta | Orta | Kritik | Yüksek | Orta |

## 23. İlk Başlanacak Faz

Üç ayrı cevap:

- Şimdi yapılan çalışma:
  - `Master Audit internal consistency fix ve audit checkpoint’i`
- Audit sonrası ilk kod fazı:
  - `Worktree / Checkpoint Stabilization`
- İlk gerçek feature fazı:
  - `Currency Core`

## 24. İlk 10 Uygulama

Bu tablo teknik uygulama işlerini özetler. UI/workflow etkili işlerin öncesindeki zorunlu preview ve kullanıcı onayı kapıları Bölüm 19.2 Master Uygulama Sırası'nda ayrıca gösterilmiştir.

| Sıra | İş | Neden Şimdi | Ön Koşul | Beklenen Kazanç |
| ---: | --- | --- | --- | --- |
| 1 | Worktree stabilization | güvenli başlangıç | audit | temiz checkpoint zemini |
| 2 | TRY/TL canonicalization kararı | currency zincirini açar | 1 | domain netliği |
| 3 | Currency Core | foundation | 1-2 | quote/order/finance çekirdeği |
| 4 | PDH currency propagation | quote search’i güvenli yapar | 3 | source→catalog zinciri |
| 5 | Quote manual/suggested/snapshot ayrımı | belge güvenliği | 3-4 | teklif bütünlüğü |
| 6 | Order/procurement carryover | operasyon/finance devamlılığı | 5 | sipariş maliyet güveni |
| 7 | Usage Depth persistence + resolver | süreç derinliği çekirdeği | 1 | tenant depth davranışı |
| 8 | Shared active-step/lock service | ortak operasyon aklı | 7 | tekrarlı Blade mantığını azaltır |
| 9 | Graphic + procurement depth adaptation | ilk operasyon adaptasyonu | 7-8 | görünür kullanıcı değeri |
| 10 | Finance multi-currency/depth | V1 kapanış için şart | 3-7 | finans tutarlılığı |

## 25. Her Faz İçin Ayrıntılı Plan

### Faz 1 — Worktree / Checkpoint Stabilization

- Amaç: karışık worktree’yi güvenli kümelere ayırmak
- Mevcut durum: route/config/css/controller/model/test/docs/tmp karışık
- Ön koşul: Audit tamam
- Kapsam:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - quote/order list testleri
  - geçici `.tmp_*` blade dosyaları
  - untracked docs raporları
  - `docs/ui-previews/`
  - diğer untracked test ve doküman dosyaları
- Kapsam dışı:
  - Public Graphic Approval yeniden açma
  - yeni feature geliştirme
- Sınıflandırma:
  - `A` tamamlanmış checkpoint kalıntısı
  - `B` güvenli küçük cleanup
  - `C` davranış değişikliği içeren ayrı feature hunkı
  - `D` geçici/backup dosya
  - `E` doküman/preview referansı
  - `F` production karşılığı belirsiz test değişikliği
  - `G` bir sonraki faza bırakılacak değişiklik
- Schema: Hayır
- Risk: Orta
- Başarı kriteri: yeni büyük fazları güvenli ayıran checkpoint zemini

### Faz 2 — Currency Core

- Amaç: canonical currency domain
- Ön koşul: Faz 1
- Schema: Evet
- Risk: Kritik
- Başarı kriteri: `TRY/USD/EUR` çekirdeği ve rate policy

### Faz 3 — Product Data Hub Currency Propagation

- Amaç: source→raw→standard→projection currency zinciri
- Ön koşul: Faz 2
- Schema: Muhtemel
- Risk: Yüksek
- Başarı kriteri: tenant catalog ürünlerinde güvenilir currency meta

### Faz 4 — Quote Currency Conversion and Snapshot

- Amaç: quote seviyesinde immutable FX snapshot
- Ön koşul: Faz 2-3
- Risk: Kritik
- Başarı kriteri: gönderilmiş tekliflerin kur değişiminden etkilenmemesi

### Faz 5 — Order / Procurement Currency Carryover

- Amaç: quote FX bilgisini order/procurement zincirine taşımak
- Ön koşul: Faz 4
- Risk: Yüksek
- Başarı kriteri: order/procurement currency tutarlılığı

### Faz 6 — Usage Depth Core

- Amaç: süreç bazlı depth çekirdeği
- Ön koşul: Faz 1
- Schema: Evet
- Risk: Kritik
- Başarı kriteri: package + tenant selected depth + shared resolver

### Faz 7 — Shared Active Step / Lock Service

- Amaç: ortak operasyon CTA/lock aklı
- Ön koşul: Faz 6
- Risk: Yüksek
- Başarı kriteri: active step / next step / lock reason tek kaynaktan hesaplanır

## 26. Test ve Smoke Stratejisi

### Üç test seviyesi

1. Hedefli test
2. Modül regresyonu
3. Full suite

### Currency zorunlu senaryolar

- `USD -> TRY`
- `EUR -> TRY`
- `TRY -> USD`
- `TRY -> EUR`
- weekend rate
- missing rate
- manual rate
- manual sales price preservation
- sent quote immutability
- order conversion carryover
- tenant isolation
- permission ile maliyet gizleme

### Usage Depth zorunlu senaryolar

- package max depth
- tenant selected depth
- üst seviyeye çıkamama
- depth düşürmede veri silinmemesi
- UI görünürlüğü
- validation farkları
- action görünürlüğü
- tenant isolation
- permission

## 27. Git / Checkpoint / Commit Stratejisi

- her büyük faz ayrı kontrollü checkpoint ile ilerlemeli
- `audit -> implementation -> targeted test -> smoke -> report -> commit`
- karışık dosyalarda patch staging tercih edilmeli
- shared CSS’de yalnız namespace/hunk staging yapılmalı
- migration ve büyük UI aynı dev committe karıştırılmamalı
- docs raporu ayrı commit olabilir
- full suite geçmeden büyük faz kapanmamalı
- worktree temizlenmeden yeni büyük faz açılmamalı

## 28. Nihai V1 Kapanış Kriterleri

- stabilization tamam
- currency core ve carryover tamam
- usage depth core ve lock service tamam
- kritik tenant/permission/finance/snapshot regressions yok
- hedefli test + modül regresyonu + full suite kabul edilebilir seviyede yeşil

## 29. Sonuç ve Kesin Karar

- Benzersiz preview sayısı `145` olarak doğrulandı.
- Preview sınıf dağılımı `A7 / B21 / C87 / D5 / E5 / F2 / G3 / H6 / I9` olarak düzeltildi.
- Yinelenen rapor satırları temizlendi.
- Preview'ların fikir envanteri olduğu ve production truth source sayılmayacağı yönetişim kuralı eklendi.
- `A` ve `B` sınıfları doğrudan implementation kararı olmaktan çıkarıldı.
- Foundation fazları ile UI/workflow fazları ayrıldı; UI etkili fazlara preview ve açık kullanıcı onayı kapıları eklendi.
- V2 açık dosya listesi `5` dosya ile netleştirildi.
- V3 açık dosya listesi `2` dosya ile netleştirildi.
- Gelecek faz listesindeki `Faz 0` kaldırıldı; completed pre-phase olarak ayrıldı.
- Gerçek implementation sırası `Worktree / Checkpoint Stabilization` ile başlar.
- İlk gerçek feature fazı `Currency Core`’dur.
- Public Graphic Approval tamamlanmış alan olarak korunur ve stabilization kapsamına tekrar alınmaz.
