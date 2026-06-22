<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modül Erişimi Kapalı - Prodelya</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
    <div class="max-w-lg w-full bg-white border border-gray-200 rounded-xl shadow-sm p-8">
        <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mb-5">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 4h.01M10.29 3.86l-8 14A1 1 0 003.16 19h17.68a1 1 0 00.87-1.5l-8-14a1 1 0 00-1.74 0z"/>
            </svg>
        </div>

        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Modül erişimi kapalı</h1>
        <p class="text-sm text-gray-600 mb-6">{{ $message ?? 'Bu modül tenant paketinizde aktif değil.' }}</p>

        <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
            İlgili modül için paket veya tenant erişimi tanımlanmadan bu sayfaya erişilemez.
        </div>

        <div class="mt-6 flex gap-3">
            <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">
                Gösterge Paneli
            </a>
            <a href="{{ route('login') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-md border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Girişe Dön
            </a>
        </div>
    </div>
</body>
</html>
