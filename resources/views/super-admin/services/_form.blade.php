@php
    $isEdit = ($isEdit ?? false) === true;
    $service = $service ?? null;
@endphp

@csrf
@if($isEdit)
    @method('PUT')
@endif

<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Hizmet Kalemi</h3>
                <p class="pd-section-subtitle">Super Admin tenant cari operasyonlarında kullanılacak merkezi hizmet tanımını düzenleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-form-shell-grid pd-form-shell-grid-2">
                <div>
                    <label class="pd-label" for="service_code">Hizmet Kodu</label>
                    <input id="service_code" type="text" name="service_code" value="{{ old('service_code', $service?->service_code) }}" class="pd-input" placeholder="ONBOARDING">
                    @error('service_code') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="service_name">Hizmet Adı</label>
                    <input id="service_name" type="text" name="service_name" value="{{ old('service_name', $service?->service_name) }}" class="pd-input" placeholder="Kurulum ve Onboarding">
                    @error('service_name') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="category">Kategori</label>
                    <input id="category" type="text" name="category" value="{{ old('category', $service?->category) }}" class="pd-input" placeholder="Kurulum">
                    @error('category') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="default_direction">Varsayılan Yön</label>
                    <select id="default_direction" name="default_direction" class="pd-select">
                        <option value="debit" @selected(old('default_direction', $service?->default_direction) === 'debit')>Borç</option>
                        <option value="credit" @selected(old('default_direction', $service?->default_direction) === 'credit')>Alacak</option>
                    </select>
                    @error('default_direction') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="default_amount">Varsayılan Tutar</label>
                    <input id="default_amount" type="number" step="0.01" min="0" name="default_amount" value="{{ old('default_amount', $service?->default_amount) }}" class="pd-input" placeholder="0,00">
                    @error('default_amount') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="currency">Para Birimi</label>
                    <select id="currency" name="currency" class="pd-select">
                        @foreach(['TRY' => 'TRY', 'USD' => 'USD', 'EUR' => 'EUR'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('currency', $service?->currency) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('currency') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="pd-label" for="sort_order">Sıralama</label>
                    <input id="sort_order" type="number" min="0" name="sort_order" value="{{ old('sort_order', $service?->sort_order ?? 0) }}" class="pd-input">
                    @error('sort_order') <div class="pd-input-error">{{ $message }}</div> @enderror
                </div>
                <div class="pd-checkbox-inline" style="padding-top: 28px;">
                    <input id="is_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $service?->is_active ?? true)) class="pd-inline-form-input">
                    <label class="pd-label" for="is_active">Aktif kullanılsın</label>
                </div>
            </div>
            <div class="mt-4">
                <label class="pd-label" for="description">Açıklama</label>
                <textarea id="description" name="description" rows="4" class="pd-textarea" placeholder="Tenant cari ekranında bu hizmet kaleminin ne için kullanılacağını kısaca açıklayın.">{{ old('description', $service?->description) }}</textarea>
                @error('description') <div class="pd-input-error">{{ $message }}</div> @enderror
            </div>
        </div>
    </section>

    <div class="pd-form-actions">
        <button type="submit" class="pd-btn pd-btn-primary">{{ $isEdit ? 'Kaydet' : 'Hizmet Kalemini Oluştur' }}</button>
        <a href="{{ route('admin.super.services.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
    </div>
</div>
