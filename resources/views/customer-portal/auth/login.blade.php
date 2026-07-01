<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Girişi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background-color: #f6f7f9; }
    </style>
</head>
<body class="min-h-screen bg-stone-100">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-stone-900 text-white text-lg font-bold">
                    P
                </div>
                <h1 class="text-2xl font-semibold text-stone-900">Müşteri Portalı</h1>
                @if($tenant)
                    <p class="mt-2 text-sm text-stone-600">{{ $tenant->name }}</p>
                    <p class="text-sm text-stone-500">Portal hesabınızla giriş yapın.</p>
                @else
                    <p class="mt-2 text-sm text-stone-600">Genel Müşteri Portalı Girişi</p>
                    <p class="text-sm text-stone-500">Tenant bilginiz olmadan merkezi müşteri portal ekranındasınız. Mümkünse firmanızın portal linkinden giriş yapın.</p>
                @endif
            </div>

            @if (session('success'))
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('customer.login.submit') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="mb-2 block text-sm font-medium text-stone-700">{{ $tenant ? 'E-posta' : 'Kullanıcı adı veya e-posta' }}</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                        class="w-full rounded-xl border border-stone-300 px-4 py-3 text-stone-900 outline-none transition focus:border-stone-900"
                    >
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-stone-700">Şifre</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full rounded-xl border border-stone-300 px-4 py-3 text-stone-900 outline-none transition focus:border-stone-900"
                    >
                </div>

                <label class="flex items-center gap-3 text-sm text-stone-600">
                    <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-stone-300 text-stone-900 focus:ring-stone-900">
                    Beni hatırla
                </label>

                <button
                    type="submit"
                    class="w-full rounded-xl bg-stone-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-stone-800"
                >
                    Giriş Yap
                </button>
            </form>

            <div class="mt-6 space-y-2 text-center text-sm">
                @if($tenant)
                    <div>
                        <a href="{{ route('customer.password.request') }}" class="font-medium text-stone-800 hover:text-stone-900">Şifremi unuttum</a>
                    </div>
                    <p class="text-stone-500">Davet linkiniz varsa bu bağlantı üzerinden şifrenizi belirleyebilirsiniz.</p>
                @else
                    <p class="text-stone-500">Mümkünse firmanızın size verdiği müşteri portal bağlantısını kullanın.</p>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
