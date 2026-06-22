@extends('layouts.prodelya-admin')

@section('title', 'Firma Bilgileri')
@section('page_title', 'Firma Bilgileri')
@section('page_subtitle', 'Tenant firma kimliğini müşteri ve cari kartlardan ayrı olarak yönetin.')

@section('content')
<div class="max-w-5xl space-y-6">
    @if(session('success'))
        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-xl border border-blue-100 bg-blue-50 px-5 py-4 text-sm text-blue-900">
        <div class="font-semibold">Bu bilgiler teklif PDF, iş formu ve müşteri ekranlarında kullanılacaktır.</div>
        <p class="mt-1 text-blue-800">Bu alan müşteri/cari kartı değildir; tenant firma profilidir. Kendi firma kimliğinizi burada yönetin.</p>
    </div>

    <form method="POST" action="{{ route('admin.settings.company-profile.update') }}" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        @csrf

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div>
                <label for="display_name" class="block text-sm font-medium text-gray-700">Görünen Firma Adı</label>
                <input id="display_name" name="display_name" type="text" value="{{ old('display_name', $profile['display_name']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900" required>
                @error('display_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="legal_name" class="block text-sm font-medium text-gray-700">Yasal Ünvan</label>
                <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name', $profile['legal_name']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('legal_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="tax_office" class="block text-sm font-medium text-gray-700">Vergi Dairesi</label>
                <input id="tax_office" name="tax_office" type="text" value="{{ old('tax_office', $profile['tax_office']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('tax_office')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="tax_number" class="block text-sm font-medium text-gray-700">Vergi Numarası</label>
                <input id="tax_number" name="tax_number" type="text" value="{{ old('tax_number', $profile['tax_number']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('tax_number')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700">Telefon</label>
                <input id="phone" name="phone" type="text" value="{{ old('phone', $profile['phone']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('phone')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">E-posta</label>
                <input id="email" name="email" type="email" value="{{ old('email', $profile['email']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label for="website" class="block text-sm font-medium text-gray-700">Web Sitesi</label>
                <input id="website" name="website" type="text" value="{{ old('website', $profile['website']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900" placeholder="www.saklimavi.com">
                @error('website')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-xs text-gray-500">Protokol yazılmazsa güvenli şekilde `https://` ile tamamlanır.</p>
            </div>
            <div class="md:col-span-2">
                <label for="address" class="block text-sm font-medium text-gray-700">Adres</label>
                <textarea id="address" name="address" rows="4" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">{{ old('address', $profile['address']) }}</textarea>
                @error('address')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="district" class="block text-sm font-medium text-gray-700">İlçe</label>
                <input id="district" name="district" type="text" value="{{ old('district', $profile['district']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('district')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="city" class="block text-sm font-medium text-gray-700">İl</label>
                <input id="city" name="city" type="text" value="{{ old('city', $profile['city']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('city')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="country" class="block text-sm font-medium text-gray-700">Ülke</label>
                <input id="country" name="country" type="text" value="{{ old('country', $profile['country']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('country')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="postal_code" class="block text-sm font-medium text-gray-700">Posta Kodu</label>
                <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $profile['postal_code']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                @error('postal_code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Logo yükleme bu fazda güvenli şekilde açılmadı. Sahte upload alanı gösterilmiyor; logo akışı ayrı bir dosya güvenliği fazında ele alınacak.
        </div>

        <div class="mt-6 flex items-center justify-between gap-3 border-t border-gray-100 pt-5">
            <div class="text-sm text-gray-500">
                Görünen ad boş kalmaz. Boş diğer alanlar güvenli fallback ile kullanılır.
            </div>
            <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
        </div>
    </form>
</div>
@endsection
