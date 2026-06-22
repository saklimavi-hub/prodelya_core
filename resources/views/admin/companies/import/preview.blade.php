@extends('layouts.prodelya-admin')

@section('title', 'İçe Aktar Önizleme')

@section('content')
<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="md:flex md:items-center md:justify-between mb-6">
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                İçe Aktar Önizleme
            </h2>
            <p class="mt-1 text-sm text-gray-500">Yüklenen dosyadaki verileri kontrol edin ve alan eşlemesi yapın.</p>
        </div>
        <div class="mt-4 flex md:mt-0 md:ml-4">
            <a href="{{ route('admin.companies.import.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                İptal
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-blue-100 rounded-lg p-3">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Toplam Kayıt</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $totalRecords }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-green-100 rounded-lg p-3">
                        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Yeni Kayıt</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $totalRecords }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-amber-100 rounded-lg p-3">
                        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Potansiyel Mükerrer</dt>
                            <dd class="text-lg font-medium text-gray-900">0</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-red-100 rounded-lg p-3">
                        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">Hata</dt>
                            <dd class="text-lg font-medium text-gray-900">0</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Duplicate Check Summary -->
    <div class="card mb-6">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Mükerrer Kontrol Özeti</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($duplicateChecks as $check => $description)
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010-1.414l-8 8a1 1 0 01-1.414 0l-8-8a1 1 0 011.414 0l8 8a1 1 0 001.414 0z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm text-gray-700">{{ $description }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Field Mapping -->
    <div class="card mb-6">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Alan Eşleme</h3>
            <p class="text-sm text-gray-500 mb-4">Dosyadaki sütunları sistem alanlarıyla eşleştirin.</p>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dosya Alanı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sistem Alanı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Firma Ünvanı</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">legal_name</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Eşleşti</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Kısa Ad</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">short_name</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Eşleşti</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Cari Tipi</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">roles</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Eşleşti</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Vergi No</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">tax_number</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Eşleşti</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sample Data Preview -->
    <div class="card mb-6">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Örnek Veri Önizlemesi (İlk 10 Kayıt)</h3>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Firma Ünvanı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cari Tipi</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vergi No</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-posta</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Telefon</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Yetkili</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Adres</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($sampleData as $index => $record)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $record['Firma Ünvanı'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                @foreach(explode(',', $record['Cari Tipi']) as $role)
                                    <span class="badge badge-blue">{{ trim($role) }}</span>
                                @endforeach
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $record['Vergi No'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $record['E-posta'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $record['Telefon'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $record['Yetkili Adı'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $record['Adres'] }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge badge-green">Yeni</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            
            @if(count($sampleData) > 10)
                <div class="mt-4 text-center text-sm text-gray-500">
                    ... ve {{ count($sampleData) - 10 }} kayıt daha
                </div>
            @endif
        </div>
    </div>

    <!-- Import Actions -->
    <div class="flex justify-end space-x-3">
        <a href="{{ route('admin.companies.import.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
            Geri Dön
        </a>
        
        <form action="{{ route('admin.companies.import.store') }}" method="POST">
            @csrf
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 20 20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"></path>
                </svg>
                İçe Aktarı Başlat
            </button>
        </form>
    </div>
</div>
@endsection

@section('side_summary')
<div class="space-y-4">
    <!-- Import Status -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Import Durumu</h3>
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span class="text-gray-600">Dosya Yükleme</span>
                <span class="badge badge-green">Tamamlandı</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-600">Alan Eşleme</span>
                <span class="badge badge-green">Tamamlandı</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-600">Mükerrer Kontrol</span>
                <span class="badge badge-green">Tamamlandı</span>
            </div>
        </div>
    </div>

    <!-- File Information -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Dosya Bilgileri</h3>
        <div class="text-sm text-gray-500">
            <p><strong>Dosya Adı:</strong> sample.csv</p>
            <p><strong>Boyut:</strong> 125 KB</p>
            <p><strong>Format:</strong> CSV</p>
            <p><strong>Yüklenme:</strong> {{ now()->format('d.m.Y H:i') }}</p>
        </div>
    </div>

    <!-- Processing Options -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">İşlem Seçenekleri</h3>
        <div class="space-y-2 text-sm text-gray-500">
            <p>• Mükerrer kayıtları atla</p>
            <p>• Mevcut kayıtları güncelle</p>
            <p>• Hatalı kayıtları atla</p>
            <p>• Audit log tut</p>
        </div>
    </div>

    <!-- Next Steps -->
    <div>
        <h3 class="text-sm font-medium text-gray-900 mb-3">Sonraki Adımlar</h3>
        <div class="text-sm text-gray-500">
            <p>Import tamamlandıktan sonra:</p>
            <ul class="list-disc list-inside space-y-1 ml-4">
                <li>İçe aktarılan cari kartları kontrol edin</li>
                <li>Gerekirse yetkili kişileri ve adresleri ekleyin</li>
                <li>Portal erişimlerini yapılandırın</li>
            </ul>
        </div>
    </div>
</div>
@endsection
