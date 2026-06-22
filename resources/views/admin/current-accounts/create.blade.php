@extends('layouts.prodelya-admin')

@section('title', 'Yeni Cari Kart')
@section('page_title', 'Yeni Cari Kart')
@section('page_subtitle', 'Tenant içinde yeni bir cari kimliği oluşturun ve gerekirse company mapping altyapısını otomatik başlatın.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-light">İptal</a>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.current-accounts.store') }}">
    @csrf

    @include('admin.current-accounts._form')

    <div class="flex justify-end gap-3">
        <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Cari Kartı Kaydet</button>
    </div>
</form>
@endsection
