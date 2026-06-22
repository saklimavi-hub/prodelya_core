<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Müşteri Portalı Daveti</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background: #f5f5f4; margin: 0; padding: 24px;">
    <div style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #e7e5e4; border-radius: 14px; padding: 28px;">
        <h1 style="margin: 0 0 12px; font-size: 22px; color: #1c1917;">Müşteri portalı davetiniz hazır</h1>
        <p style="margin: 0 0 12px; color: #44403c;">Merhaba {{ $portalUser->safeDisplayName() }},</p>
        <p style="margin: 0 0 12px; color: #44403c;">
            {{ $tenant->name }} müşteri portalına giriş yapmanız için güvenli bir bağlantı oluşturuldu.
        </p>
        <p style="margin: 0 0 18px; color: #44403c;">
            Şifrenizi belirlemek için aşağıdaki bağlantıyı kullanın. Bu bağlantı {{ $expiresLabel }} boyunca geçerlidir.
        </p>
        <p style="margin: 0 0 24px;">
            <a href="{{ $inviteUrl }}" style="display: inline-block; background: #1c1917; color: #ffffff; text-decoration: none; padding: 12px 18px; border-radius: 10px;">Şifremi Belirle</a>
        </p>
        <p style="margin: 0 0 10px; color: #57534e; font-size: 14px;">Bağlantı açılmazsa şu adresi tarayıcınıza yapıştırabilirsiniz:</p>
        <p style="margin: 0 0 20px; color: #0f172a; font-size: 14px; word-break: break-all;">{{ $inviteUrl }}</p>
        <p style="margin: 0; color: #78716c; font-size: 13px;">
            Güvenlik notu: Bu e-postada şifreniz yer almaz. Bağlantıyı yalnızca yetkili kişilerle paylaşın.
        </p>
    </div>
</body>
</html>
