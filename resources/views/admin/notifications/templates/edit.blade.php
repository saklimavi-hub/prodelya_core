@extends('layouts.prodelya-admin')

@section('title', 'Bildirim Şablonunu Düzenle')
@section('page_title', 'Bildirim Şablonunu Düzenle')
@section('page_subtitle', 'Varsayılan içeriği tenant kullanım dilinize göre güncelleyin.')
@section('hide_side_summary', true)

@section('content')
    @include('admin.notifications.templates._form')
@endsection
