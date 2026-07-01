@extends('layouts.prodelya-admin')

@section('title', $pageTitle)
@section('page_title', $pageTitle)
@section('page_subtitle', $pageSubtitle)

@section('content')
<form method="POST" action="{{ $formAction }}">
    @csrf
    @if($formMethod !== 'POST')
        @method($formMethod)
    @endif

    <div class="pd-hub-family-shell">
        <section class="pd-section-card pd-section-card-soft-blue">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">{{ $tenant->name }} SaaS Cari Kaydı</h3>
                    <p class="pd-section-subtitle">Tenant müşteri carisiyle karışmadan yalnızca SaaS hizmet, paket ve tahsilat hareketlerini yönetin.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="pd-label" for="entry_type">Hareket Tipi</label>
                        <select id="entry_type" name="entry_type" class="pd-select">
                            @foreach($entryTypeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('entry_type', $entry->entry_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('entry_type') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="direction">Yön</label>
                        <select id="direction" name="direction" class="pd-select">
                            @foreach($directionOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('direction', $entry->direction) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('direction') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="title">Başlık</label>
                        <input id="title" type="text" name="title" class="pd-input" value="{{ old('title', $entry->title) }}" placeholder="Ek destek hizmeti">
                        @error('title') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="tenant_service_definition_id">Hizmet Kalemi</label>
                        <select id="tenant_service_definition_id" name="tenant_service_definition_id" class="pd-select">
                            <option value="">Seçiniz</option>
                            @foreach($serviceDefinitions as $service)
                                <option value="{{ $service->id }}" @selected((string) old('tenant_service_definition_id', $entry->tenant_service_definition_id) === (string) $service->id)>{{ $service->service_name }}</option>
                            @endforeach
                        </select>
                        @error('tenant_service_definition_id') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="amount">Tutar</label>
                        <input id="amount" type="number" min="0.01" step="0.01" name="amount" class="pd-input" value="{{ old('amount', $entry->amount) }}">
                        @error('amount') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="currency">Para Birimi</label>
                        <select id="currency" name="currency" class="pd-select">
                            @foreach(['TRY' => 'TRY', 'USD' => 'USD', 'EUR' => 'EUR'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('currency', $entry->currency) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('currency') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="entry_date">Tarih</label>
                        <input id="entry_date" type="date" name="entry_date" class="pd-input" value="{{ old('entry_date', optional($entry->entry_date)->format('Y-m-d')) }}">
                        @error('entry_date') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="pd-label" for="reference_no">Referans</label>
                        <input id="reference_no" type="text" name="reference_no" class="pd-input" value="{{ old('reference_no', $entry->reference_no) }}" placeholder="REF-20260626-001">
                        @error('reference_no') <div class="pd-input-error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label class="pd-label" for="note">Not</label>
                    <textarea id="note" name="note" rows="4" class="pd-textarea" placeholder="Bu hareketin kısa operasyon notunu yazın.">{{ old('note', $entry->note) }}</textarea>
                    @error('note') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>
        <div class="flex gap-3">
            <button type="submit" class="pd-btn pd-btn-primary">{{ $formMethod === 'POST' ? 'Cari Kaydı Oluştur' : 'Kaydet' }}</button>
            <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-light">SaaS Cariye Dön</a>
        </div>
    </div>
</form>
@endsection
