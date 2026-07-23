@php
    /** @var \App\Services\TenantCatalog\LocalProductFieldCatalogService $fieldCatalog */
    $fieldCatalog = app(\App\Services\TenantCatalog\LocalProductFieldCatalogService::class);
    $isEdit = (bool) $editProduct;
    $hasVariants = $isEdit && $editProduct->variants->isNotEmpty();
    $productType = old('product_type', $hasVariants ? 'variant' : 'flat');
    $currentImage = old('image_url', $editProduct?->image_url);
    $currentCurrency = old('currency', $editProduct?->currency ?? 'TRY');
    $currentGroupCode = old('group_code', $hasVariants ? $editProduct?->display_code : '');
    $currentProductCode = old('product_code', !$hasVariants ? $editProduct?->display_code : '');
    $currentProductUrl = old('product_url', $editProduct?->product_url);
    $flatVariantAttributes = (array) data_get($editProduct?->meta, 'variant_attributes', []);

    $initialVariants = collect(old('variants', $hasVariants ? $editProduct->variants->map(function ($variant) use ($editProduct) {
        $stockRows = collect($editProduct->localStocks ?? [])->where('tenant_catalog_product_variant_id', $variant->id);

        return [
            'id' => $variant->id,
            'included' => true,
            'variant_code' => $variant->variant_code,
            'variant_name' => $variant->variant_name,
            'variant_color' => $variant->variant_color,
            'variant_size' => data_get($variant->meta, 'variant_attributes.measure', $variant->variant_size),
            'variant_dimensions' => data_get($variant->meta, 'variant_attributes.dimensions'),
            'display_price' => $variant->display_price,
            'currency' => $variant->currency ?? $editProduct->currency ?? 'TRY',
            'initial_stock' => 0,
            'image_url' => $variant->image_url,
            'visible_in_catalog' => $variant->visible_in_catalog,
            'visible_in_quote' => (bool) data_get($variant->meta, 'quote_search_visible', $editProduct->visible_in_quote),
            'is_active' => $variant->is_active,
            'remove_image' => false,
            'stock_label' => number_format((float) $stockRows->sum('quantity_on_hand'), 0, ',', '.'),
            'stock_entry_url' => route('admin.stock-purchases.create', ['variant' => $variant->id]),
        ];
    })->all() : [[
        'included' => true,
        'variant_code' => '',
        'variant_name' => '',
        'variant_color' => '',
        'variant_size' => '',
        'variant_dimensions' => '',
        'display_price' => '',
        'currency' => $currentCurrency,
        'initial_stock' => 0,
        'image_url' => '',
        'visible_in_catalog' => true,
        'visible_in_quote' => true,
        'is_active' => true,
        'remove_image' => false,
        'stock_label' => '0',
        'stock_entry_url' => null,
    ]]))->values();

    $flatStockLabel = number_format((float) ($editProduct?->localStocks?->sum('quantity_on_hand') ?? $editProduct?->local_stock_quantity ?? 0), 0, ',', '.');
@endphp

