@extends('layouts.public-site')

@section('title', 'Promosyon Teklif ve Sipariş Yönetimi Yazılımı | Prodelya')
@section('meta_description', 'Promosyon, baskı ve sipariş operasyonlarını tekliften teslimata tek panelde yönetin. Product Data Hub, müşteri portalı, grafik onayı ve operasyon takibi Prodelya’da.')

@section('content')
<section class="hero">
    <div class="hero-grid">
        <div>
            <div class="eyebrow">Promosyon, baskı ve sipariş operasyonları için merkezi SaaS platformu</div>
            <h1>Promosyon, baskı ve sipariş operasyonlarını tek panelden yönetin.</h1>
            <p class="lead">Prodelya; promosyon ürünleri firmaları için teklif, müşteri onayı, ürün kataloğu, grafik, tedarik, üretim, teslimat ve finans takibini tek sistemde toplar.</p>

            <div class="hero-actions">
                <a href="{{ route('marketing.register-interest') }}" class="btn btn-primary">1 Ay Ücretsiz Dene</a>
                <a href="{{ route('marketing.demo-request') }}" class="btn btn-success">Demo Talep Et</a>
                <a href="#paketler" class="btn btn-light">Paketleri İncele</a>
            </div>

            <div class="soft-note success">Ücretsiz deneme talebinde ödeme alınmaz. Kredi kartı gerekmez. Başvuru sonrası sizinle iletişime geçilir ve uygun görülürse Abone Firma paneli açılır.</div>

            <div class="hero-support">
                <a href="#ozellikler">Özellikleri Gör</a>
                <a href="{{ route('login') }}">Abone Firma Girişi</a>
            </div>

            <div class="pill-row">
                <span class="pill">Tekliften siparişe tek akış</span>
                <span class="pill">Müşteri teklif onayı</span>
                <span class="pill">Product Data Hub</span>
                <span class="pill">Talep Merkezi ile büyüyen SaaS yapı</span>
            </div>
        </div>

        <div class="card hero-panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title">Günlük operasyon özeti</div>
                    <p class="panel-sub">Abone Firma panelinde ekiplerin göreceği pratik akış.</p>
                </div>
                <span class="pill">Canlıya hazır çekirdek</span>
            </div>

            <div class="flow">
                @foreach($workflowSteps as $index => $step)
                    <div class="flow-row">
                        <div class="flow-no">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</div>
                        <div>
                            <div class="flow-title">{{ $step['title'] }}</div>
                            <div class="flow-desc">{{ $step['description'] }}</div>
                        </div>
                        <span class="pill">{{ $index < 2 ? 'Müşteri tarafı' : 'Operasyon' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat-box">
            <b>Tekliften Siparişe</b>
            <span>Teklif, müşteri onayı ve siparişe dönüşümü aynı kayıt üzerinde izleyin.</span>
        </div>
        <div class="stat-box">
            <b>Product Data Hub</b>
            <span>Tedarikçi ürünlerini ve kendi katalog verinizi aynı ekran mantığında yönetin.</span>
        </div>
        <div class="stat-box">
            <b>Müşteri Portalı</b>
            <span>Teklif, sipariş, grafik onayı ve dosya görünürlüğünü müşteriye güvenli açın.</span>
        </div>
        <div class="stat-box">
            <b>SaaS Yönetimi</b>
            <span>Paket, modül, limit ve talep süreçlerini firmanız büyüdükçe kontrollü genişletin.</span>
        </div>
    </div>
</section>

<section class="section anchor-offset" id="ozellikler">
    <div class="section-header">
        <div>
            <span class="section-kicker">Dağınık Takip Yerine Tek Akış</span>
            <h2>Promosyon ve baskı operasyonunda en çok zorlayan noktalar</h2>
            <p class="section-subtitle">WhatsApp, e-posta ve Excel arasında dağılmış süreçleri tek panelde toplamak için tasarlandı.</p>
        </div>
    </div>

    <div class="grid grid-3">
        @foreach($problemPoints as $point)
            <div class="card side-card">
                <h3>Sorun</h3>
                <p>{{ $point }}</p>
            </div>
        @endforeach
    </div>
</section>

<section class="section anchor-offset" id="is-akisi">
    <div class="section-header">
        <div>
            <span class="section-kicker">İş Akışı</span>
            <h2>Tekliften siparişe, üretimden teslimata kadar aynı omurga</h2>
            <p class="section-subtitle">Müşteri 500 adet / 1000 adet gibi alternatifleri veya farklı ürün seçeneklerini görüp istediğini seçerek onaylayabilir.</p>
        </div>
    </div>

    <div class="timeline">
        @foreach($workflowSteps as $index => $step)
            <div class="card timeline-step">
                <strong>{{ $index + 1 }}</strong>
                <h3>{{ $step['title'] }}</h3>
                <p>{{ $step['description'] }}</p>
            </div>
        @endforeach
    </div>
</section>

<section class="section anchor-offset" id="product-hub">
    <div class="section-header">
        <div>
            <span class="section-kicker">Product Data Hub</span>
            <h2>Promosyon ürünleri tedarikçilerinden gelen ürün bilgilerini tek katalogda toplayın.</h2>
            <p class="section-subtitle">Ürün adı, kodu, görsel, kategori, fiyat ve stok bilgisini aynı yapı içinde izleyin; kendi ürünlerinizi de ayrı yönetip teklif ekranında birlikte kullanın.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card side-card">
            <h3>Ne sağlar?</h3>
            <div class="feature-list">
                <div class="feature-item"><span class="tick">✓</span><span>Tedarikçi ürünlerini katalogda görme ve teklifte seçme</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Local ürünler ile tedarikçi ürünlerini ayrı ama birlikte yönetme</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Uyarılı ürünleri kontrol edip satış öncesi dikkat gerektiren satırları ayırma</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Katalog görünürlüğünü ekiplerin kullanacağı kadar sade tutma</span></div>
            </div>
        </div>
        <div class="card side-card">
            <h3>Örnek tedarikçi kaynakları</h3>
            <p>Prodelya içinde yönetilebilecek örnek promosyon ürün kaynakları:</p>
            <div class="pill-row">
                @foreach($supplierExamples as $supplier)
                    <span class="pill">{{ $supplier }}</span>
                @endforeach
            </div>
            <div class="soft-note amber" style="margin-top:14px;">Bu isimler örnek tedarikçi kaynaklarını anlatır. Aktif entegrasyon ve kapsam, kurulum ve paket politikasına göre belirlenir.</div>
        </div>
    </div>
</section>

<section class="section anchor-offset" id="moduller">
    <div class="section-header">
        <div>
            <span class="section-kicker">Modüller</span>
            <h2>Her modül yalnız adıyla değil, işinize ne kazandırdığıyla anlatılır</h2>
            <p class="section-subtitle">Müşteri Portalı ana giriş değildir; abone firmanın müşterilerine sunduğu kontrollü bir modül olarak konumlanır.</p>
        </div>
    </div>

    <div class="grid grid-3">
        @foreach($moduleStoryGroups as $group)
            <div class="card side-card">
                <h3>{{ $group['title'] }}</h3>
                <p>{{ $group['description'] }}</p>
                <div class="feature-list">
                    @foreach($group['items'] as $item)
                        <div class="feature-item">
                            <span class="tick">✓</span>
                            <span><strong>{{ $item['label'] }}</strong>: {{ $item['description'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>

<section class="section anchor-offset" id="paketler">
    <div class="section-header">
        <div>
            <span class="section-kicker">Paketler</span>
            <h2>Mevcut paket kayıtlarıyla uyumlu, ihtiyaca göre büyüyen paket yapısı</h2>
            <p class="section-subtitle">Paket kartları doğrudan aktif public package kayıtlarından beslenir. Fiyat tanımı yoksa sahte rakam yerine güvenli yönlendirme dili kullanılır.</p>
        </div>
    </div>

    <div class="grid grid-3">
        @forelse($packageCards as $card)
            <div class="card package-card">
                <div class="package-pill">{{ $card['package']->name }}</div>
                <h3>{{ $card['price_label'] ?: 'Teklif Al' }}</h3>
                <p class="price-meta">{{ $card['audience'] }}</p>
                <div class="feature-list">
                    @foreach($card['highlights'] as $highlight)
                        <div class="feature-item"><span class="tick">✓</span><span>{{ $highlight }}</span></div>
                    @endforeach
                </div>
                <div class="soft-note" style="margin-top:14px;">{{ $card['package']->description ?: ($card['price_label'] ? 'Paket kapsamı ve kullanım limitleri başvuru sırasında birlikte netleştirilir.' : 'Paket kapsamı görüşmeyle netleşir.') }}</div>
                <div class="actions" style="margin-top:14px;">
                    <a href="{{ route('marketing.register-interest') }}" class="btn btn-primary">1 Ay Ücretsiz Dene</a>
                    <a href="{{ route('marketing.demo-request') }}" class="btn btn-light">{{ $card['cta_label'] }}</a>
                </div>
            </div>
        @empty
            <div class="card package-card">
                <h3>Paketler yakında netleşiyor</h3>
                <p>Aktif public paket kaydı bulunmadığında uygun paket ve modül seti başvuru sırasında birlikte belirlenir.</p>
            </div>
        @endforelse
    </div>
</section>

<section class="section">
    <div class="section-header">
        <div>
            <span class="section-kicker">Müşteri Portalı ve Güven</span>
            <h2>Müşteri Portalı bir satış CTA’sı değil, müşterinize sunduğunuz kontrollü görünürlük alanıdır</h2>
            <p class="section-subtitle">Abone firmanızın müşterileri teklifleri, siparişleri ve dosyaları güvenli bağlantılar üzerinden görebilir; maliyet ve tedarikçi fiyatı gibi alanlar görünmez.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card side-card">
            <h3>Müşteri Portalında neler yapılır?</h3>
            <div class="feature-list">
                <div class="feature-item"><span class="tick">✓</span><span>Teklif görüntüleme, onay, revize veya ret</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Sipariş takibi ve teslim süreci görünürlüğü</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Grafik onayı ve dosya görünürlüğü</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Fiyat/maliyet gizliliği ve güvenli bağlantı yapısı</span></div>
            </div>
        </div>
        <div class="card side-card">
            <h3>SaaS ve veri güveni</h3>
            <div class="feature-list">
                @foreach($securityHighlights as $highlight)
                    <div class="feature-item"><span class="tick">✓</span><span>{{ $highlight }}</span></div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="section anchor-offset" id="basvuru">
    <div class="section-header">
        <div>
            <span class="section-kicker">Başvuru</span>
            <h2>Önce deneyin veya size uygun demo akışını birlikte planlayalım</h2>
            <p class="section-subtitle">Bu fazda mevcut güvenli lead akışları korunur; ana sayfa sizi doğru başvuru ekranına yönlendirir.</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card side-card">
            <h3>1 Ay Ücretsiz Dene</h3>
            <p>Ödeme alınmaz. Kredi kartı gerekmez. Başvuru incelenir; uygun görülürse Abone Firma paneli açılır.</p>
            <div class="feature-list">
                <div class="feature-item"><span class="tick">✓</span><span>Paket ve modül ihtiyacınızı iletirsiniz</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Başvuru Prodelya ekibi tarafından incelenir</span></div>
                <div class="feature-item"><span class="tick">✓</span><span>Gelişmiş Product Data Hub veya API modülleri trial politikasına göre sınırlı olabilir</span></div>
            </div>
            <div class="actions" style="margin-top:14px;">
                <a href="{{ route('marketing.register-interest') }}" class="btn btn-primary">1 Ay Ücretsiz Dene</a>
            </div>
        </div>

        <div class="card side-card">
            <h3>Demo Talep Et</h3>
            <p>Demo ücretsiz bir tanıtım görüşmesidir. Başvuru sonrası uygun zaman için iletişime geçilir ve iş akışınıza uygun ekranlar gösterilir.</p>
            <div class="pill-row">
                @foreach($demoTopicOptions as $topic)
                    <span class="pill">{{ $topic }}</span>
                @endforeach
            </div>
            <div class="actions" style="margin-top:14px;">
                <a href="{{ route('marketing.demo-request') }}" class="btn btn-success">Demo Talep Et</a>
            </div>
        </div>
    </div>
</section>
@endsection
