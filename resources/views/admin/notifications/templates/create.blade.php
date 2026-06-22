@extends('layouts.prodelya-admin')

@section('title', 'Yeni Bildirim Şablonu')
@section('page_title', 'Yeni Bildirim Şablonu')
@section('page_subtitle', 'Hazır akışlardan yola çıkarak yeni bir tenant şablonu oluşturun.')
@section('hide_side_summary', true)

@section('content')
    @include('admin.notifications.templates._form')
@endsection
