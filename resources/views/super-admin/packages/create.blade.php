@extends('layouts.prodelya-admin')

@section('title', 'Yeni Paket')
@section('page_title', 'Yeni Paket')
@section('page_subtitle', 'Merkezi paket şablonu oluşturun.')

@section('content')
<form method="POST" action="{{ route('admin.super.packages.store') }}">
    @include('super-admin.packages._form', ['isEdit' => false])
</form>
@endsection
