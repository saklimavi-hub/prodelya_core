@php
    $workForm = $production->workForm;
    $productionPhotos = $workForm
        ? $workForm->attachments
            ->where('attachment_type', 'production_photo')
            ->sortByDesc('id')
            ->values()
        : collect();
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">Fotoğraflar</h2>
        <p class="prd-section-subtitle">Üretim fotoğrafları burada salt okunur incelenir. Yeni fotoğraf ekleme operator veya fason takip ekranındaki canonical akıştan yapılır.</p>

        <div class="prd-grid-2">
            <div class="prd-info-card">
                <span class="prd-info-label">Toplam Fotoğraf</span>
                <div class="prd-info-value">{{ $productionPhotos->count() }}</div>
            </div>
            <div class="prd-info-card">
                <span class="prd-info-label">Son Yükleme</span>
                <div class="prd-info-value" style="font-size:14px;">
                    {{ $productionPhotos->isNotEmpty() ? optional($productionPhotos->first()->created_at)->format('d.m.Y H:i') : 'Henüz yok' }}
                </div>
            </div>
        </div>
    </section>

    <section class="prd-card">
        <h2 class="prd-section-title">Son Yüklenen Fotoğraflar</h2>

        @if($productionPhotos->isNotEmpty())
            <div class="prd-grid-4">
                @foreach($productionPhotos->take(8) as $photo)
                    <div class="prd-photo-card">
                        <div style="aspect-ratio: 4 / 3; border-radius: 10px; overflow: hidden; background: #f3f6fb; border: 1px solid #e7edf4; display:flex; align-items:center; justify-content:center;">
                            @if($photo->isImage())
                                <img src="{{ route('admin.work-forms.attachments.preview', $photo) }}" alt="{{ $photo->file_name }}" style="width:100%; height:100%; object-fit:cover;">
                            @else
                                <span style="color:#667085; font-size:12px; font-weight:700;">Belge Önizleme</span>
                            @endif
                        </div>

                        <div style="margin-top:10px; display:grid; gap:6px;">
                            <div style="color:#182230; font-size:13px; font-weight:700;">{{ $photo->file_name ?: 'Üretim fotoğrafı' }}</div>
                            <div style="color:#667085; font-size:12px;">{{ optional($photo->created_at)->format('d.m.Y H:i') }}</div>
                            <div style="color:#667085; font-size:12px;">{{ $photo->uploader?->name ?: 'Operatör bilgisi yok' }}</div>
                            @if($photo->note)
                                <div style="color:#475467; font-size:12px; line-height:1.45;">{{ $photo->note }}</div>
                            @endif
                        </div>

                        <div class="prd-form-actions">
                            <a href="{{ route('admin.work-forms.attachments.preview', $photo) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Görüntüle</a>
                            <a href="{{ route('admin.work-forms.attachments.preview', $photo) }}" target="_blank" rel="noopener" download="{{ $photo->file_name }}" class="btn btn-sm btn-outline-primary">İndir</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="prd-empty">Henüz fotoğraf yüklenmedi.</div>
        @endif
    </section>
</div>
