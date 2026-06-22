<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifre Belirle</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background-color: #f6f7f9; }
    </style>
</head>
<body class="min-h-screen bg-stone-100">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-lg rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-stone-900 text-white text-lg font-bold">P</div>
                <h1 class="text-2xl font-semibold text-stone-900">Şifrenizi Belirleyin</h1>
                <p class="mt-2 text-sm text-stone-600">{{ $tenant->name }} müşteri portalı için hesabınızı aktive edin.</p>
                <p class="text-sm text-stone-500">{{ $portalUser->safeDisplayName() }} olarak devam ediyorsunuz.</p>
            </div>

            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('customer.invite.accept.submit', ['token' => $token]) }}" class="space-y-5">
                @csrf
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-stone-700">Yeni Şifre</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-stone-300 px-4 py-3 text-stone-900 outline-none transition focus:border-stone-900">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-medium text-stone-700">Şifre Tekrar</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-stone-300 px-4 py-3 text-stone-900 outline-none transition focus:border-stone-900">
                </div>
                <p class="text-sm text-stone-500">En az 8 karakter uzunluğunda bir şifre belirleyin.</p>
                <button type="submit" class="w-full rounded-xl bg-stone-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-stone-800">Hesabımı Aktifleştir</button>
            </form>
        </div>
    </div>
</body>
</html>
