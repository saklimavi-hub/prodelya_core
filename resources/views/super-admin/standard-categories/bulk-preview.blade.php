@extends('layouts.prodelya-admin')

@section('title', 'Toplu Aktarım Önizleme')
@section('page_title', 'Toplu Aktarım Önizleme')
@section('page_subtitle', 'Parse edilen satırları kontrol edin ve onaylanan kayıtları içeri alın.')

@section('page_actions')
<div class="pd-actions-wrap">
    <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-light">Geri Dön</a>
</div>
@endsection

@section('content')
<div class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Önizleme Sonucu</h3>
        <p class="pd-card-subtitle">Kod zaten varsa kayıt güncellenir. Hata durumundaki satırlar kaydedilmez.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Satır</th>
                        <th>Kod</th>
                        <th>Ad</th>
                        <th>Üst Kod</th>
                        <th>Ürün Ailesi</th>
                        <th>Sıra</th>
                        <th>Durum</th>
                        <th>Uyarı</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $badge = $row['status'] === 'ok' ? 'green' : ($row['status'] === 'warning' ? 'amber' : 'red');
                            $statusLabel = match ($row['status']) {
                                'ok' => !empty($row['is_existing']) ? 'Güncellenecek' : 'Yeni Kayıt',
                                'warning' => 'Güncellenecek / Kontrol',
                                default => 'Hata',
                            };
                        @endphp
                        <tr class="pd-bulk-preview-row">
                            <td>{{ $row['line'] }}</td>
                            <td>{{ $row['code'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['parent_code'] ?: '-' }}</td>
                            <td>{{ $row['product_family'] }}</td>
                            <td>{{ $row['sort_order'] }}</td>
                            <td><span class="pd-badge pd-badge-{{ $badge }}">{{ $statusLabel }}</span></td>
                            <td>{{ $row['warning'] ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pd-note mt-3">
            Kaydetmeden önce üst kategori kodları ve ürün ailesi alanlarını kontrol edin. Hata içeren satırlar içeri alınmaz.
        </div>

        <form method="POST" action="{{ route('admin.super.standard-categories.bulk-paste.store') }}" class="mt-3">
            @csrf
            <input type="hidden" name="rows_payload" value="{{ $encodedRows }}">
            <div class="flex gap-2 flex-wrap">
                <button type="submit" class="pd-btn pd-btn-primary">Kaydetmeden Önce Onayla ve İçeri Al</button>
                <a href="{{ route('admin.super.standard-categories.bulk-paste') }}" class="pd-btn pd-btn-light">Düzenlemeye Dön</a>
            </div>
        </form>
    </div>
</div>
@endsection

@section('side_summary')
<div class="pd-card">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Önizleme Notları</h3>
        <div class="pd-summary-list">
            <span class="pd-summary-item">Kod zaten var: kayıt güncellenecek.</span>
            <span class="pd-summary-item">Üst kategori bulunamadı: satır atlanabilir.</span>
            <span class="pd-summary-item">Ürün ailesi veya kategori adı eksikse kayıt alınmaz.</span>
        </div>
    </div>
</div>
@endsection
