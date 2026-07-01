@extends('layouts.public-site')

@section('title', 'Prodelya - 1 Ay Ücretsiz Dene')

@section('content')
<div class="form-shell">
    <section class="card form-card">
        <div class="eyebrow">Prodelya Üyelik Başvurusu</div>
        <h1>1 Ay Ücretsiz Dene</h1>
        <p class="lead">Paket ve modül tercihlerinizi iletin. Prodelya ekibi deneme kurulum planınızı sizinle birlikte netleştirsin.</p>

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

        <div class="soft-note success" style="margin-bottom:18px;">Bu başvuruda ödeme alınmaz ve kredi kartı gerekmez. Başvuru sonrası sizinle iletişime geçilir; uygun görülürse Abone Firma paneliniz planlanır.</div>

        <form method="POST" action="{{ route('marketing.register-interest.store') }}">
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
                <div>
                    <label for="city">Şehir</label>
                    <input id="city" name="city" value="{{ old('city') }}">
                </div>
                <div>
                    <label for="business_type">Sektör / İş Tipi</label>
                    <input id="business_type" name="business_type" value="{{ old('business_type') }}">
                </div>
                <div>
                    <label for="requested_package_id">Tercih Edilen Paket</label>
                    <select id="requested_package_id" name="requested_package_id">
                        <option value="">Paket seçiniz</option>
                        @foreach($packageOptions as $package)
                            <option value="{{ $package->id }}" @selected((string) old('requested_package_id') === (string) $package->id)>{{ $package->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="expected_user_count">Beklenen Kullanıcı Sayısı</label>
                    <input id="expected_user_count" name="expected_user_count" type="number" min="1" max="10000" value="{{ old('expected_user_count') }}">
                </div>
                <div class="full">
                    <label>Tercih Edilen Modüller</label>
                    <div class="module-list">
                        @forelse($moduleOptions as $key => $module)
                            <label class="module-item">
                                <input type="checkbox" name="selected_modules[]" value="{{ $key }}" @checked(in_array($key, old('selected_modules', []), true))>
                                <strong>{{ $module['label'] }}</strong>
                                <span class="small-muted">{{ $module['description'] }}</span>
                            </label>
                        @empty
                            <div class="help">Public seçim için hazır modül bulunmuyor.</div>
                        @endforelse
                    </div>
                </div>
                <div class="full">
                    <label for="message">Not</label>
                    <textarea id="message" name="message" placeholder="Mevcut sipariş hacminiz, ihtiyaç duyduğunuz akışlar veya özel beklentileriniz...">{{ old('message') }}</textarea>
                </div>
                <div class="full actions">
                    <button type="submit" class="btn btn-success">Başvuruyu Gönder</button>
                    <a href="{{ route('marketing.demo-request') }}" class="btn btn-light">Demo Talep Et</a>
                    <a href="{{ route('marketing.home') }}" class="btn btn-light">Ana Sayfaya Dön</a>
                </div>
            </div>
        </form>
    </section>

    <aside class="side-stack">
        <section class="card side-card">
            <h3>Paketler</h3>
            <div class="grid">
                @forelse($packageOptions as $package)
                    <div class="package-card" style="padding:16px; border:1px solid var(--border); border-radius:8px;">
                        <div class="package-pill">{{ $package->name }}</div>
                        <p>{{ $package->description ?: 'Paket detayları satış görüşmesinde netleştirilir.' }}</p>
                        <div class="pill-row">
                            <span class="pill">{{ $package->safeStatusLabel() }}</span>
                            <span class="pill">Deneme: {{ $package->trial_days ?: 30 }} gün</span>
                        </div>
                    </div>
                @empty
                    <p class="small-muted">Public listede gösterilecek aktif paket bulunmuyor.</p>
                @endforelse
            </div>
        </section>

        <section class="card side-card">
            <h3>Bilgi</h3>
            <div class="info-note">Bu aşamada ödeme alınmaz. Kredi kartı gerekmez. Başvurunuz incelenir ve uygun paket ile modül seti sizinle netleştirilir.</div>
        </section>

        <section class="card side-card">
            <h3>Paketleri İncele</h3>
            <p class="small-muted">Önce paketleri görmek isterseniz public ana sayfadaki paket bölümüne dönerek kapsamı karşılaştırabilirsiniz.</p>
            <div class="actions" style="margin-top:12px;">
                <a href="{{ route('marketing.home') }}#paketler" class="btn btn-light">Paketlere Git</a>
            </div>
        </section>
    </aside>
</div>
@endsection
