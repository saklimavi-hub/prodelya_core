@php
    $isEdit = $mode === 'edit';
    $submitRoute = $isEdit
        ? route('admin.super.standard-categories.update', $category)
        : route('admin.super.standard-categories.store');
    $backRoute = route('admin.super.standard-categories.index');
@endphp

<div class="pd-grid pd-grid-2">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">{{ $isEdit ? 'Kategori Düzenleme Formu' : 'Yeni Kategori Formu' }}</h3>
            <p class="pd-card-subtitle">Kod, üst kategori ve ürün ailesi ile global hedef kategoriyi tanımlayın.</p>
        </div>
        <div class="pd-card-body">
            <form id="pdStandardCategoryForm" method="POST" action="{{ $submitRoute }}" class="pd-grid pd-grid-2">
                @csrf
                @if($isEdit)
                    @method('PUT')
                @endif

                <div>
                    <label class="pd-label">Kategori Kodu</label>
                    <input type="text" name="code" value="{{ old('code', $category->code) }}" class="pd-input" required placeholder="PROMO-KALEMLER-PLASTIK">
                    <div class="pd-profile-note mt-2">Kod benzersiz, büyük harfli ve tireli olmalıdır. Örnek: <code>PROMO-KALEMLER-PLASTIK</code></div>
                </div>
                <div>
                    <label class="pd-label">Kategori Adı</label>
                    <input type="text" name="name" value="{{ old('name', $category->name) }}" class="pd-input" required>
                </div>
                <div>
                    <label class="pd-label">Üst Kategori</label>
                    <select name="parent_id" class="pd-select">
                        <option value="">Kök Kategori</option>
                        @foreach($parents as $parent)
                            <option value="{{ $parent->id }}" @selected(old('parent_id', $category->parent_id) == $parent->id)>{{ $parent->full_path }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label">Ürün Ailesi</label>
                    <select name="product_family" class="pd-select" required>
                        <option value="promotion" @selected(old('product_family', $category->product_family) === 'promotion')>Promosyon</option>
                        <option value="print" @selected(old('product_family', $category->product_family) === 'print')>Matbaa</option>
                    </select>
                </div>
                <div>
                    <label class="pd-label">Slug</label>
                    <input type="text" name="slug" value="{{ old('slug', $category->slug) }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label">Sıra</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}" class="pd-input">
                </div>
                <div class="lg:col-span-2">
                    <label class="pd-label">Açıklama</label>
                    <textarea name="description" class="pd-textarea" rows="4">{{ old('description', $category->description) }}</textarea>
                </div>
                <div class="lg:col-span-2">
                    <div class="pd-grid pd-grid-2">
                        <label class="pd-checkbox">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active))>
                            Aktif
                        </label>
                        <label class="pd-checkbox">
                            <input type="checkbox" name="visible_in_catalog" value="1" @checked(old('visible_in_catalog', $category->visible_in_catalog))>
                            Katalogda Görünsün
                        </label>
                        <label class="pd-checkbox">
                            <input type="checkbox" name="requires_mapping" value="1" @checked(old('requires_mapping', $category->requires_mapping ?? true))>
                            Eşleme Gerektiriyor
                        </label>
                    </div>
                </div>
                <div class="lg:col-span-2">
                    <div class="flex gap-2 flex-wrap">
                        <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
                        <a href="{{ $backRoute }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Kategori Notları</h3>
            <p class="pd-card-subtitle">Bu kategori global Product Data Hub ağacının bir parçasıdır.</p>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                <span class="pd-summary-item">Bu kategori globaldir.</span>
                <span class="pd-summary-item">Tenant değiştiremez.</span>
                <span class="pd-summary-item">Tedarikçi kategori eşlemesinde hedef olarak kullanılır.</span>
                <span class="pd-summary-item">Kod benzersiz olmalıdır.</span>
            </div>

            <div class="pd-note mt-3">
                PROMO altına Matbaa, PRINT altına Promosyon kategorisi bağlanamaz. 4 seviye ve üstü yapı yerine özellik/filtre kullanılması önerilir.
            </div>

            @if($isEdit)
                <div class="pd-summary-section mt-4">
                    <h4 class="pd-summary-section-title">Mevcut Durum</h4>
                    <div class="pd-summary-info">
                        <div class="pd-summary-row"><span>Kod</span><span class="font-medium">{{ $category->code }}</span></div>
                        <div class="pd-summary-row"><span>Path</span><span class="font-medium">{{ $category->full_path }}</span></div>
                        <div class="pd-summary-row"><span>Depth</span><span class="font-medium">{{ $category->depth }}</span></div>
                        <div class="pd-summary-row"><span>Alt Kategori</span><span class="font-medium">{{ $category->children()->count() }}</span></div>
                    </div>
                </div>

                <div class="pd-summary-section mt-4">
                    <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
                    <div class="flex gap-2 flex-wrap">
                        @if($category->isArchivedCategory())
                            <span class="pd-badge pd-badge-amber">Arşiv kategori</span>
                            <span class="pd-profile-note">Doğrudan aktif yapılmaz. Eski bağlantıları inceleyip bakım akışıyla ele alınır.</span>
                        @else
                            <form method="POST" action="{{ route('admin.super.standard-categories.toggle-active', $category) }}">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-light" onclick="return confirm('{{ $category->is_active ? 'Bu kategoriyi pasife almak istediğinize emin misiniz?' : 'Bu kategoriyi aktif etmek istediğinize emin misiniz?' }}')">
                                    {{ $category->is_active ? 'Pasife Al' : 'Aktif Et' }}
                                </button>
                            </form>
                            @if(!$category->isPermanentBackbone())
                                <form method="POST" action="{{ route('admin.super.standard-categories.destroy', $category) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="pd-btn pd-btn-danger" onclick="return confirm('Bu kategoriyi silmek istediğinize emin misiniz?')">Sil</button>
                                </form>
                            @else
                                <span class="pd-badge pd-badge-blue">Kalıcı omurga silinemez</span>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
