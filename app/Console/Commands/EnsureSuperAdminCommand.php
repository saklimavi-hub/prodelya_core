<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnsureSuperAdminCommand extends Command
{
    protected $signature = 'app:ensure-super-admin
        {email : Platform admin yapilacak kullanici e-posta adresi}
        {--name= : Kullanici olusturulacaksa kullanilacak ad}
        {--password= : Kullanici olusturulacaksa veya sifre guncellenecekse atanacak sifre}
        {--reset-password : Var olan kullanicinin sifresini de guncelle}';

    protected $description = 'Var olan bir kullaniciyi platform admin yapar veya yeni platform admin kullanicisi olusturur.';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: 'Platform Admin'));
        $resetPassword = (bool) $this->option('reset-password');

        if ($email === '') {
            $this->error('Gecerli bir e-posta adresi gerekli.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $password = (string) $this->option('password');
        $generatedPassword = false;

        if (! $user && $password === '') {
            $password = Str::password(16, true, true, true, false);
            $generatedPassword = true;
        }

        $this->newLine();
        $this->info('Platform Admin Bootstrap Ozeti');
        $this->line('E-posta: ' . $email);
        $this->line('Islem: ' . ($user ? 'Mevcut kullanici platform admin olarak guncellenecek' : 'Yeni platform admin kullanicisi olusturulacak'));
        $this->line('Platform admin bayragi: true');
        $this->line('Sifre guncellenecek mi: ' . (($password !== '' && (! $user || $resetPassword)) ? 'Evet' : 'Hayir'));

        if ($this->input->isInteractive() && ! $this->confirm('Devam edilsin mi?', true)) {
            $this->warn('Islem iptal edildi.');

            return self::INVALID;
        }

        if ($user) {
            $user->forceFill([
                'is_platform_admin' => true,
            ]);

            if ($password !== '' && $resetPassword) {
                $user->password = $password;
            }

            $user->save();
        } else {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password !== '' ? $password : Hash::make(Str::random(32)),
                'is_platform_admin' => true,
            ]);
        }

        $this->newLine();
        $this->info('Platform admin hazir.');
        $this->line('Kullanici ID: ' . $user->id);
        $this->line('E-posta: ' . $user->email);
        $this->line('is_platform_admin: ' . ($user->fresh()->is_platform_admin ? 'true' : 'false'));

        if ($generatedPassword) {
            $this->warn('Olusturulan gecici sifre: ' . $password);
        }

        if ($password !== '' && $resetPassword && ! $generatedPassword) {
            $this->warn('Kullanici sifresi bu komutla guncellendi.');
        }

        return self::SUCCESS;
    }
}
