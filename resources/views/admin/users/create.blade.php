@extends('layouts.prodelya-admin')

@section('title', 'Yeni Kullanıcı')
@section('page_title', 'Yeni Kullanıcı')
@section('page_subtitle', 'Owner sonrası ekip kurulumunu bu ekrandan başlatın.')

@section('content')
<form method="POST" action="{{ route('admin.users.store') }}">
    @csrf
    @include('admin.users._form')

    <div class="flex items-center justify-between gap-3" style="margin-top: 16px;">
        <a href="{{ route('admin.users.index') }}" class="pd-btn pd-btn-light">Vazgeç</a>
        <button type="submit" class="pd-btn pd-btn-primary">Kullanıcıyı Kaydet</button>
    </div>
</form>
@endsection
