@extends('layouts.prodelya-admin')

@section('title', $package->name . ' Düzenle')
@section('page_title', $package->name . ' Düzenle')
@section('page_subtitle', 'Temel paket bilgileri, modüller, feature’lar ve limitleri yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.packages.show', $package) }}" class="pd-btn pd-btn-light">Detayı Aç</a>
    <a href="{{ route('admin.super.packages.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
@if(session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
@endif

<div class="pd-grid" style="grid-template-columns: minmax(0, 1fr); gap:16px;">
    <form method="POST" action="{{ route('admin.super.packages.update', $package) }}">
        @include('super-admin.packages._form', ['isEdit' => true])
    </form>

    <form method="POST" action="{{ route('admin.super.packages.modules.update', $package) }}">
        @csrf
        @method('PUT')
        <div class="pd-card">
            <div class="pd-card-header">
                <div>
                    <h3 class="pd-card-title">Modül Yönetimi</h3>
                    <p class="pd-card-subtitle">Canonical module catalog üzerinden paket default modüllerini yönetin.</p>
                </div>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Açık</th><th>Modül</th><th>Kategori</th><th>Durum</th><th>Not</th></tr></thead>
                        <tbody>
                            @foreach($moduleCatalog as $module)
                                <tr>
                                    <td>
                                        @if($module['locked'])
                                            <input type="checkbox" checked disabled style="width:auto;">
                                        @else
                                            <input type="checkbox" name="modules[]" value="{{ $module['key'] }}" @checked($module['enabled']) style="width:auto;">
                                        @endif
                                    </td>
                                    <td><div class="font-medium">{{ $module['label'] }}</div><div class="text-sm text-gray-500">{{ $module['key'] }}</div></td>
                                    <td>{{ $module['category'] }}</td>
                                    <td><span class="pd-badge {{ $module['locked'] ? 'pd-badge-blue' : ($module['status'] === 'active' ? 'pd-badge-green' : 'pd-badge-amber') }}">{{ $module['locked'] ? 'Core' : ucfirst($module['status']) }}</span></td>
                                    <td>{{ $module['description'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4"><button type="submit" class="pd-btn pd-btn-primary">Modülleri Kaydet</button></div>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.super.packages.features.update', $package) }}">
        @csrf
        @method('PUT')
        <div class="pd-card">
            <div class="pd-card-header">
                <div>
                    <h3 class="pd-card-title">Feature Yönetimi</h3>
                    <p class="pd-card-subtitle">Module disabled ise feature erişimi effective olarak açılmayabilir.</p>
                </div>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Açık</th><th>Feature</th><th>Modül</th><th>Durum</th></tr></thead>
                        <tbody>
                            @foreach($featureCatalog as $feature)
                                <tr>
                                    <td><input type="checkbox" name="features[]" value="{{ $feature['key'] }}" @checked($feature['enabled']) style="width:auto;"></td>
                                    <td><div class="font-medium">{{ $feature['label'] }}</div><div class="text-sm text-gray-500">{{ $feature['key'] }}</div></td>
                                    <td>{{ $feature['module_label'] }}</td>
                                    <td><span class="pd-badge {{ $feature['status'] === 'active' ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ ucfirst($feature['status']) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4"><button type="submit" class="pd-btn pd-btn-primary">Feature’ları Kaydet</button></div>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.super.packages.limits.update', $package) }}">
        @csrf
        @method('PUT')
        <div class="pd-card">
            <div class="pd-card-header">
                <div>
                    <h3 class="pd-card-title">Limit Yönetimi</h3>
                    <p class="pd-card-subtitle">Usage snapshot için paket bazlı default limitler.</p>
                </div>
            </div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Alan</th><th>Limit</th><th>Limitsiz</th><th>Not</th></tr></thead>
                        <tbody>
                            @foreach($limitRows as $limit)
                                <tr>
                                    <td>{{ $limit['label'] }}</td>
                                    <td><input type="number" min="0" name="limits[{{ $limit['key'] }}][limit_value]" value="{{ old('limits.' . $limit['key'] . '.limit_value', $limit['limit_value']) }}"></td>
                                    <td>
                                        <input type="hidden" name="limits[{{ $limit['key'] }}][is_unlimited]" value="0">
                                        <input type="checkbox" name="limits[{{ $limit['key'] }}][is_unlimited]" value="1" @checked(old('limits.' . $limit['key'] . '.is_unlimited', $limit['is_unlimited'])) style="width:auto;">
                                    </td>
                                    <td><input type="text" name="limits[{{ $limit['key'] }}][notes]" value="{{ old('limits.' . $limit['key'] . '.notes', $limit['notes']) }}"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4"><button type="submit" class="pd-btn pd-btn-primary">Limitleri Kaydet</button></div>
            </div>
        </div>
    </form>
</div>
@endsection
