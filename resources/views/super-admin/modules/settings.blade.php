@extends('layouts.prodelya-admin')

@section('title', 'Paket Erişim Kuralları')
@section('page_title', 'Paket Erişim Kuralları')
@section('page_subtitle', 'Modül, özellik ve limit kararlarının tenant erişimine nasıl yansıdığını görün.')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Karar Özeti</h3>
                <p class="pd-section-subtitle">Menü, route guard ve lifecycle kısıtları burada ortak dille özetlenir.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-kpi-strip">
                @foreach($decisionRules as $rule)
                    <div class="pd-card pd-card-soft">
                        <div class="pd-card-body">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-medium">{{ $rule['title'] }}</div>
                                <span class="pd-badge {{ match($rule['tone']) {'green' => 'pd-badge-green', 'blue' => 'pd-badge-blue', 'amber' => 'pd-badge-amber', 'red' => 'pd-badge-red', default => 'pd-badge-gray'} }}">{{ $rule['badge'] }}</span>
                            </div>
                            <div class="pd-panel-card-copy mt-2">{{ $rule['description'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Paket Matrisi</h3>
                <p class="pd-section-subtitle">Her pakette kaç modül, özellik ve limit tanımı olduğu görünür.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Paket</th>
                            <th>Durum</th>
                            <th>Modül</th>
                            <th>Özellik</th>
                            <th>Limit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packageMatrix as $row)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $row['name'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $row['key'] }}</div>
                                </td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['module_count'] }}</td>
                                <td>{{ $row['feature_count'] }}</td>
                                <td>{{ $row['limit_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
