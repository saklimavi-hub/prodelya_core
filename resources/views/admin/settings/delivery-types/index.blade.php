@extends('layouts.prodelya-admin')

@section('title', 'Teslimat Tipleri')
@section('page_title', 'Teslimat Tipleri')
@section('page_subtitle', 'Teklif, sipariş ve teslimat süreçlerinde kullanılacak teslimat tiplerini yönetin.')

@section('content')
<style>
    .tdt-shell { display: grid; gap: 16px; }
    .tdt-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04); }
    .tdt-card-body { padding: 18px; }
    .tdt-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr); gap: 16px; align-items: start; }
    .tdt-list { display: grid; gap: 12px; }
    .tdt-item { border: 1px solid #e5e7eb; border-radius: 8px; background: #fbfcfe; padding: 14px; }
    .tdt-item-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    .tdt-item-title { margin: 0; font-size: 16px; color: #172033; }
    .tdt-item-meta { color: #6b7280; font-size: 12px; line-height: 1.5; margin-top: 4px; }
    .tdt-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .tdt-form-grid .full { grid-column: 1 / -1; }
    .tdt-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 700; }
    .tdt-check { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; color: #334155; font-size: 13px; }
    .tdt-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
    .tdt-note { border: 1px solid #dbeafe; border-radius: 8px; background: #f8fbff; padding: 12px 14px; color: #526174; font-size: 12px; line-height: 1.55; }
    @media (max-width: 1100px) { .tdt-grid { grid-template-columns: 1fr; } }
    @media (max-width: 760px) { .tdt-form-grid { grid-template-columns: 1fr; } }
</style>

<div class="tdt-shell">
    @if(session('success'))
        <div class="pd-alert">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-alert-warning">{{ $errors->first() }}</div>
    @endif

    <div class="tdt-grid">
        <section class="tdt-card">
            <div class="tdt-card-body">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">Aktif ve Pasif Teslimat Tipleri</h2>
                        <p class="text-sm text-gray-500 mt-1">Varsayılan teslimat tipi teklif oluştururken seçili gelir. Pasif tipler yeni tekliflerde kullanılmaz.</p>
                    </div>
                    <a href="{{ route('admin.settings') }}?tab=company-profile" class="pd-btn pd-btn-light">Kurulum Merkezi</a>
                </div>

                <div class="tdt-list mt-4">
                    @foreach($deliveryTypes as $deliveryType)
                        <article class="tdt-item">
                            <div class="tdt-item-head">
                                <div>
                                    <h3 class="tdt-item-title">{{ $deliveryType->name }}</h3>
                                    <div class="tdt-item-meta">
                                        Kod: {{ $deliveryType->code }} · Sıra: {{ $deliveryType->sort_order }} · {{ $deliveryType->is_active ? 'Aktif' : 'Pasif' }}
                                        @if($deliveryType->is_default)
                                            · Varsayılan
                                        @endif
                                    </div>
                                </div>
                                <div class="flex gap-2 flex-wrap">
                                    <span class="badge {{ $deliveryType->is_active ? 'badge-green' : 'badge-gray' }}">{{ $deliveryType->is_active ? 'Aktif' : 'Pasif' }}</span>
                                    @if($deliveryType->is_default)
                                        <span class="badge badge-blue">Varsayılan</span>
                                    @endif
                                </div>
                            </div>

                            <form method="POST" action="{{ route('admin.settings.delivery-types.update', $deliveryType) }}" class="mt-4">
                                @csrf
                                @method('PUT')
                                <div class="tdt-form-grid">
                                    <div class="tdt-field">
                                        <label>Ad</label>
                                        <input type="text" name="name" value="{{ old('name.' . $deliveryType->id, $deliveryType->name) }}">
                                    </div>
                                    <div class="tdt-field">
                                        <label>Kod</label>
                                        <input type="text" name="code" value="{{ old('code.' . $deliveryType->id, $deliveryType->code) }}">
                                    </div>
                                    <div class="tdt-field full">
                                        <label>Açıklama</label>
                                        <input type="text" name="description" value="{{ old('description.' . $deliveryType->id, $deliveryType->description) }}">
                                    </div>
                                    <div class="tdt-field">
                                        <label>Sıra</label>
                                        <input type="number" min="0" max="9999" name="sort_order" value="{{ old('sort_order.' . $deliveryType->id, $deliveryType->sort_order) }}">
                                    </div>
                                    <div class="tdt-field">
                                        <label class="block">Durum</label>
                                        <label class="tdt-check">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active.' . $deliveryType->id, $deliveryType->is_active))>
                                            Aktif kullanılsın
                                        </label>
                                        <label class="tdt-check">
                                            <input type="hidden" name="is_default" value="0">
                                            <input type="checkbox" name="is_default" value="1" @checked(old('is_default.' . $deliveryType->id, $deliveryType->is_default))>
                                            Varsayılan olsun
                                        </label>
                                    </div>
                                </div>
                                <div class="tdt-actions">
                                    <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
                                </div>
                            </form>
                            @unless($deliveryType->is_default)
                                <form method="POST" action="{{ route('admin.settings.delivery-types.default', $deliveryType) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="pd-btn pd-btn-light">Varsayılan Yap</button>
                                </form>
                            @endunless
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <aside class="tdt-card">
            <div class="tdt-card-body">
                <h3 class="text-lg font-semibold text-gray-900">Yeni Teslimat Tipi</h3>
                <p class="text-sm text-gray-500 mt-1">Abone firmanıza özel yeni teslimat tipi ekleyebilirsiniz.</p>

                <form method="POST" action="{{ route('admin.settings.delivery-types.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div class="tdt-form-grid">
                        <div class="tdt-field">
                            <label>Ad</label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="Örn. Ofis Teslim">
                        </div>
                        <div class="tdt-field">
                            <label>Kod</label>
                            <input type="text" name="code" value="{{ old('code') }}" placeholder="Otomatik üretilebilir">
                        </div>
                        <div class="tdt-field full">
                            <label>Açıklama</label>
                            <input type="text" name="description" value="{{ old('description') }}" placeholder="Kısa açıklama">
                        </div>
                        <div class="tdt-field">
                            <label>Sıra</label>
                            <input type="number" min="0" max="9999" name="sort_order" value="{{ old('sort_order', 100) }}">
                        </div>
                        <div class="tdt-field">
                            <label class="block">Durum</label>
                            <label class="tdt-check">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                                Aktif kullanılsın
                            </label>
                            <label class="tdt-check">
                                <input type="hidden" name="is_default" value="0">
                                <input type="checkbox" name="is_default" value="1" @checked(old('is_default', false))>
                                Varsayılan olsun
                            </label>
                        </div>
                    </div>
                    <div class="tdt-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Teslimat Tipi Ekle</button>
                    </div>
                </form>

                <div class="tdt-note mt-4">
                    <strong>Ticari teslimat tipi</strong> teklif ve siparişte görünen iş etiketidir. <strong>Operasyonel teslim yöntemi</strong> ise teslimat ekranındaki kargo, kurye, ambar gibi saha bilgisidir. Bu iki kavram birbirini destekler ama aynı alan değildir.
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
