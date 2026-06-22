@extends('layouts.prodelya-admin')

@section('title', $account->safeDisplayName() . ' / Finansal Cari Hesap')
@section('page_title', 'Finansal Cari Hesap')
@section('page_subtitle', $account->safeDisplayName() . ' teknik finans omurgası ve bağlantı özetleri')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.current-accounts.edit', $account) }}" class="pd-btn pd-btn-light">Düzenle</a>
    <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-primary">Listeye Dön</a>
</div>
@endsection

@section('content')
<div class="pd-grid" style="grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);">
    <div>
        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Üst Özet</h3>
                <p class="pd-card-subtitle">Cari kimlik, roller ve güvenli bağlantılar.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-2">
                    <div>
                        <div class="text-xs text-gray-600">Görünen Ad</div>
                        <div class="font-bold" style="margin-top: 6px;">{{ $account->safeDisplayName() }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Resmi Ünvan</div>
                        <div style="margin-top: 6px;">{{ $account->legal_name ?: '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Cari Kod</div>
                        <div style="margin-top: 6px;">{{ $account->account_code ?: '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Roller</div>
                        <div class="flex flex-wrap gap-2" style="margin-top: 6px;">
                            @forelse($account->roles as $role)
                                @php
                                    $roleTone = match($role->role) {
                                        \App\Models\CurrentAccountRole::ROLE_CUSTOMER => 'green',
                                        \App\Models\CurrentAccountRole::ROLE_SUPPLIER => 'blue',
                                        \App\Models\CurrentAccountRole::ROLE_SUBCONTRACTOR => 'purple',
                                        \App\Models\CurrentAccountRole::ROLE_CARRIER => 'amber',
                                        \App\Models\CurrentAccountRole::ROLE_SERVICE_PROVIDER => 'indigo',
                                        default => 'gray',
                                    };
                                @endphp
                                <span class="pd-badge pd-badge-{{ $roleTone }}">{{ $role->safeRoleLabel() }}</span>
                            @empty
                                <span class="pd-badge pd-badge-gray">Rol Yok</span>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Durum</div>
                        <div style="margin-top: 6px;">
                            @php
                                $statusTone = match($account->status) {
                                    \App\Models\CurrentAccount::STATUS_ACTIVE => 'green',
                                    \App\Models\CurrentAccount::STATUS_BLOCKED => 'red',
                                    \App\Models\CurrentAccount::STATUS_ARCHIVED => 'gray',
                                    default => 'amber',
                                };
                            @endphp
                            <span class="pd-badge pd-badge-{{ $statusTone }}">{{ $account->safeStatusLabel() }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Risk Durumu</div>
                        <div style="margin-top: 6px;">
                            @if($account->risk_status)
                                @php
                                    $riskTone = match($account->risk_status) {
                                        'critical', 'high' => 'red',
                                        'medium' => 'amber',
                                        default => 'green',
                                    };
                                @endphp
                                <span class="pd-badge pd-badge-{{ $riskTone }}">{{ $account->safeRiskStatusLabel() }}</span>
                            @else
                                <span class="text-gray-600">Tanımlı değil</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">İletişim</h3>
                <p class="pd-card-subtitle">Cari karta ait temel iletişim bilgileri.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-2">
                    <div><div class="text-xs text-gray-600">Telefon</div><div style="margin-top: 6px;">{{ $account->phone ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">Mobil</div><div style="margin-top: 6px;">{{ $account->mobile ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">E-posta</div><div style="margin-top: 6px;">{{ $account->email ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">Web Sitesi</div><div style="margin-top: 6px;">{{ $account->website ?: '-' }}</div></div>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Vergi / Kimlik</h3>
                <p class="pd-card-subtitle">Vergi ve kimlik alanları.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    <div><div class="text-xs text-gray-600">Vergi Dairesi</div><div style="margin-top: 6px;">{{ $account->tax_office ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">Vergi No</div><div style="margin-top: 6px;">{{ $account->tax_number ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">TC No</div><div style="margin-top: 6px;">{{ $account->tc_no ?: '-' }}</div></div>
                </div>
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Finansal Temel Ayarlar</h3>
                <p class="pd-card-subtitle">Bu fazda sadece temel ayarlar gösterilir; finansal hareket ekranı henüz kapalıdır.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid pd-grid-3">
                    <div><div class="text-xs text-gray-600">Varsayılan Para Birimi</div><div style="margin-top: 6px;">{{ $account->default_currency ?: '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">Ödeme Vadesi</div><div style="margin-top: 6px;">{{ $account->payment_terms_days !== null ? $account->payment_terms_days . ' gün' : '-' }}</div></div>
                    <div><div class="text-xs text-gray-600">Risk Limiti</div><div style="margin-top: 6px;">{{ $account->risk_limit !== null ? number_format((float) $account->risk_limit, 2, ',', '.') : '-' }}</div></div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Bağlantılar</h3>
                <p class="pd-card-subtitle">Bu teknik finans hesabına bağlı güvenli kayıt bağlantıları.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-grid">
                    @if($linkedCompany)
                        <div style="padding: 14px; border: 1px solid var(--pd-line); border-radius: 8px;">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-medium">Firma Kaydı</div>
                                    <div class="text-sm text-gray-600" style="margin-top: 4px;">{{ $linkedCompany->legal_name }}</div>
                                    <div class="text-sm text-gray-600" style="margin-top: 4px;">Firma Kaydı</div>
                                </div>
                                <a href="{{ route('admin.companies.show', $linkedCompany) }}" class="pd-btn pd-btn-light pd-btn-sm">Firma Kaydını Aç</a>
                            </div>
                        </div>
                    @endif

                    @foreach($supplierLinks as $supplierLink)
                        @php $supplier = $linkedSuppliers->get($supplierLink->link_id); @endphp
                        <div style="padding: 14px; border: 1px solid var(--pd-line); border-radius: 8px;">
                            <div class="font-medium">{{ $supplier?->name ?: 'Global Supplier #' . $supplierLink->link_id }}</div>
                            <div class="text-sm text-gray-600" style="margin-top: 4px;">Ürün/Data Kaynağı Bağlantısı</div>
                            <div class="text-sm text-gray-600" style="margin-top: 4px;">Ürün/Data Kaynağı</div>
                            <form method="POST" action="{{ route('admin.current-accounts.supplier-link.destroy', $account) }}" style="margin-top: 10px;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Bağlantıyı Kaldır</button>
                            </form>
                        </div>
                    @endforeach

                    @foreach($tenantSupplierAccessLinks as $tenantSupplierAccessLink)
                        @php $access = $tenantSupplierAccesses->get($tenantSupplierAccessLink->link_id); @endphp
                        @if($access)
                            <div style="padding: 14px; border: 1px solid var(--pd-line); border-radius: 8px;">
                                <div class="font-medium">Tenant Tedarikçi Erişimi</div>
                                <div class="text-sm text-gray-600" style="margin-top: 4px;">{{ $access->supplier?->name ?: 'Supplier #' . $access->supplier_id }}</div>
                                <div class="text-sm text-gray-600" style="margin-top: 4px;">{{ $access->getStatusLabel() }}</div>
                            </div>
                        @endif
                    @endforeach

                    @if(!$linkedCompany && $supplierLinks->isEmpty())
                        <div class="pd-note">Bu cari için henüz company veya global supplier bağlantısı bulunmuyor.</div>
                    @endif
                </div>

                @if($account->hasRole(\App\Models\CurrentAccountRole::ROLE_SUPPLIER))
                    <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--pd-line);">
                        <h4 class="pd-summary-section-title" style="margin-bottom: 10px;">Ürün/Data Kaynağı Bağlantısı</h4>
                        <form method="POST" action="{{ route('admin.current-accounts.supplier-link.store', $account) }}">
                            @csrf
                            <div class="pd-grid pd-grid-2">
                                <div>
                                    <label class="text-sm font-medium">Aktif Global Supplier</label>
                                    <select name="supplier_id">
                                        <option value="">Seçiniz</option>
                                        @foreach($supplierOptions as $supplierOption)
                                            <option value="{{ $supplierOption->id }}" @selected(optional($supplierLinks->first())->link_id === $supplierOption->id)>{{ $supplierOption->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div style="display: flex; align-items: end;">
                                    <button type="submit" class="pd-btn pd-btn-primary">Supplier Bağla</button>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        @if($canViewTransactions)
            <div class="pd-card" style="margin-top: 14px;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Cari Hareketler</h3>
                    <p class="pd-card-subtitle">Yalnız yetkili kullanıcılar için manuel hareket özeti ve son kayıtlar.</p>
                </div>
                <div class="pd-card-body">
                    <div class="pd-grid pd-grid-3" style="margin-bottom: 14px;">
                        @forelse(($transactionSummary['currencies'] ?? []) as $currencySummary)
                            <div style="padding: 14px; border: 1px solid var(--pd-line); border-radius: 8px;">
                                <div class="text-sm text-gray-600">{{ $currencySummary['currency'] }}</div>
                                <div style="margin-top: 8px;">Borç: <strong>{{ $currencySummary['debit_total_label'] }}</strong></div>
                                <div style="margin-top: 6px;">Alacak: <strong>{{ $currencySummary['credit_total_label'] }}</strong></div>
                                <div style="margin-top: 6px;">Bakiye: <strong>{{ $currencySummary['balance_label'] }}</strong></div>
                            </div>
                        @empty
                            <div class="pd-note">Henüz cari hareket kaydı yok.</div>
                        @endforelse
                    </div>

                    <div class="pd-table-wrap">
                        <table class="pd-table">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tür</th>
                                    <th>Açıklama</th>
                                    <th>Borç</th>
                                    <th>Alacak</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTransactions as $transaction)
                                    @php
                                        $transactionTone = match($transaction->status) {
                                            \App\Models\CurrentAccountTransaction::STATUS_CANCELLED => 'gray',
                                            \App\Models\CurrentAccountTransaction::STATUS_PAID,
                                            \App\Models\CurrentAccountTransaction::STATUS_CLOSED => 'green',
                                            \App\Models\CurrentAccountTransaction::STATUS_PARTIALLY_PAID => 'amber',
                                            default => 'blue',
                                        };
                                    @endphp
                                    <tr @if($transaction->isCancelled()) style="opacity:.65;" @endif>
                                        <td>{{ optional($transaction->transaction_date)->format('d.m.Y') ?: '-' }}</td>
                                        <td>{{ $transaction->safeTypeLabel() }}</td>
                                        <td>{{ $transaction->description ?: '-' }}</td>
                                        <td>{{ $transaction->isDebit() ? $transaction->formattedAmount() : '-' }}</td>
                                        <td>{{ $transaction->isCredit() ? $transaction->formattedAmount() : '-' }}</td>
                                        <td><span class="pd-badge pd-badge-{{ $transactionTone }}">{{ $transaction->safeStatusLabel() }}</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">Henüz hareket yok.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="pd-actions-wrap" style="margin-top: 14px;">
                        @if($canManageTransactions)
                            <a href="{{ route('admin.current-accounts.transactions.index', $account) }}" class="pd-btn pd-btn-primary pd-btn-sm">Yeni Hareket Ekle</a>
                        @endif
                        <a href="{{ route('admin.current-accounts.transactions.index', $account) }}" class="pd-btn pd-btn-light pd-btn-sm">Tüm Hareketler</a>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div>
        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Notlar</h3>
                <p class="pd-card-subtitle">Ekip içi operasyon notları.</p>
            </div>
            <div class="pd-card-body">
                @if($account->notes)
                    <div style="white-space: pre-line;">{{ $account->notes }}</div>
                @else
                    <div class="pd-note">Not girilmemiş.</div>
                @endif
            </div>
        </div>

        <div class="pd-card" style="margin-bottom: 14px;">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Durum Aksiyonları</h3>
                <p class="pd-card-subtitle">Cari kartı silmeden durumunu güncelleyin.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    @foreach(\App\Models\CurrentAccount::statusLabels() as $statusValue => $statusLabel)
                        <form method="POST" action="{{ route('admin.current-accounts.update-status', $account) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $statusValue }}">
                            <button type="submit" class="pd-summary-action" @disabled($account->status === $statusValue)>
                                <span>
                                    @switch($statusValue)
                                        @case(\App\Models\CurrentAccount::STATUS_ACTIVE)
                                            Aktif Yap
                                            @break
                                        @case(\App\Models\CurrentAccount::STATUS_PASSIVE)
                                            Pasif Yap
                                            @break
                                        @case(\App\Models\CurrentAccount::STATUS_BLOCKED)
                                            Bloke Et
                                            @break
                                        @default
                                            Arşivle
                                    @endswitch
                                </span>
                                <span class="pd-badge pd-badge-{{ $account->status === $statusValue ? 'green' : 'gray' }}">{{ $statusLabel }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Hızlı Geçişler</h3>
                <p class="pd-card-subtitle">Finansal cari hesap yönetimi için hızlı bağlantılar.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    <a href="{{ route('admin.current-accounts.edit', $account) }}" class="pd-summary-item">Düzenle</a>
                    <a href="{{ route('admin.current-accounts.index') }}" class="pd-summary-item">Listeye Dön</a>
                    @if($linkedCompany)
                        <a href="{{ route('admin.companies.show', $linkedCompany) }}" class="pd-summary-item">Firma kaydını aç</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
