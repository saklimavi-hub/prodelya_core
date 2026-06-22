@extends('layouts.prodelya-admin')

@section('title', 'Finansal Cari Hesabı Düzenle')
@section('page_title', 'Finansal Cari Hesabı Düzenle')
@section('page_subtitle', $account->safeDisplayName() . ' için teknik finans ayarlarını güncelleyin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.current-accounts.show', $account) }}" class="pd-btn pd-btn-light">Vazgeç</a>
    <a href="{{ route('admin.current-accounts.index') }}" class="pd-btn pd-btn-primary">Listeye Dön</a>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('admin.current-accounts.update', $account) }}">
    @csrf
    @method('PUT')

    @include('admin.current-accounts._form')

    <div class="flex justify-end gap-3">
        <a href="{{ route('admin.current-accounts.show', $account) }}" class="pd-btn pd-btn-light">İptal</a>
        <button type="submit" class="pd-btn pd-btn-primary">Finansal Cari Hesabı Kaydet</button>
    </div>
</form>
@endsection
