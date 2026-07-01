@extends('layouts.prodelya-admin')

@section('title', 'Tenant SaaS Cari')
@section('page_title', $tenant->name . ' SaaS Cari Hareketleri')
@section('page_subtitle', 'Tenant bazlı hizmet, paket, tahsilat ve manuel SaaS cari hareketlerini yönetin.')

@section('page_actions')
<div class="flex flex-wrap gap-2">
    <form method="POST" action="{{ route('admin.super.tenants.billing.package-fee', $tenant) }}">
        @csrf
        <button type="submit" class="pd-btn pd-btn-danger">Paket Bedelini Borçlandır</button>
    </form>
    <a href="{{ route('admin.super.tenants.billing.payment-checkouts.create', $tenant) }}" class="pd-btn pd-btn-primary">Ödeme Linki Oluştur</a>
    <a href="{{ route('admin.super.tenants.billing.create', [$tenant, 'entry_type' => 'service_fee']) }}" class="pd-btn pd-btn-primary">Hizmet Borcu Ekle</a>
    <a href="{{ route('admin.super.tenants.billing.create', [$tenant, 'entry_type' => 'collection']) }}" class="pd-btn pd-btn-light">Tahsilat Ekle</a>
    <a href="{{ route('admin.super.tenants.billing.export.pdf', array_merge(['tenant' => $tenant], $filters)) }}" class="pd-btn pd-btn-light">Ekstre PDF</a>
    <a href="{{ route('admin.super.tenants.billing.export.csv', array_merge(['tenant' => $tenant], $filters)) }}" class="pd-btn pd-btn-light">Ekstre CSV</a>
    <a href="{{ route('admin.super.tenants.show', $tenant) }}" class="pd-btn pd-btn-light">Abone Firma Detayına Dön</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    @include('super-admin.payment-partials.foundation-roadmap', ['compact' => true])

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-grid">
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Cari Bakiye</div>
                    <div class="pd-mini-link-copy {{ $summary['balance'] > 0 ? 'text-red-600' : 'text-green-600' }}">{{ \App\Services\MoneyFormatter::format((float) $summary['balance']) }}</div>
                    <div class="text-xs text-gray-500">{{ $summary['balance'] > 0 ? 'Borç bakiyesi' : 'Alacak / kapalı bakiye' }}</div>
                </div>
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Toplam Borç</div>
                    <div class="pd-mini-link-copy text-red-600">{{ \App\Services\MoneyFormatter::format((float) $summary['total_debit']) }}</div>
                    <div class="text-xs text-gray-500">Paket ve hizmet bedelleri dahil</div>
                </div>
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Toplam Alacak</div>
                    <div class="pd-mini-link-copy text-green-600">{{ \App\Services\MoneyFormatter::format((float) $summary['total_credit']) }}</div>
                    <div class="text-xs text-gray-500">Tahsilat ve mahsuplar dahil</div>
                </div>
                <div class="pd-mini-link-card">
                    <div class="pd-mini-link-title">Hareket Sayısı</div>
                    <div class="pd-mini-link-copy">{{ $summary['entry_count'] }}</div>
                    <div class="text-xs text-gray-500">Filtre sonucu toplam hareket</div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler</h3>
                <p class="pd-section-subtitle">Tarih, hareket tipi, yön ve hizmet kalemine göre tenant SaaS cari kayıtlarını daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" action="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_1fr_1fr_auto]">
                <div>
                    <label class="pd-label" for="date_from">Başlangıç</label>
                    <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="date_to">Bitiş</label>
                    <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="pd-input">
                </div>
                <div>
                    <label class="pd-label" for="entry_type">Hareket Tipi</label>
                    <select id="entry_type" name="entry_type" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($entryTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['entry_type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="direction">Yön</label>
                    <select id="direction" name="direction" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($directionOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['direction'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="pd-label" for="tenant_service_definition_id">Hizmet Kalemi</label>
                    <select id="tenant_service_definition_id" name="tenant_service_definition_id" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($serviceDefinitions as $service)
                            <option value="{{ $service->id }}" @selected((string) $filters['tenant_service_definition_id'] === (string) $service->id)>{{ $service->service_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-3">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.tenants.billing.index', $tenant) }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Ortak Ödeme Omurgası</h3>
                <p class="pd-section-subtitle">Super Admin tarafında ortak provider kullanılır. Tenant tarafı ileride modül olarak kendi ödeme hesabını tanımlar.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Aktif Ortak Provider</div><div class="pd-mini-kpi-value">{{ $sharedProviderCount }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Son Checkout</div><div class="pd-mini-kpi-value">{{ $recentCheckoutSessions->count() }}</div></div>
            </div>
            <div class="pd-table-wrap mt-4">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Referans</th>
                            <th>Provider</th>
                            <th>Durum</th>
                            <th>Tutar</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentCheckoutSessions as $session)
                            <tr>
                                <td>{{ $session->reference_no }}</td>
                                <td>{{ $session->provider?->display_name ?: '-' }}</td>
                                <td><span class="pd-badge pd-badge-blue">{{ $session->statusLabel() }}</span></td>
                                <td>{{ \App\Services\MoneyFormatter::format((float) $session->amount, $session->currency ?: 'TRY') }}</td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.payment-checkouts.show', $session) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-sm text-gray-500">Henüz ortak checkout oturumu oluşturulmadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-white">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Hareket Listesi</h3>
                <p class="pd-section-subtitle">Tenant müşteri carisinden bağımsız SaaS işletme hareketleri burada tutulur.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Hareket Tipi</th>
                            <th>Başlık</th>
                            <th>Hizmet</th>
                            <th>Referans</th>
                            <th>Borç</th>
                            <th>Alacak</th>
                            <th>Oluşturan</th>
                            <th class="text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ optional($entry->entry_date)->format('d.m.Y') }}</td>
                                <td><span class="pd-badge {{ $entry->direction === 'credit' ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ $entry->typeLabel() }}</span></td>
                                <td>
                                    <div class="font-medium">{{ $entry->title }}</div>
                                    <div class="text-sm text-gray-600">{{ $entry->note ?: 'Not girilmemiş.' }}</div>
                                </td>
                                <td>{{ $entry->serviceDefinition?->service_name ?: '-' }}</td>
                                <td>{{ $entry->reference_no ?: '-' }}</td>
                                <td>{{ $entry->direction === 'debit' ? \App\Services\MoneyFormatter::format((float) $entry->amount, $entry->currency ?: 'TRY') : '-' }}</td>
                                <td>{{ $entry->direction === 'credit' ? \App\Services\MoneyFormatter::format((float) $entry->amount, $entry->currency ?: 'TRY') : '-' }}</td>
                                <td>{{ $entry->creator?->name ?: '-' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.super.tenants.billing.edit', [$tenant, $entry]) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a>
                                    <form method="POST" action="{{ route('admin.super.tenants.billing.destroy', [$tenant, $entry]) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="pd-btn pd-btn-sm pd-btn-danger" onclick="return confirm('Bu SaaS cari hareketini silmek istediğinize emin misiniz?')">Sil</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-sm text-gray-500">Tenant SaaS cari hareketi henüz oluşmadı.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $entries->links() }}
            </div>
        </div>
    </section>
</div>
@endsection
