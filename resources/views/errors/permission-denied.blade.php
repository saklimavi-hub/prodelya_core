<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erisim Engellendi</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f7f8fa;
            color: #1f2937;
        }

        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 12px;
            font-size: 28px;
        }

        p {
            margin: 0;
            line-height: 1.6;
            color: #4b5563;
        }

        .hint {
            margin-top: 18px;
            padding: 14px 16px;
            background: #fff7ed;
            border: 1px solid #fdba74;
            border-radius: 12px;
            color: #9a3412;
        }

        .meta {
            margin-top: 10px;
            font-size: 14px;
            color: #6b7280;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .button,
        .button-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 10px;
            border: 1px solid transparent;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .button {
            background: #111827;
            color: #ffffff;
        }

        .button-secondary {
            background: #ffffff;
            border-color: #d1d5db;
            color: #111827;
        }

        .logout-form {
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Erisim Engellendi</h1>
            <p>{{ $message ?? 'Bu alana erisim yetkiniz bulunmuyor.' }}</p>

            <div class="hint">
                Bu durum genellikle bu tenant hostunda farkli bir kullanici oturumu acik oldugunda gorulur.
                Mevcut oturumu kapatip dogru tenant hesabi ile yeniden giris yapin.
            </div>

            @if (auth()->check())
                <p class="meta">
                    Acik oturum: <strong>{{ auth()->user()->email }}</strong>
                </p>
            @endif

            <div class="actions">
                <form class="logout-form" method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="button-secondary">Oturumu Kapat</button>
                </form>

                <a href="{{ route('login') }}" class="button">Tenant Girisi</a>
            </div>
        </div>
    </div>
</body>
</html>
