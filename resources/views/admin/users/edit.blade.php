@extends('layouts.prodelya-admin')

@section('title', 'Kullanıcı Düzenle')
@section('page_title', 'Kullanıcı Düzenle')
@section('page_subtitle', 'Abone Firma ekip kullanıcısının rol ve yetkilerini güvenli şekilde güncelleyin.')

@section('content')
<form method="POST" action="{{ route('admin.users.update', $userRecord) }}">
    @csrf
    @method('PUT')
    @include('admin.users._form')

    <div class="flex items-center justify-between gap-3" style="margin-top: 16px;">
        <a href="{{ route('admin.users.index') }}" class="pd-btn pd-btn-light">Geri Dön</a>
        <button type="submit" class="pd-btn pd-btn-primary">Değişiklikleri Kaydet</button>
    </div>
</form>
@endsection
