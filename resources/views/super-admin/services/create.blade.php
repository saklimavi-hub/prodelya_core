@extends('layouts.prodelya-admin')

@section('title', 'Yeni Hizmet Kalemi')
@section('page_title', 'Yeni Hizmet Kalemi')
@section('page_subtitle', 'Tenant SaaS cari operasyonlarında kullanılacak merkezi hizmet kalemini oluşturun.')

@section('content')
<form method="POST" action="{{ route('admin.super.services.store') }}">
    @include('super-admin.services._form', ['isEdit' => false])
</form>
@endsection
