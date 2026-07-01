@php($compact = (bool) ($compact ?? false))

<section class="pd-section-card pd-section-card-soft-slate">
    <div class="pd-section-header">
        <div>
            <h3 class="pd-section-title">Odeme Faz Durumu</h3>
            <p class="pd-section-subtitle">Bu ekran, odeme omurgasinin hangi seviyede oldugunu ve kaldigimiz yerden nasil devam edecegimizi net gostermek icin tutulur.</p>
        </div>
    </div>
    <div class="pd-section-body">
        <div class="pd-field-grid pd-field-grid-2">
            <div>
                <div class="pd-field-label">Bu Fazda Hazir</div>
                <div class="pd-field-value">
                    Ortak provider omurgasi, credential store, checkout session, webhook log, callback sayfalari, SaaS cari tahsilat senkronu ve checkout operasyon aksiyonlari hazir.
                </div>
            </div>
            <div>
                <div class="pd-field-label">Mimari Sabit</div>
                <div class="pd-field-value">
                    Super Admin odeme omurgasi ortaktir. Tenant tarafi odeme yetenegi ayri bir modül olarak acilacaktir.
                </div>
            </div>
            <div>
                <div class="pd-field-label">Bu Fazda Bilerek Kapali</div>
                <div class="pd-field-value">
                    Tenant customer payment, tam otomatik billing, subscription renewal, failed payment retry orchestration ve tenant adina otomatik provider provisioning bu fazda acik degildir.
                </div>
            </div>
            <div>
                <div class="pd-field-label">Sonraki Teknik Faz</div>
                <div class="pd-field-value">
                    Iyzico provider-specific signature/header sertlestirmesi, token/payment id eslesmesi ve webhook replay korumasi sirasiyla tamamlanacaktir.
                </div>
            </div>
            @unless($compact)
                <div>
                    <div class="pd-field-label">Super Admin Sonrasi</div>
                    <div class="pd-field-value">
                        Ortak SaaS odeme akisi tamamen oturduktan sonra tenant tarafi odeme modülü acilacak; quote/order/customer payment akislari ayri scope olarak ele alinacaktir.
                    </div>
                </div>
                <div>
                    <div class="pd-field-label">Bu Ekrani Gorunce Ne Hatirlanmali</div>
                    <div class="pd-field-value">
                        Buradaki omurga canli ticari omurgadir ama tum otomasyonlar tamamlanmis son urun degildir. Yani provider baglandiysa bile sonraki guvenlik fazlari bitmeden nihai rollout yapilmamalidir.
                    </div>
                </div>
            @endunless
        </div>
    </div>
</section>
