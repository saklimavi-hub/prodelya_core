@extends('layouts.prodelya-admin')

@section('title', 'Bildirim Detayi')
@section('page_title', 'Bildirim Detayi')
@section('page_subtitle', 'Sade ve sanitize edilmis bildirim log kaydi.')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.notifications.logs.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Bildirim Gecmisine Don</a>
</div>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="xl:col-span-2 space-y-6">
        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge badge-blue">{{ $eventLabel }}</span>
                    <span class="badge badge-gray">{{ $log->safeChannelLabel() }}</span>
                    <span class="badge badge-gray">{{ $log->safeAudienceLabel() }}</span>
                    <span class="badge {{ $log->status === 'failed' ? 'badge-red' : ($log->status === 'sent' ? 'badge-green' : ($log->status === 'link_created' ? 'badge-amber' : 'badge-gray')) }}">{{ $log->safeStatusLabel() }}</span>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Alici</div>
                        <div class="mt-2 text-sm font-medium text-gray-900">{{ $log->recipient_name ?: '—' }}</div>
                        <div class="mt-1 text-sm text-gray-500">{{ $log->recipient_email ?: ($log->recipient_phone ?: 'Alici bilgisi yok') }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Kaynak</div>
                        <div class="mt-2 text-sm font-medium text-gray-900">{{ $relatedLabel }}</div>
                        <div class="mt-1 text-sm text-gray-500">Attempt: {{ $log->attempt_count }}</div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="text-sm font-medium text-gray-900">Konu</div>
                    <div class="mt-2 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700">
                        {{ $log->safeDisplaySubject() ?: 'Konu yok' }}
                    </div>
                </div>

                <div class="mt-6">
                    <div class="text-sm font-medium text-gray-900">Mesaj Onizleme</div>
                    <div class="mt-2 whitespace-pre-wrap rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700">{{ $log->safeDisplayPreview() ?: 'Onizleme yok' }}</div>
                </div>

                @if($log->error_message)
                    <div class="mt-6">
                        <div class="text-sm font-medium text-gray-900">Hata Mesaji</div>
                        <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $log->safeDisplayError() }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg font-medium text-gray-900">Meta</h3>
                <div class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-gray-500">Created By</span>
                        <span class="font-medium text-gray-900">{{ $log->creator?->name ?: 'Sistem' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-gray-500">Created At</span>
                        <span class="font-medium text-gray-900">{{ $log->created_at?->format('d.m.Y H:i') ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-gray-500">Sent At</span>
                        <span class="font-medium text-gray-900">{{ $log->sent_at?->format('d.m.Y H:i') ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-gray-500">Response Code</span>
                        <span class="font-medium text-gray-900">{{ $log->response_code ?: '—' }}</span>
                    </div>
                </div>
            </div>
        </div>

        @if(!empty($safeProviderResponse))
            <div class="card">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium text-gray-900">Provider Response</h3>
                    <pre class="mt-4 overflow-x-auto whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-700">{{ json_encode($safeProviderResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif

        @if(!empty($safeMeta))
            <div class="card">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium text-gray-900">Guvenli Meta</h3>
                    <pre class="mt-4 overflow-x-auto whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-700">{{ json_encode($safeMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