<form action="{{ $isEdit ? route('admin.catalog.local-products.update', $editProduct) : route('admin.catalog.local-products.store') }}" method="POST" enctype="multipart/form-data" class="pd-local-product-form" novalidate>
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    @if($errors->any())
        <div class="pd-local-product-alert pd-local-product-alert-danger">
            <strong>Formu kaydetmeden önce aşağıdaki alanları düzeltin.</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="pd-local-product-form-card">
        <div class="pd-local-product-form-card-head">
            <div>
                <h3>1. Kimlik ve Ortak Bilgiler</h3>
                <p>Kendi ürünün temel kimliği, kategori, açıklama ve bağlantı alanları bu bölümde yönetilir.</p>
            </div>
        </div>
        <div class="pd-local-product-form-grid pd-local-product-form-grid-2">
            <div class="pd-local-product-form-grid-span-full">
                <label class="pd-label">Ürün Tipi</label>
                <div class="pd-local-product-toggle-grid">
                    <label class="pd-local-product-check"><input type="radio" name="product_type" value="flat" data-local-product-type @checked($productType === 'flat')> <span>Varyantsız Exact Ürün</span></label>
                    <label class="pd-local-product-check"><input type="radio" name="product_type" value="variant" data-local-product-type @checked($productType === 'variant')> <span>Varyantlı Ürün Grubu</span></label>
                </div>
            </div>
            <div>
                <label class="pd-label" for="product_name">{{ $fieldCatalog->label('product_name') }}</label>
                <input id="product_name" name="product_name" class="pd-input @error('product_name') pd-input-error @enderror" value="{{ old('product_name', $editProduct?->product_name) }}" required>
                @error('product_name')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div data-flat-only @if($productType !== 'flat') hidden @endif>
                <label class="pd-label" for="product_code">{{ $fieldCatalog->label('product_code') }}</label>
                <input id="product_code" name="product_code" class="pd-input @error('product_code') pd-input-error @enderror" value="{{ $currentProductCode }}">
                @error('product_code')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div data-variant-only @if($productType !== 'variant') hidden @endif>
                <label class="pd-label" for="group_code">Grup Kodu</label>
                <input id="group_code" name="group_code" class="pd-input @error('group_code') pd-input-error @enderror" value="{{ $currentGroupCode }}">
                @error('group_code')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pd-label" for="standard_category_id">{{ $fieldCatalog->label('category') }}</label>
                <select id="standard_category_id" name="standard_category_id" class="pd-select @error('standard_category_id') pd-input-error @enderror">
                    <option value="">Kategori seçin</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('standard_category_id', $editProduct?->standard_category_id) === (string) $category->id)>{{ $category->full_path }}</option>
                    @endforeach
                </select>
                @error('standard_category_id')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pd-label" for="product_url">{{ $fieldCatalog->label('product_url') }}</label>
                <input id="product_url" name="product_url" class="pd-input @error('product_url') pd-input-error @enderror" value="{{ $currentProductUrl }}" placeholder="https://ornek.com/urun-sayfasi">
                @error('product_url')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="pd-local-product-form-grid-span-full">
                <label class="pd-label" for="description">{{ $fieldCatalog->label('description') }}</label>
                <textarea id="description" name="description" class="pd-textarea @error('description') pd-input-error @enderror" rows="4">{{ old('description', $editProduct?->description) }}</textarea>
                @error('description')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </section>

    <section class="pd-local-product-form-card">
        <div class="pd-local-product-form-card-head">
            <div>
                <h3>2. Görsel ve Yayın Ayarları</h3>
                <p>Galerinin ana görseli, yükleme alanı ve katalog görünürlüğü bu bölümden kontrol edilir.</p>
            </div>
        </div>
        <div class="pd-local-product-form-grid pd-local-product-form-grid-2">
            <div>
                <label class="pd-label" for="image_upload">Bilgisayardan Görsel Seç</label>
                <input id="image_upload" name="image_upload" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="pd-input @error('image_upload') pd-input-error @enderror" data-local-product-image-upload>
                <div class="pd-local-product-help">JPG, PNG veya WEBP. Maksimum 5 MB.</div>
                @error('image_upload')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pd-label" for="image_url">{{ $fieldCatalog->label('image_url') }}</label>
                <input id="image_url" name="image_url" class="pd-input @error('image_url') pd-input-error @enderror" value="{{ $currentImage }}" placeholder="https://ornek.com/urun-gorseli.webp" data-local-product-image-url>
                <div class="pd-local-product-help">Exact varyantta ayrı görsel yoksa bu ana görsel kullanılır.</div>
                @error('image_url')<div class="pd-local-product-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="pd-local-product-form-grid-span-full pd-local-product-toggle-grid">
                <label class="pd-local-product-check"><input type="checkbox" name="visible_in_catalog" value="1" @checked(old('visible_in_catalog', $editProduct?->visible_in_catalog ?? true))> <span>{{ $fieldCatalog->label('catalog_visible') }}</span></label>
                <label class="pd-local-product-check"><input type="checkbox" name="visible_in_quote" value="1" @checked(old('visible_in_quote', $editProduct?->visible_in_quote ?? true))> <span>{{ $fieldCatalog->label('quote_visible') }}</span></label>
                <label class="pd-local-product-check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editProduct?->is_active ?? true))> <span>{{ $fieldCatalog->label('status') }}</span></label>
                <label class="pd-local-product-check"><input type="checkbox" name="remove_image" value="1" @checked(old('remove_image', false))> <span>Mevcut ana görseli kaldır</span></label>
            </div>
        </div>
    </section>

    <section class="pd-local-product-form-card" data-flat-only @if($productType !== 'flat') hidden @endif>
        <div class="pd-local-product-form-card-head">
            <div>
                <h3>3. Exact Ürün Alanları</h3>
                <p>Varyantsız own-product için exact SKU, fiyat, renk, ölçü, ebat ve stok alanları burada yönetilir.</p>
            </div>
        </div>
        <div class="pd-local-product-form-grid pd-local-product-form-grid-3">
            <div>
                <label class="pd-label" for="display_price">{{ $fieldCatalog->label('list_price') }}</label>
                <input id="display_price" name="display_price" type="number" step="0.01" min="0" class="pd-input @error('display_price') pd-input-error @enderror" value="{{ old('display_price', !$hasVariants ? $editProduct?->display_price : null) }}">
            </div>
            <div>
                <label class="pd-label" for="currency">{{ $fieldCatalog->label('currency') }}</label>
                <select id="currency" name="currency" class="pd-select @error('currency') pd-input-error @enderror">
                    @foreach(['TRY' => 'TL', 'USD' => 'USD', 'EUR' => 'EUR'] as $code => $label)
                        <option value="{{ $code }}" @selected((string) $currentCurrency === (string) $code || ((string) $currentCurrency === 'TL' && $code === 'TRY'))>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="pd-label" for="vat_rate">{{ $fieldCatalog->label('vat_rate') }}</label>
                <input id="vat_rate" name="vat_rate" type="number" step="0.01" min="0" max="100" class="pd-input" value="{{ old('vat_rate', data_get($editProduct?->meta, 'price_snapshot.vat_rate', 20)) }}">
            </div>
            <div>
                <label class="pd-label" for="variant_color">{{ $fieldCatalog->label('color') }}</label>
                <input id="variant_color" name="variant_color" class="pd-input" value="{{ old('variant_color', data_get($flatVariantAttributes, 'color')) }}">
            </div>
            <div>
                <label class="pd-label" for="variant_size">{{ $fieldCatalog->label('measure') }}</label>
                <input id="variant_size" name="variant_size" class="pd-input" value="{{ old('variant_size', data_get($flatVariantAttributes, 'measure')) }}">
            </div>
            <div>
                <label class="pd-label" for="variant_dimensions">{{ $fieldCatalog->label('dimensions') }}</label>
                <input id="variant_dimensions" name="variant_dimensions" class="pd-input" value="{{ old('variant_dimensions', data_get($flatVariantAttributes, 'dimensions')) }}">
            </div>
            <div>
                <label class="pd-label" for="local_stock_quantity">{{ $fieldCatalog->label('initial_stock') }}</label>
                @if($isEdit)
                    <div class="pd-local-product-form-hint-box">
                        <strong>{{ $flatStockLabel }}</strong>
                        <span>Stok alanı bu ekranda sadece okunur. Canonical giriş için Stok Girişi / Satın Alma ekranını kullanın.</span>
                    </div>
                @else
                    <input id="local_stock_quantity" name="local_stock_quantity" type="number" step="0.01" min="0" class="pd-input" value="{{ old('local_stock_quantity', 0) }}">
                @endif
            </div>
            @if($isEdit)
                <div class="pd-local-product-form-grid-span-2 pd-local-product-form-hint-box">
                    <strong>Stok değişikliği</strong>
                    <span><a href="{{ route('admin.stock-purchases.create', ['product' => $editProduct->id]) }}">Stok Girişi / Satın Alma</a> ekranından yapılır.</span>
                </div>
            @endif
        </div>
    </section>

    <section class="pd-local-product-form-card" data-variant-only @if($productType !== 'variant') hidden @endif>
        <div class="pd-local-product-form-card-head">
            <div>
                <h3>3. Exact Varyantlar</h3>
                <p>Parent ortak metadata taşır; her exact varyant kendi SKU, fiyat, stok, renk, ölçü, ebat ve görsel alanını yönetir.</p>
            </div>
            <button type="button" class="pd-btn pd-btn-light" data-add-variant>Varyant Ekle</button>
        </div>
        <div class="pd-local-product-table-wrap">
            <table class="pd-table pd-local-product-table">
                <thead>
                    <tr>
                        <th>Dahil</th>
                        <th>{{ $fieldCatalog->label('product_code') }}</th>
                        <th>Görünen Ad</th>
                        <th>{{ $fieldCatalog->label('color') }}</th>
                        <th>{{ $fieldCatalog->label('measure') }}</th>
                        <th>{{ $fieldCatalog->label('dimensions') }}</th>
                        <th>{{ $fieldCatalog->label('list_price') }}</th>
                        <th>{{ $fieldCatalog->label('currency') }}</th>
                        <th>{{ $fieldCatalog->label('initial_stock') }}</th>
                        <th>{{ $fieldCatalog->label('image_url') }}</th>
                        <th>Durum</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody data-variant-rows>
                    @foreach($initialVariants as $index => $variant)
                        <tr data-variant-row>
                            <td>
                                <input type="hidden" name="variants[{{ $index }}][id]" value="{{ $variant['id'] ?? '' }}">
                                <label class="pd-local-product-check"><input type="checkbox" name="variants[{{ $index }}][included]" value="1" @checked(data_get($variant, 'included', true))> <span></span></label>
                            </td>
                            <td><input name="variants[{{ $index }}][variant_code]" class="pd-input" value="{{ $variant['variant_code'] ?? '' }}"></td>
                            <td><input name="variants[{{ $index }}][variant_name]" class="pd-input" value="{{ $variant['variant_name'] ?? '' }}" placeholder="İsteğe bağlı exact ad"></td>
                            <td><input name="variants[{{ $index }}][variant_color]" class="pd-input" value="{{ $variant['variant_color'] ?? '' }}"></td>
                            <td><input name="variants[{{ $index }}][variant_size]" class="pd-input" value="{{ $variant['variant_size'] ?? '' }}"></td>
                            <td><input name="variants[{{ $index }}][variant_dimensions]" class="pd-input" value="{{ $variant['variant_dimensions'] ?? '' }}"></td>
                            <td><input name="variants[{{ $index }}][display_price]" type="number" step="0.01" min="0" class="pd-input" value="{{ $variant['display_price'] ?? '' }}"></td>
                            <td>
                                <select name="variants[{{ $index }}][currency]" class="pd-select">
                                    @foreach(['TRY' => 'TL', 'USD' => 'USD', 'EUR' => 'EUR'] as $code => $label)
                                        <option value="{{ $code }}" @selected((string) ($variant['currency'] ?? 'TRY') === (string) $code || ((string) ($variant['currency'] ?? '') === 'TL' && $code === 'TRY'))>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                @if($isEdit)
                                    <div class="pd-local-product-help"><strong>{{ $fieldCatalog->label('initial_stock') }}:</strong> {{ $variant['stock_label'] ?? '0' }}</div>
                                    @if(!empty($variant['stock_entry_url']))
                                        <a href="{{ $variant['stock_entry_url'] }}" class="pd-local-product-help">Stok girişine git</a>
                                    @endif
                                    <input type="hidden" name="variants[{{ $index }}][initial_stock]" value="0">
                                @else
                                    <input name="variants[{{ $index }}][initial_stock]" type="number" step="0.01" min="0" class="pd-input" value="{{ $variant['initial_stock'] ?? 0 }}">
                                @endif
                            </td>
                            <td>
                                <input name="variants[{{ $index }}][image_url]" class="pd-input" value="{{ $variant['image_url'] ?? '' }}" placeholder="https://...">
                                <input name="variant_image_uploads[{{ $index }}]" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="pd-input" style="margin-top:6px;">
                                <label class="pd-local-product-check" style="margin-top:6px;"><input type="checkbox" name="variants[{{ $index }}][remove_image]" value="1" @checked(data_get($variant, 'remove_image', false))> <span>Kaldır</span></label>
                            </td>
                            <td>
                                <label class="pd-local-product-check"><input type="checkbox" name="variants[{{ $index }}][visible_in_catalog]" value="1" @checked(data_get($variant, 'visible_in_catalog', true))> <span>Katalog</span></label>
                                <label class="pd-local-product-check"><input type="checkbox" name="variants[{{ $index }}][visible_in_quote]" value="1" @checked(data_get($variant, 'visible_in_quote', true))> <span>Teklif</span></label>
                                <label class="pd-local-product-check"><input type="checkbox" name="variants[{{ $index }}][is_active]" value="1" @checked(data_get($variant, 'is_active', true))> <span>Aktif</span></label>
                            </td>
                            <td><button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-remove-variant>Satırı Kaldır</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="pd-local-product-sticky-actions">
        <div>
            <strong>{{ $isEdit ? 'Ürünü Düzenle' : 'Yeni Ürün Ekle' }}</strong>
            <span>{{ $isEdit ? 'Değişiklikler same-field-contract ile kaydedilir ve stok alanı read-only kalır.' : 'Kaydettiğinizde ürün canonical katalog ve teklif DTO yüzeylerine bağlanır.' }}</span>
        </div>
        <div class="pd-local-product-sticky-actions-buttons">
            <button type="submit" class="pd-btn pd-btn-primary">{{ $isEdit ? 'Değişiklikleri Kaydet' : 'Ürünü Kaydet' }}</button>
            <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Vazgeç</a>
        </div>
    </div>
