@extends('layouts.prodelya-admin')

@section('title', 'WhatsApp Hazır Mesaj')
@section('page_title', 'WhatsApp Hazır Mesaj')
@section('page_subtitle', 'Bu özellik WhatsApp’ı hazır mesajla açar; otomatik API gönderimi yapmaz.')
@section('hide_side_summary', true)

@section('content')
<style>
    .whatsapp-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .whatsapp-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
</style>

<div class="whatsapp-shell">
@if(session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="xl:col-span-2 space-y-6">
        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">WhatsApp Hazır Mesaj Ayarları</h3>
                <p class="mt-1 text-sm text-gray-500">Telefon numarası düzenlenir; sistem sizin yerinize otomatik mesaj göndermez.</p>

                <form method="POST" action="{{ route('admin.settings.notifications.whatsapp.update') }}" class="mt-6 space-y-5">
                    @csrf
                    @method('PUT')

                    <label class="inline-flex items-center gap-3 text-sm font-medium text-gray-700">
                        <input type="checkbox" name="whatsapp_is_active" value="1" class="rounded border-gray-300 text-blue-600" {{ old('whatsapp_is_active', $whatsappSettings['is_active']) ? 'checked' : '' }}>
                        WhatsApp hazır mesaj aktif
                    </label>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label for="whatsapp_default_country_code" class="block text-sm font-medium text-gray-700">Varsayılan Ülke Kodu</label>
                            <input id="whatsapp_default_country_code" name="whatsapp_default_country_code" type="text" value="{{ old('whatsapp_default_country_code', $whatsappSettings['default_country_code']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('whatsapp_default_country_code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="whatsapp_sender_label" class="block text-sm font-medium text-gray-700">Gönderen Etiketi</label>
                            <input id="whatsapp_sender_label" name="whatsapp_sender_label" type="text" value="{{ old('whatsapp_sender_label', $whatsappSettings['sender_label']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('whatsapp_sender_label')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="whatsapp_default_signature" class="block text-sm font-medium text-gray-700">Varsayılan İmza Metni</label>
                            <textarea id="whatsapp_default_signature" name="whatsapp_default_signature" rows="3" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">{{ old('whatsapp_default_signature', $whatsappSettings['default_signature']) }}</textarea>
                            @error('whatsapp_default_signature')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="whatsapp_test_phone" class="block text-sm font-medium text-gray-700">Test Telefonu</label>
                            <input id="whatsapp_test_phone" name="whatsapp_test_phone" type="text" value="{{ old('whatsapp_test_phone', $whatsappSettings['test_phone']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('whatsapp_test_phone')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                        Ayarları Kaydet
                    </button>
                </form>
            </div>
        </div>

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Hazır Mesaj Oluştur</h3>
                <p class="mt-1 text-sm text-gray-500">Mesajı önce önizleyin, sonra WhatsApp’ta açılacak linki oluşturun.</p>

                <form method="POST" action="{{ route('admin.settings.notifications.whatsapp.preview') }}" class="mt-6 space-y-5">
                    @csrf

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label for="customer_name" class="block text-sm font-medium text-gray-700">Alıcı Adı</label>
                            <input id="customer_name" name="customer_name" type="text" value="{{ old('customer_name') }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('customer_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="recipient_phone" class="block text-sm font-medium text-gray-700">Telefon</label>
                            <input id="recipient_phone" name="recipient_phone" type="text" value="{{ old('recipient_phone', $whatsappSettings['test_phone']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('recipient_phone')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="message_type" class="block text-sm font-medium text-gray-700">Mesaj Türü</label>
                            <select id="message_type" name="message_type" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                                @foreach($messageTypeOptions as $value => $label)
                                    <option value="{{ $value }}" {{ old('message_type', 'general') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('message_type')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="public_link" class="block text-sm font-medium text-gray-700">Link / URL</label>
                            <input id="public_link" name="public_link" type="url" value="{{ old('public_link') }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('public_link')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="message" class="block text-sm font-medium text-gray-700">Mesaj Metni</label>
                            <textarea id="message" name="message" rows="5" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">{{ old('message') }}</textarea>
                            @error('message')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                            Önizle
                        </button>
                        <button type="submit" formaction="{{ route('admin.settings.notifications.whatsapp.create-link') }}" class="inline-flex items-center justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700">
                            WhatsApp Linki Oluştur
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        @if(session('whatsapp_preview'))
            @php($preview = session('whatsapp_preview'))
            <div class="pd-soft-card">
                <div class="px-4 py-4 sm:px-5 sm:py-5">
                    <h3 class="text-lg font-medium text-gray-900">Önizleme</h3>
                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Telefon</div>
                        <div class="mt-1 text-sm font-medium text-gray-900">{{ $preview['phone'] ?: '—' }}</div>
                        <div class="mt-4 text-xs uppercase tracking-wide text-gray-500">Mesaj</div>
                        <pre class="mt-2 whitespace-pre-wrap text-sm text-gray-800">{{ $preview['message'] }}</pre>
                    </div>
                </div>
            </div>
        @endif

        @if(session('whatsapp_result'))
            @php($result = session('whatsapp_result'))
            <div class="pd-soft-card">
                <div class="px-4 py-4 sm:px-5 sm:py-5">
                    <h3 class="text-lg font-medium text-gray-900">Hazır Link</h3>
                    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-4">
                        <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-green-800 underline break-all">
                            {{ $result['url'] }}
                        </a>
                        <div class="mt-4">
                            <a href="{{ $result['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700">
                                WhatsApp'ta Aç
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Nasıl Çalışır?</h3>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    <li>Bu özellik WhatsApp API kullanmaz; yalnız hazır mesajla WhatsApp açar.</li>
                    <li>Fiyat, KDV, bakiye ve maliyet bilgileri mesajdan temizlenir.</li>
                    <li>Ham token, file path ve group code gösterilmez.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
