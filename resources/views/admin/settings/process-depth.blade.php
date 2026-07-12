@extends('layouts.prodelya-admin')

@section('title', 'Süreç Derinliği')
@section('page_title', 'Süreç Derinliği')
@section('page_subtitle', 'Seçilen çalışma şeklinin teklif, sipariş ve operasyon ekranlarındaki ayrıntı seviyesine etkisini belirler.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.settings') }}" class="pd-btn pd-btn-light">Kurulum Merkezine Dön</a>
</div>
@endsection

@section('content')
<style>
    .process-depth-layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(280px, 0.92fr); gap: 18px; align-items: start; }
    .process-depth-stack { display: grid; gap: 18px; }
    .process-depth-intro { border: 1px solid #dbe7ff; border-radius: 14px; padding: 18px; background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%); color: #31425d; }
    .process-depth-intro h3 { margin: 0 0 8px; color: #22324b; font-size: 18px; }
    .process-depth-intro p { margin: 0; font-size: 13px; line-height: 1.65; }
    .process-depth-overview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .process-depth-stat { border: 1px solid var(--pd-line, #e5e7eb); border-radius: 12px; padding: 14px; background: #fff; }
    .process-depth-stat-label { color: #6b7280; font-size: 12px; margin-bottom: 6px; }
    .process-depth-stat-value { color: #1f2937; font-size: 18px; font-weight: 700; }
    .process-depth-form-grid { display: grid; gap: 12px; }
    .process-depth-option { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; background: #fff; display: flex; gap: 12px; align-items: flex-start; }
    .process-depth-option input { margin-top: 4px; }
    .process-depth-option-title { color: #1f2937; font-size: 15px; font-weight: 700; }
    .process-depth-option-text { color: #64748b; font-size: 12.5px; line-height: 1.6; margin-top: 4px; display: block; }
    .process-depth-note { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; background: #f8fafc; color: #475569; font-size: 12.5px; line-height: 1.6; }
    .process-depth-sidebar { position: sticky; top: 18px; display: grid; gap: 18px; }
    .process-depth-summary-list { display: grid; gap: 10px; }
    .process-depth-summary-row { display: flex; justify-content: space-between; gap: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--pd-line, #e5e7eb); font-size: 13px; }
    .process-depth-summary-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .process-depth-alert { margin-bottom: 14px; padding: 12px 14px; border-radius: 12px; font-size: 13px; }
    .process-depth-alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
    .process-depth-alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @media (max-width: 1120px) { .process-depth-layout { grid-template-columns: 1fr; } .process-depth-sidebar { position: static; } }
    @media (max-width: 760px) { .process-depth-overview-grid { grid-template-columns: 1fr; } }
</style>

@if(session('success'))
    <div class="process-depth-alert process-depth-alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="process-depth-alert process-depth-alert-error">{{ $errors->first() }}</div>
@endif

<form id="processDepthSettingsForm" method="POST" action="{{ route('admin.settings.process-depth.update') }}">
    @csrf
    @method('PUT')

    <div class="process-depth-layout">
        <div class="process-depth-stack">
            <div class="process-depth-intro">
                <h3>Süreç Derinliği</h3>
                <p>Seçilen çalışma şeklinin teklif, sipariş ve operasyon ekranlarındaki ayrıntı seviyesine etkisini belirler.</p>
                <p style="margin-top: 8px;">Seçimin etkisi, Süreç Derinliği desteği eklenen operasyon ekranlarında uygulanır.</p>
            </div>

            <div class="pd-card">
                <div class="pd-card-header">
                    <div>
                        <h3 class="pd-card-title">Mevcut Durum</h3>
                        <p class="pd-card-subtitle">Etkin çalışma şekli ve paket varsayılanı birlikte gösterilir.</p>
                    </div>
                </div>
                <div class="pd-card-body">
                    <div class="process-depth-overview-grid">
                        <div class="process-depth-stat">
                            <div class="process-depth-stat-label">Etkin çalışma şekli</div>
                            <div class="process-depth-stat-value">{{ $effectiveDepth['label'] }}</div>
                        </div>
                        <div class="process-depth-stat">
                            <div class="process-depth-stat-label">Seçimin kaynağı</div>
                            <div class="process-depth-stat-value" style="font-size: 16px;">{{ $effectiveDepth['source_label'] }}</div>
                        </div>
                        <div class="process-depth-stat">
                            <div class="process-depth-stat-label">Paket varsayılanı</div>
                            <div class="process-depth-stat-value">{{ $packageDefaultDepth['label'] }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pd-card">
                <div class="pd-card-header">
                    <div>
                        <h3 class="pd-card-title">Çalışma Şeklini Seçin</h3>
                        <p class="pd-card-subtitle">Paket varsayılanını kullanabilir veya Abone Firma için farklı bir tercih kaydedebilirsiniz.</p>
                    </div>
                </div>
                <div class="pd-card-body">
                    <div class="process-depth-form-grid">
                        <label class="process-depth-option" for="process_depth_selection_inherit">
                            <input id="process_depth_selection_inherit" type="radio" name="process_depth_selection" value="inherit" @checked($selectedProcessDepth === 'inherit')>
                            <span>
                                <span class="process-depth-option-title">Paket varsayılanını kullan</span>
                                <span class="process-depth-option-text">Paketinizin varsayılan seçimi: {{ $packageDefaultDepth['label'] }}</span>
                            </span>
                        </label>

                        @foreach($processDepthOptions as $option)
                            <label class="process-depth-option" for="process_depth_selection_{{ $option['key'] }}">
                                <input id="process_depth_selection_{{ $option['key'] }}" type="radio" name="process_depth_selection" value="{{ $option['key'] }}" @checked($selectedProcessDepth === $option['key'])>
                                <span>
                                    <span class="process-depth-option-title">{{ $option['label'] }}</span>
                                    <span class="process-depth-option-text">{{ $option['description'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('process_depth_selection')
                        <div class="pd-input-error" style="margin-top: 10px;">{{ $message }}</div>
                    @enderror

                    @if($hasInvalidOverride)
                        <div class="process-depth-note" style="margin-top: 12px;">Önceki kayıt güvenli varsayılanla gösterildi. Yeni seçim yaptığınızda temiz bir tercih kaydedilir.</div>
                    @endif
                </div>
            </div>
        </div>

        <aside class="process-depth-sidebar">
            <div class="pd-card">
                <div class="pd-card-header">
                    <div>
                        <h3 class="pd-card-title">Durum Özeti</h3>
                        <p class="pd-card-subtitle">Kaydedilecek ayarın hızlı özeti.</p>
                    </div>
                </div>
                <div class="pd-card-body">
                    <div class="process-depth-summary-list">
                        <div class="process-depth-summary-row"><span>Etkin çalışma şekli</span><strong>{{ $effectiveDepth['label'] }}</strong></div>
                        <div class="process-depth-summary-row"><span>Seçimin kaynağı</span><strong>{{ $effectiveDepth['source_label'] }}</strong></div>
                        <div class="process-depth-summary-row"><span>Paket varsayılanı</span><strong>{{ $packageDefaultDepth['label'] }}</strong></div>
                    </div>
                    <div class="process-depth-note" style="margin-top: 14px;">Bu ayar modül erişimini veya kullanıcı yetkilerini değiştirmez.</div>
                    <div class="process-depth-note" style="margin-top: 10px;">Seçimin etkisi, Süreç Derinliği desteği eklenen operasyon ekranlarında uygulanır.</div>
                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <button type="submit" class="pd-btn pd-btn-primary">Ayarları Kaydet</button>
                        <a href="{{ route('admin.settings') }}" class="pd-btn pd-btn-light">İptal</a>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</form>
@endsection