</form>

<template data-variant-row-template>
    <tr data-variant-row>
        <td><label class="pd-local-product-check"><input type="checkbox" data-name="included" value="1" checked> <span></span></label></td>
        <td><input data-name="variant_code" class="pd-input"></td>
        <td><input data-name="variant_name" class="pd-input" placeholder="İsteğe bağlı exact ad"></td>
        <td><input data-name="variant_color" class="pd-input"></td>
        <td><input data-name="variant_size" class="pd-input"></td>
        <td><input data-name="variant_dimensions" class="pd-input"></td>
        <td><input data-name="display_price" type="number" step="0.01" min="0" class="pd-input"></td>
        <td><select data-name="currency" class="pd-select"><option value="TRY">TL</option><option value="USD">USD</option><option value="EUR">EUR</option></select></td>
        <td><input data-name="initial_stock" type="number" step="0.01" min="0" class="pd-input" value="0"></td>
        <td><input data-name="image_url" class="pd-input" placeholder="https://..."><input type="file" data-upload class="pd-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="margin-top:6px;"></td>
        <td>
            <label class="pd-local-product-check"><input type="checkbox" data-name="visible_in_catalog" value="1" checked> <span>Katalog</span></label>
            <label class="pd-local-product-check"><input type="checkbox" data-name="visible_in_quote" value="1" checked> <span>Teklif</span></label>
            <label class="pd-local-product-check"><input type="checkbox" data-name="is_active" value="1" checked> <span>Aktif</span></label>
        </td>
        <td><button type="button" class="pd-btn pd-btn-sm pd-btn-light" data-remove-variant>Satırı Kaldır</button></td>
    </tr>
