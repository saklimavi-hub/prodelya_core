@extends('layouts.prodelya-admin')

@section('title', 'Cari Kart İçe Aktarma')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    @if(session('info'))
        <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            {{ session('info') }}
        </div>
    @endif

    @if($errors->has('import'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $errors->first('import') }}
        </div>
    @endif

    <div class="md:flex md:items-center md:justify-between">
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                Cari Kart İçe Aktarma
            </h2>
            <p class="mt-1 text-sm text-gray-500">Toplu cari kart içe aktarma akışı henüz açılmadı. Gerçek parser, mükerrer kontrolü ve audit süreci tamamlandığında bu ekrandan kullanılacak.</p>
        </div>
        <div class="mt-4 flex md:mt-0 md:ml-4">
            <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Listeye Dön
            </a>
        </div>
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-5">
        <h3 class="text-base font-semibold text-amber-900">Modül durumu</h3>
        <p class="mt-2 text-sm text-amber-800">
            Bu ekranda gerçek dosya önizleme, alan eşleme ve kayıt oluşturma henüz aktif değildir.
            Kullanıcı güveni için sahte önizleme ve sahte import başlatma akışı kapatılmıştır.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white px-5 py-5 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Şu an kullanılabilen yol</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-600">
                <li>Manuel cari kart ekleme aktiftir.</li>
                <li>Firma detayında yetkili kişi ve adres ekleme aktiftir.</li>
                <li>Portal kullanıcısı tanımlama ilgili firma detayından yapılabilir.</li>
            </ul>
            <div class="mt-5">
                <a href="{{ route('admin.companies.create') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-sm font-medium text-white hover:bg-blue-700">
                    Manuel Cari Kart Ekle
                </a>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white px-5 py-5 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Hazırlık kapsamı</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-600">
                <li>CSV / Excel / XML parser</li>
                <li>Mükerrer kontrol ve eşleme</li>
                <li>Yetkili ve adres audit kaydı</li>
                <li>Güvenli önizleme ve toplu işlem onayı</li>
            </ul>
            <div class="mt-5">
                <form action="{{ route('admin.companies.import.template') }}" method="GET">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                        CSV Şablonu İndir
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('side_summary')
<div class="space-y-4">
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Durum</h3>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900">
            İçe aktarma modülü hazırlık aşamasındadır.
        </div>
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Kullanılabilir İşlem</h3>
        <a href="{{ route('admin.companies.create') }}" class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            Manuel Cari Kart
        </a>
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Not</h3>
        <div class="text-sm text-gray-600">
            Sahte önizleme, demo kayıt ve otomatik import başlatma akışı bu fazda kapalı tutulur.
        </div>
    </div>
</div>
@endsection
