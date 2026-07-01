@extends('layouts.public-site')

@section('title', 'Prodelya - Demo Talep Et')

@section('content')
<div class="form-shell" style="grid-template-columns:minmax(0,1fr); max-width:860px; margin:0 auto;">
    <section class="card form-card">
        <div class="eyebrow">Prodelya Demo Talebi</div>
        <h1>Demo Talep Et</h1>
        <p class="lead">Tekliften siparişe, grafik onayından üretime kadar görmek istediğiniz akışı belirtin; size uygun demo akışını planlayalım.</p>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div style="display:grid; gap:16px; grid-template-columns:repeat(2,minmax(0,1fr)); margin-bottom:18px;">
            <div class="card side-card">
                <h3>Kimler için?</h3>
                <p>Promosyon firmaları, grafik ekipleri, baskı ve tedarik operasyonu olan yapılar.</p>
            </div>
            <div class="card side-card">
                <h3>Neleri gösterebiliriz?</h3>
                <p>Teklif, sipariş, müşteri portalı, grafik onayı, iş formu, üretim ve teslimat akışları.</p>
            </div>
        </div>

        <div class="soft-note" style="margin-bottom:18px;">Demo ücretsiz bir tanıtım görüşmesidir. Kullanım amacınıza göre doğru ekranları birlikte seçeriz; başvuru sonrası uygun zaman için sizinle iletişime geçilir.</div>

        <form method="POST" action="{{ route('marketing.demo-request.store') }}">
            @csrf
            <input type="text" name="website" value="{{ old('website') }}" style="display:none" tabindex="-1" autocomplete="off">

            <div class="form-grid">
                <div>
                    <label for="company_name">Firma Adı</label>
                    <input id="company_name" name="company_name" value="{{ old('company_name') }}" required>
                </div>
                <div>
                    <label for="contact_name">Yetkili Adı Soyadı</label>
                    <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" required>
                </div>
                <div>
                    <label for="phone">Telefon</label>
                    <input id="phone" name="phone" value="{{ old('phone') }}" required>
                </div>
                <div>
                    <label for="email">E-posta</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                </div>
                <div class="full">
                    <label for="demo_topic">Demo Konusu / Kullanım Amacı</label>
                    <select id="demo_topic" name="demo_topic" required>
                        @foreach($demoTopicOptions as $topic)
                            <option value="{{ $topic }}" @selected(old('demo_topic', 'Promosyon teklif ve sipariş akışı') === $topic)>{{ $topic }}</option>
                        @endforeach
                    </select>
                    <div class="help" style="margin-top:6px;">Demo sırasında sizin iş akışınıza uygun ekranlar gösterilir.</div>
                </div>
                <div class="full">
                    <label for="message">Not</label>
                    <textarea id="message" name="message" placeholder="Özellikle görmek istediğiniz senaryolar, ekip büyüklüğü veya zaman tercihiniz...">{{ old('message') }}</textarea>
                </div>
                <div class="full actions">
                    <button type="submit" class="btn btn-primary">Demo Talebini Gönder</button>
                    <a href="{{ route('marketing.register-interest') }}" class="btn btn-light">1 Ay Ücretsiz Dene</a>
                    <a href="{{ route('marketing.home') }}" class="btn btn-light">Ana Sayfaya Dön</a>
                </div>
            </div>
        </form>
    </section>
</div>
@endsection
