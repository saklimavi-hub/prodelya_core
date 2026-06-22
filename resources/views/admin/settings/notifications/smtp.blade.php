@extends('layouts.prodelya-admin')

@section('title', 'Mail Gönderimi')
@section('page_title', 'Mail Gönderimi')
@section('page_subtitle', 'Tenant bazlı e-posta gönderim ayarlarını güvenli ve anlaşılır şekilde yönetin.')
@section('hide_side_summary', true)

@section('content')
@php
    $selectedPort = (string) old('smtp_port', $smtpSettings['smtp_port']);
    $selectedEncryption = (string) old('smtp_encryption', $smtpSettings['smtp_encryption'] ?: 'tls');
    $smtpUsername = trim((string) old('smtp_username', $smtpSettings['smtp_username']));
    $fromEmail = trim((string) old('smtp_from_email', $smtpSettings['smtp_from_email']));
    $showIdentityWarning = $smtpUsername !== '' && $fromEmail !== '' && strcasecmp($smtpUsername, $fromEmail) !== 0;
    $portHint = match ($selectedPort) {
        '465' => '465 portu genelde ssl ile kullanılır.',
        '587' => '587 portu genelde tls ile kullanılır.',
        default => '465 genelde ssl, 587 genelde tls ile kullanılır.',
    };
@endphp
<style>
    .smtp-shell {
        font-family: Arial, Helvetica, sans-serif;
    }
    .smtp-shell .pd-soft-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
</style>

<div class="smtp-shell">
@if(session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ session('error') }}
    </div>
