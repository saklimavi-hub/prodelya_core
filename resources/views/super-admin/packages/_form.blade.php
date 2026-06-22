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
                <label class="text-sm font-medium text-gray-700">Key</label>
                <input type="text" name="key" value="{{ old('key', $package->key) }}" placeholder="starter">
                @error('key')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Paket Adı</label>
                <input type="text" name="name" value="{{ old('name', $package->name) }}" placeholder="Starter">
                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Durum</label>
                <select name="status">
                    @foreach(['active' => 'Aktif', 'passive' => 'Pasif', 'planned' => 'Planlandı', 'archived' => 'Arşiv'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $package->status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Deneme Süresi</label>
                <input type="number" min="0" name="trial_days" value="{{ old('trial_days', $package->trial_days) }}" placeholder="14">
                @error('trial_days')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Aylık Fiyat</label>
                <input type="number" min="0" step="0.01" name="monthly_price" value="{{ old('monthly_price', $package->monthly_price) }}">
                @error('monthly_price')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Yıllık Fiyat</label>
                <input type="number" min="0" step="0.01" name="yearly_price" value="{{ old('yearly_price', $package->yearly_price) }}">
                @error('yearly_price')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Para Birimi</label>
                <select name="currency">
                    @foreach(['TRY', 'USD', 'EUR'] as $currency)
                        <option value="{{ $currency }}" @selected(old('currency', $package->currency ?: 'TRY') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
                @error('currency')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700">Sıra</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $package->sort_order ?? 0) }}">
                @error('sort_order')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-center gap-3 pt-7">
                <input type="hidden" name="is_public" value="0">
                <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $package->is_public)) style="width:auto;">
                <label class="text-sm font-medium text-gray-700">Public listelerde gösterilebilir</label>
                @error('is_public')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div style="margin-top:16px;">
            <label class="text-sm font-medium text-gray-700">Açıklama</label>
            <textarea name="description" rows="3">{{ old('description', $package->description) }}</textarea>
            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div style="margin-top:16px;">
            <label class="text-sm font-medium text-gray-700">Notlar</label>
            <textarea name="notes" rows="3">{{ old('notes', $package->notes) }}</textarea>
            @error('notes')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
            <a href="{{ route('admin.super.packages.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        </div>
    </div>
</div>
