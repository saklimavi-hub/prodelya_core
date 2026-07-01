@extends('layouts.prodelya-admin')

@section('title', 'Tenant Hizmet Kalemi Düzenle')
@section('page_title', 'Tenant Hizmet Kalemi Düzenle')
@section('page_subtitle', 'Merkezi tenant hizmet kaleminin kod, tutar ve durum bilgisini güncelleyin.')

@section('content')
<form method="POST" action="{{ route('admin.super.services.update', $service) }}">
    @include('super-admin.services._form', ['isEdit' => true, 'service' => $service])
</form>
@endsection
