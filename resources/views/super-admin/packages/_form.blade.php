@csrf
@if($isEdit)
    @method('PUT')
@endif

<div class="pd-card">
    <div class="pd-card-header">
        <div>
            <h3 class="pd-card-title">Paket Bilgileri</h3>
            <p class="pd-card-subtitle">Satış paketi kimliği, durum ve fiyat bilgileri.</p>
        </div>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            <div>
                <label class="pd-label">Key</label>
                <input type="text" name="key" value="{{ old('key', $package->key) }}" placeholder="starter" class="pd-input">
                @error('key')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Paket Adı</label>
                <input type="text" name="name" value="{{ old('name', $package->name) }}" placeholder="Starter" class="pd-input">
                @error('name')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Durum</label>
                <select name="status" class="pd-input">
                    @foreach(['active' => 'Aktif', 'passive' => 'Pasif', 'planned' => 'Planlandı', 'archived' => 'Arşiv'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $package->status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('status')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Deneme Süresi</label>
                <input type="number" min="0" name="trial_days" value="{{ old('trial_days', $package->trial_days) }}" placeholder="14" class="pd-input">
                @error('trial_days')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Aylık Fiyat</label>
                <input type="number" min="0" step="0.01" name="monthly_price" value="{{ old('monthly_price', $package->monthly_price) }}" class="pd-input">
                @error('monthly_price')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Yıllık Fiyat</label>
                <input type="number" min="0" step="0.01" name="yearly_price" value="{{ old('yearly_price', $package->yearly_price) }}" class="pd-input">
                @error('yearly_price')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Para Birimi</label>
                <select name="currency" class="pd-input">
                    @foreach(['TRY', 'USD', 'EUR'] as $currency)
                        <option value="{{ $currency }}" @selected(old('currency', $package->currency ?: 'TRY') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
                @error('currency')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="pd-label">Sıra</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $package->sort_order ?? 0) }}" class="pd-input">
                @error('sort_order')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
            <div class="pd-checkbox-inline" style="padding-top: 28px;">
                <input type="hidden" name="is_public" value="0">
                <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $package->is_public)) class="pd-inline-form-input">
                <label class="pd-label">Public listelerde gösterilebilir</label>
                @error('is_public')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-4">
            <label class="pd-label">Açıklama</label>
            <textarea name="description" rows="3" class="pd-input">{{ old('description', $package->description) }}</textarea>
            @error('description')<p class="pd-input-error">{{ $message }}</p>@enderror
        </div>

        <div class="mt-4">
            <label class="pd-label">Notlar</label>
            <textarea name="notes" rows="3" class="pd-input">{{ old('notes', $package->notes) }}</textarea>
            @error('notes')<p class="pd-input-error">{{ $message }}</p>@enderror
        </div>

        @php
            $processDepthValue = old('process_depth', $package->process_depth ?: \App\Support\ProcessDepth\ProcessDepth::default());
            $processDepthOptions = [
                \App\Support\ProcessDepth\ProcessDepth::FAST => 'Hızlı Akış',
                \App\Support\ProcessDepth\ProcessDepth::STANDARD => 'Standart Akış',
                \App\Support\ProcessDepth\ProcessDepth::CONTROLLED => 'Kontrollü Akış',
            ];
        @endphp

        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="flex items-start justify-between gap-3" style="margin-bottom: 12px;">
                <div>
                    <div class="pd-card-title" style="font-size: 16px;">Varsayılan Süreç Derinliği</div>
                    <p class="pd-card-subtitle" style="margin-top: 4px;">Bu seçim paketi kullanan Abone Firmalar için varsayılan çalışma şeklini belirler. Abone Firma kendi ayarından farklı bir seçim yapabilir.</p>
                </div>
                <span class="pd-badge pd-badge-blue">Lisans değil</span>
            </div>
            <div>
                <label class="pd-label" for="process_depth">Varsayılan Süreç Derinliği</label>
                <select id="process_depth" name="process_depth" class="pd-input">
                    @foreach($processDepthOptions as $value => $label)
                        <option value="{{ $value }}" @selected($processDepthValue === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="text-sm text-gray-600" style="margin-top: 8px;">Bu alan modül veya feature erişimini değiştirmez; yalnız desteklenen operasyon ekranlarındaki varsayılan çalışma şeklini belirler.</p>
                @error('process_depth')<p class="pd-input-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="pd-form-actions mt-4">
            <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
            <a href="{{ route('admin.super.packages.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        </div>
    </div>
</div>
