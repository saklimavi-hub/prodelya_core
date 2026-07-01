@extends('layouts.prodelya-admin')

@section('title', 'Yeni Ödeme Provider')
@section('page_title', 'Yeni Ödeme Provider')
@section('page_subtitle', 'Ortak payment backbone için provider kaydı ve shared credential hazırlığı.')

@section('content')
<form method="POST" action="{{ $formAction }}">
    @include('super-admin.payment-providers._form')
</form>
@endsection
