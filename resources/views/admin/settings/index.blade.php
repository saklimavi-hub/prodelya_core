@extends('layouts.prodelya-admin')

@section('title', 'Ayarlar')
@section('page_title', 'Ayarlar')
@section('page_subtitle', 'Firma, kullanıcı, modül, kullanım ve operasyon ayarlarınızı yönetin.')
@section('hide_side_summary', true)

@php
    $statusBadgeMap = [
        'success' => 'badge-green',
        'info' => 'badge-blue',
        'warning' => 'badge-amber',
        'danger' => 'badge-red',
        'muted' => 'badge-gray',
    ];

    $usageBadgeMap = [
        'ok' => 'badge-green',
        'warning' => 'badge-amber',
        'exceeded' => 'badge-red',
        'unlimited' => 'badge-blue',
    ];

    $usageLabelMap = [
        'ok' => 'Aktif',
        'warning' => 'Limit dolmak üzere',
        'exceeded' => 'Limit aşıldı',
        'unlimited' => 'Limitsiz',
    ];

    $hardStopUsageKeys = ['users', 'current_accounts', 'orders'];

    $sectionBadgeMap = [
        'green' => 'badge-green',
        'gray' => 'badge-gray',
        'amber' => 'badge-amber',
    ];
@endphp

@section('content')
<style>
    .settings-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .settings-task-grid,
    .settings-usage-grid {
        display: grid;
        gap: 16px;
    }
    .settings-task-grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }
    .settings-usage-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    .settings-task-card,
    .settings-compact-card,
    .settings-usage-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .settings-task-card {
        padding: 16px;
    }
    .settings-compact-card {
        padding: 18px;
    }
    .settings-usage-card {
        padding: 14px;
    }
    .settings-task-item {
        border: 1px solid #edf2f7;
        border-radius: 12px;
        background: #f8fafc;
        padding: 12px 14px;
    }
    .settings-mini-text {
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
    }
    .settings-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 92px;
    }
    .settings-hero {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        padding: 18px 20px;
    }
    @media (max-width: 1024px) {
        .settings-task-grid,
        .settings-usage-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="settings-shell">
@if(session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif

@if($subscription['status'] === 'expired')
    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Paket süresi dolmuş. İşlem yapmak için paket yenilenmeli.
    </div>
@endif

<div class="settings-hero mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Önce yapmak istediğiniz işi seçin.</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-600">Firma, operasyon, müşteri portalı, bildirim ve paket ayarlarını buradan yönetin. Her bölüm kısa açıklamayla neyi yönettiğini gösterir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="badge badge-blue">{{ $packageLabel }}</span>
            <span class="badge {{ $statusBadgeMap[$subscription['severity']] ?? 'badge-gray' }}">{{ $subscription['label'] }}</span>
        </div>
    </div>
</div>

<div class="space-y-6">
        <div class="settings-compact-card">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3 class="text-[15px] font-semibold text-gray-900">Tenant Profili ve Hazırlık</h3>
                    <p class="mt-1 settings-mini-text">SAKLImavi gibi gerçek tenantlar için firma kimliği, panel adresi ve kurulum bekleyen alanları tek bakışta görün.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-blue">Tenant Profili</span>
                    <span class="badge {{ ($tenantReadiness['legacy_import_done'] ?? false) ? 'badge-green' : 'badge-amber' }}">
                        {{ ($tenantReadiness['legacy_import_done'] ?? false) ? 'Eski veri import edildi' : 'Eski veri import yapılmadı' }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="settings-task-item">
                    <div class="settings-mini-text">Görünen Firma Adı</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['company_display_name'] ?: '-' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Yasal Ünvan</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['company_legal_name'] ?: 'Henüz girilmedi' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Panel Adresi</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900 break-all">{{ $tenantReadiness['panel_url'] ?: '-' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Dil / Para Birimi</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['default_locale'] }} / {{ $tenantReadiness['default_currency'] }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Zaman / Format</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['timezone'] }} / {{ $tenantReadiness['number_format_locale'] }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Storage Disk</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['storage_disk'] }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Adres Özeti</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['company_full_address'] ?: 'Henüz girilmedi' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Telefon / E-posta</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['company_phone'] ?: '-' }}{{ $tenantReadiness['company_email'] ? ' · ' . $tenantReadiness['company_email'] : '' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Web Sitesi</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900 break-all">{{ $tenantReadiness['company_website'] ?: 'Henüz girilmedi' }}</div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="settings-task-item">
                    <div class="settings-mini-text">İlk Firma</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['has_first_company'] ? 'Hazır' : 'Henüz oluşturulmadı' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">İlk Cari</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['has_first_current_account'] ? 'Hazır' : 'Henüz oluşturulmadı' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">SMTP Kurulumu</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['smtp_ready'] ? 'Bilgi girilmiş' : 'Kurulum bekliyor' }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">WhatsApp Kurulumu</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenantReadiness['whatsapp_ready'] ? 'Bilgi girilmiş' : 'Kurulum bekliyor' }}</div>
                </div>
            </div>

            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                İlk firma/cari bu fazda otomatik oluşturulmadı. Bu kayıtlar müşteri/tedarikçi operasyonuyla karışmaması için tenant içinde bilinçli olarak manuel açılmalı.
            </div>
            <div class="mt-3 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                Windows local test için gerekirse <code>127.0.0.1 {{ $tenantReadiness['panel_host'] }}</code> hosts kaydı eklenebilir. SMTP ve WhatsApp ayar ekranları hazırdır ancak bu tenant için gerçek kimlik bilgileri henüz girilmemiştir.
            </div>
            <div class="mt-4">
                <a href="{{ route('admin.settings.company-profile.edit') }}" class="pd-btn pd-btn-primary">
                    Firma Bilgilerini Düzenle
                </a>
            </div>
        </div>

        <div class="settings-task-grid">
            @foreach($settingsSections as $section)
                <div class="settings-task-card">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-[15px] font-semibold text-gray-900">{{ $section['title'] }}</h3>
                                <p class="mt-1 settings-mini-text">{{ $section['description'] }}</p>
                            </div>
                        </div>
                        <div class="mt-4 space-y-4">
                            @foreach($section['items'] as $item)
                                <div class="settings-task-item">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-[14px] font-semibold text-gray-900">{{ $item['title'] }}</div>
                                            <p class="mt-1 settings-mini-text">{{ $item['description'] }}</p>
                                        </div>
                                        <span class="badge {{ $sectionBadgeMap[$item['badge_tone']] ?? 'badge-gray' }}">{{ $item['badge'] }}</span>
                                    </div>

                                    <div class="mt-3 flex items-center justify-between gap-3">
                                        <span class="settings-mini-text">{{ $item['description'] }}</span>
                                        @if($item['available'] && $item['route'])
                                            <a href="{{ $item['route'] }}" class="pd-btn pd-btn-primary settings-action">
                                                Yönet
                                            </a>
                                        @else
                                            <span class="settings-mini-text">{{ $item['badge'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                </div>
            @endforeach
        </div>

        <div class="settings-compact-card">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3 class="text-[15px] font-semibold text-gray-900">Paket ve Kullanım</h3>
                    <p class="mt-1 settings-mini-text">Paket bilgisi ve kullanım limitleri daha kompakt özetlenir.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge-blue">{{ $packageLabel }}</span>
                    <span class="badge {{ $statusBadgeMap[$subscription['severity']] ?? 'badge-gray' }}">{{ $subscription['label'] }}</span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="settings-task-item">
                    <div class="settings-mini-text">Paket</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $packageLabel }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Paket Durumu</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $subscription['label'] }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Firma</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $tenant->name }}</div>
                </div>
                <div class="settings-task-item">
                    <div class="settings-mini-text">Durum Özeti</div>
                    <div class="mt-1 text-sm font-semibold text-gray-900">{{ $subscription['days_remaining'] !== null ? 'Kalan gün: '.$subscription['days_remaining'] : 'Aktif kullanım' }}</div>
                </div>
            </div>

            <div class="settings-usage-grid mt-4">
                @foreach($usageSnapshot as $usage)
                    <div class="settings-usage-card">
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-[13px] font-semibold text-gray-900">{{ $usage['label'] }}</div>
                            <span class="badge {{ $usageBadgeMap[$usage['status']] ?? 'badge-gray' }}">{{ $usageLabelMap[$usage['status']] ?? ucfirst($usage['status']) }}</span>
                        </div>
                        <div class="mt-2 text-lg font-semibold text-gray-900">{{ $usage['current'] }} <span class="text-sm font-normal text-gray-500">/ {{ $usage['limit'] ?? 'Limitsiz' }}</span></div>
                        @if($usage['percentage'] !== null)
                            <div class="mt-1 settings-mini-text">{{ $usage['percentage'] }}% kullanım</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="settings-task-grid">
            <div class="settings-compact-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Modül Görünümü</h3>
                <p class="mt-1 settings-mini-text">Aktif ve kapalı modüller kullanıcı dilinde özetlenir.</p>
                <div class="mt-4">
                    <div class="settings-mini-text">Temel Modüller</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($moduleSummary['core'] as $module)
                            <span class="badge badge-green">{{ $module['label'] }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="mt-4">
                    <div class="settings-mini-text">Aktif Modüller</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($moduleSummary['enabled_optional'] as $module)
                            <span class="badge badge-blue">{{ $module['label'] }}</span>
                        @empty
                            <span class="settings-mini-text">Ek aktif modül yok.</span>
                        @endforelse
                    </div>
                </div>
                <div class="mt-4">
                    <div class="settings-mini-text">Kapalı Modüller</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($moduleSummary['disabled_optional'] as $module)
                            <span class="badge badge-gray">{{ $module['label'] }}</span>
                        @empty
                            <span class="settings-mini-text">Kapalı opsiyonel modül görünmüyor.</span>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="settings-compact-card">
                <h3 class="text-[15px] font-semibold text-gray-900">Portal ve Uyarılar</h3>
                <p class="mt-1 settings-mini-text">Müşteri portalı durumu ve kritik kullanım sinyalleri tek kartta görünür.</p>
                <div class="mt-4 space-y-3">
                    @foreach($portalSummary as $item)
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-gray-600">{{ $item['label'] }}</span>
                            <span class="badge {{ $sectionBadgeMap[$item['tone']] ?? 'badge-gray' }}">{{ $item['value'] }}</span>
                        </div>
                    @endforeach
                </div>

                @if(!empty($usageWarnings))
                    <div class="mt-4 space-y-2">
                        @foreach($usageWarnings as $warning)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium text-amber-900">{{ $warning['label'] }}</span>
                                    <span class="badge {{ $usageBadgeMap[$warning['status']] ?? 'badge-amber' }}">{{ $usageLabelMap[$warning['status']] ?? ucfirst($warning['status']) }}</span>
                                </div>
                                <div class="mt-1 settings-mini-text text-amber-800">{{ $warning['current'] }} / {{ $warning['limit'] ?? 'Limitsiz' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div id="dosya-ve-calisma-klasoru" class="settings-compact-card">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h3 class="text-[15px] font-semibold text-gray-900">Dosya ve Çalışma Klasörü</h3>
                        <p class="mt-1 settings-mini-text">Yeni sipariş ve iş formu klasörlerinin kök adını güvenli şekilde yönetin.</p>
                    </div>
                    <span class="badge badge-green">Display Path</span>
                </div>

                <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-5 space-y-5">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label for="work_folder_root_name" class="block text-sm font-medium text-gray-700">Çalışma Klasörü Kök Adı</label>
                            <div class="mt-1">
                                <input
                                    id="work_folder_root_name"
                                    name="work_folder_root_name"
                                    type="text"
                                    value="{{ old('work_folder_root_name', $workFolderRootName) }}"
                                    class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-gray-900"
                                    placeholder="ISLER">
                            </div>
                            @error('work_folder_root_name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 settings-mini-text">
                                Sipariş ve İş Formu çalışma klasörlerinin altında oluşacağı ana klasör adıdır. Örnek: ISLER, GRAFIK-ISLERI, SIPARIS-DOSYALARI.
                            </p>
                            <p class="mt-2 text-sm text-gray-600">
                                Kaydedilecek normalize değer: <strong id="work-folder-root-name-normalized">{{ $workFolderRootName }}</strong>
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preview Path</label>
                            <div class="mt-1 break-all rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-900">
                                {{ $workFolderPreviewPath }}
                            </div>
                            <p class="mt-2 settings-mini-text">
                                Bu ayar yeni oluşacak çalışma klasörleri için kullanılır. Mevcut klasörler otomatik yeniden adlandırılmaz.
                            </p>
                        </div>
                    </div>

                    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        Absolute veya fiziksel path gösterilmez. Panelde yalnız güvenli display path yapısı kullanılır.
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="pd-btn pd-btn-primary">
                            Kaydet
                        </button>
                    </div>
                </form>
        </div>
</div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const input = document.getElementById('work_folder_root_name');
        const normalizedOutput = document.getElementById('work-folder-root-name-normalized');

        if (!input || !normalizedOutput) {
            return;
        }

        const replacements = {
            'Ç': 'C', 'ç': 'c',
            'Ğ': 'G', 'ğ': 'g',
            'İ': 'I', 'I': 'I', 'ı': 'i',
            'Ö': 'O', 'ö': 'o',
            'Ş': 'S', 'ş': 's',
            'Ü': 'U', 'ü': 'u'
        };

        const normalizeValue = (value) => {
            const prepared = String(value || '').trim();

            if (prepared === '') {
                return 'ISLER';
            }

            const replaced = prepared.replace(/[ÇçĞğİIıÖöŞşÜü]/g, (char) => replacements[char] ?? char);
            const ascii = replaced.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
            const normalized = ascii
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 32)
                .replace(/-+$/g, '');

            return normalized || 'ISLER';
        };

        const syncNormalizedValue = () => {
            normalizedOutput.textContent = normalizeValue(input.value);
        };

        input.addEventListener('input', syncNormalizedValue);
        syncNormalizedValue();
    }());
</script>
@endpush