</template>

<script>
(function () {
    const typeInputs = document.querySelectorAll('[data-local-product-type]');
    const flatBlocks = document.querySelectorAll('[data-flat-only]');
    const variantBlocks = document.querySelectorAll('[data-variant-only]');
    const uploadInput = document.querySelector('[data-local-product-image-upload]');
    const urlInput = document.querySelector('[data-local-product-image-url]');
    const previewImage = document.querySelector('[data-local-product-preview-image]');
    const previewEmpty = document.querySelector('[data-local-product-preview-empty]');
    const variantTableBody = document.querySelector('[data-variant-rows]');
    const variantTemplate = document.querySelector('[data-variant-row-template]');
    const addVariantButton = document.querySelector('[data-add-variant]');

    const toggleSections = (type) => {
        flatBlocks.forEach((node) => node.hidden = type !== 'flat');
        variantBlocks.forEach((node) => node.hidden = type !== 'variant');
    };

    typeInputs.forEach((input) => {
        input.addEventListener('change', () => toggleSections(input.value));
    });

    if (previewImage && previewEmpty && uploadInput && urlInput) {
        const showImage = (src) => {
            if (!src) {
                previewImage.setAttribute('hidden', 'hidden');
                previewImage.removeAttribute('src');
                previewEmpty.removeAttribute('hidden');
                return;
            }
            previewImage.src = src;
            previewImage.removeAttribute('hidden');
            previewEmpty.setAttribute('hidden', 'hidden');
        };

        uploadInput.addEventListener('change', (event) => {
            const file = event.target.files && event.target.files[0];
            if (!file) {
                showImage(urlInput.value.trim());
                return;
            }
            showImage(URL.createObjectURL(file));
        });

        urlInput.addEventListener('input', (event) => {
            if (uploadInput.files && uploadInput.files.length > 0) {
                return;
            }
            showImage(event.target.value.trim());
        });
    }

    const reindexVariantRows = () => {
        if (!variantTableBody) {
            return;
        }
        Array.from(variantTableBody.querySelectorAll('[data-variant-row]')).forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((element) => {
                element.name = element.name.replace(/variants\[\d+\]/, `variants[${index}]`);
            });
            row.querySelectorAll('[data-name]').forEach((element) => {
                element.name = `variants[${index}][${element.getAttribute('data-name')}]`;
            });
            row.querySelectorAll('[data-upload]').forEach((element) => {
                element.name = `variant_image_uploads[${index}]`;
            });
        });
    };

    if (variantTableBody) {
        variantTableBody.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-variant]');
            if (!button) {
                return;
            }
            const rows = variantTableBody.querySelectorAll('[data-variant-row]');
            if (rows.length === 1) {
                return;
            }
            button.closest('[data-variant-row]')?.remove();
            reindexVariantRows();
        });
    }

    if (addVariantButton && variantTemplate && variantTableBody) {
        addVariantButton.addEventListener('click', () => {
            const fragment = variantTemplate.content.cloneNode(true);
            variantTableBody.appendChild(fragment);
            reindexVariantRows();
        });
        reindexVariantRows();
    }

    const checkedType = Array.from(typeInputs).find((input) => input.checked)?.value || 'flat';
    toggleSections(checkedType);
})();
</script>