@endif

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="xl:col-span-2">
        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Mail Gönderim Ayarları</h3>
                        <p class="mt-1 text-sm text-gray-500">Şifre hiçbir zaman düz gösterilmez. Boş bırakırsanız mevcut şifre korunur.</p>
                    </div>
                    <span class="badge {{ $smtpSettings['smtp_password_configured'] ? 'badge-green' : 'badge-amber' }}">
                        {{ $smtpSettings['smtp_password_configured'] ? 'Şifre Tanımlı' : 'Şifre Tanımlı Değil' }}
                    </span>
                </div>

                <form method="POST" action="{{ route('admin.settings.notifications.smtp.update') }}" class="mt-6 space-y-5">
                    @csrf
                    @method('PUT')

                    <label class="inline-flex items-center gap-3 text-sm font-medium text-gray-700">
                        <input type="checkbox" name="smtp_is_active" value="1" class="rounded border-gray-300 text-blue-600" {{ old('smtp_is_active', $smtpSettings['smtp_is_active']) ? 'checked' : '' }}>
                        Mail gönderimi aktif
                    </label>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label for="smtp_host" class="block text-sm font-medium text-gray-700">SMTP Host</label>
                            <input id="smtp_host" name="smtp_host" type="text" value="{{ old('smtp_host', $smtpSettings['smtp_host']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_host')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="smtp_port" class="block text-sm font-medium text-gray-700">SMTP Port</label>
                            <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="{{ old('smtp_port', $smtpSettings['smtp_port']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_port')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="mt-2 text-sm text-gray-500">{{ $portHint }}</p>
                        </div>
                        <div>
                            <label for="smtp_username" class="block text-sm font-medium text-gray-700">SMTP Kullanıcı Adı</label>
                            <input id="smtp_username" name="smtp_username" type="text" value="{{ old('smtp_username', $smtpSettings['smtp_username']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_username')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="smtp_password" class="block text-sm font-medium text-gray-700">SMTP Şifre</label>
                            <input id="smtp_password" name="smtp_password" type="password" value="" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_password')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="mt-2 text-sm text-gray-500">Boş bırakılırsa mevcut şifre korunur. Yandex kullanıyorsanız uygulama parolası girin.</p>
                        </div>
                        <div>
                            <label for="smtp_encryption" class="block text-sm font-medium text-gray-700">Şifreleme</label>
                            <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                                @foreach(['none' => 'none', 'ssl' => 'ssl', 'tls' => 'tls'] as $value => $label)
                                    <option value="{{ $value }}" {{ old('smtp_encryption', $smtpSettings['smtp_encryption'] ?: 'tls') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('smtp_encryption')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="mt-2 text-sm text-gray-500">
                                Seçili ayar: <span class="font-medium text-gray-700">{{ $selectedEncryption }}</span>.
                                465 için genelde ssl, 587 için genelde tls önerilir.
                            </p>
                        </div>
                        <div>
                            <label for="smtp_test_email" class="block text-sm font-medium text-gray-700">Test Mail Adresi</label>
                            <input id="smtp_test_email" name="smtp_test_email" type="email" value="{{ old('smtp_test_email', $smtpSettings['smtp_test_email']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_test_email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="smtp_from_name" class="block text-sm font-medium text-gray-700">From Name</label>
                            <input id="smtp_from_name" name="smtp_from_name" type="text" value="{{ old('smtp_from_name', $smtpSettings['smtp_from_name']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_from_name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="smtp_from_email" class="block text-sm font-medium text-gray-700">From Email</label>
                            <input id="smtp_from_email" name="smtp_from_email" type="email" value="{{ old('smtp_from_email', $smtpSettings['smtp_from_email']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_from_email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            @if($showIdentityWarning)
                                <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    Bazı sağlayıcılar From Email ile SMTP kullanıcı adının aynı olmasını ister.
                                </div>
                            @endif
                        </div>
                        <div class="md:col-span-2">
                            <label for="smtp_reply_to_email" class="block text-sm font-medium text-gray-700">Reply-To Email</label>
                            <input id="smtp_reply_to_email" name="smtp_reply_to_email" type="email" value="{{ old('smtp_reply_to_email', $smtpSettings['smtp_reply_to_email']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                            @error('smtp_reply_to_email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            Ayarları Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Test Mail</h3>
                <p class="mt-1 text-sm text-gray-500">Kaydedilmiş ayarla seçtiğiniz adrese anlaşılır bir test maili gönderir.</p>
                <p class="mt-2 text-sm text-gray-500">Hata olursa güvenli bir özet gösterilir; şifre ve gizli bilgiler asla görünmez.</p>

                <form method="POST" action="{{ route('admin.settings.notifications.smtp.test') }}" class="mt-5 space-y-4">
                    @csrf
                    <div>
                        <label for="smtp_test_email_action" class="block text-sm font-medium text-gray-700">Gönderilecek Adres</label>
                        <input id="smtp_test_email_action" name="smtp_test_email" type="email" value="{{ old('smtp_test_email', $smtpSettings['smtp_test_email']) }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900">
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                        Test Mail Gönder
                    </button>
                </form>
            </div>
        </div>

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Yandex Mail için önerilen ayarlar</h3>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    <li>Host: <span class="font-medium text-gray-900">smtp.yandex.com</span></li>
                    <li>Port ve şifreleme: <span class="font-medium text-gray-900">465 / ssl</span> veya <span class="font-medium text-gray-900">587 / tls</span></li>
                    <li>Kullanıcı adı ve From Email aynı e-posta olmalı.</li>
                    <li>Yandex tarafında e-posta programları erişimi ve uygulama parolası açık olmalı.</li>
                    <li>Normal hesap şifresi yerine Yandex uygulama parolası gerekebilir.</li>
                </ul>
            </div>
        </div>

        <div class="pd-soft-card">
            <div class="px-4 py-4 sm:px-5 sm:py-5">
                <h3 class="text-lg font-medium text-gray-900">Güvenlik</h3>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    <li>SMTP şifre değeri hiçbir zaman düz gösterilmez.</li>
                    <li>Test mail logları credential, token veya private path içermez.</li>
                    <li>Mail gönderimi aktif değilse test maili gönderilmez.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
