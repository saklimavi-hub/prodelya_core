@extends('customer-portal.layouts.app')

@push('styles')
    <style>
        .file-card,
        .empty-state {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
            padding: 18px;
        }

        .file-list {
            display: grid;
            gap: 14px;
        }

        .file-head,
        .file-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .file-name {
            margin: 0;
            font-size: 20px;
        }

        .badge {
            display: inline-block;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            font-family: Arial, Helvetica, sans-serif;
        }

        .muted {
            color: #6b7280;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }

        .button {
            border: 1px solid #d1d5db;
            background: #111827;
            color: #ffffff;
            padding: 11px 16px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border-radius: 12px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }
    </style>
@endpush

@section('content')
    <div class="file-card" style="margin-bottom:14px;">
        <p class="muted" style="margin:0;">Sipariş ve grafik süreçlerinde sizinle paylaşılan dosyaları burada görüntüleyebilirsiniz.</p>
    </div>

    @if($attachments->isEmpty())
        <div class="empty-state">
            <p class="muted" style="margin:0;">Henüz görüntülenebilir dosya yok.</p>
        </div>
    @else
        <div class="file-list">
            @foreach($attachments as $attachment)
                <article class="file-card">
                    <div class="file-head">
                        <div>
                            <h2 class="file-name">{{ $attachment['file_name'] }}</h2>
                            <p class="muted" style="margin:8px 0 0;">{{ $attachment['product_name'] }}</p>
                        </div>
                        <span class="badge">{{ $attachment['attachment_type_label'] }}</span>
                    </div>

                    <div class="file-meta" style="margin-top:16px;">
                        <p class="muted" style="margin:0;">Sipariş: {{ $attachment['order_number'] }}</p>
                        <p class="muted" style="margin:0;">İş Formu: {{ $attachment['work_form_number'] }}</p>
                        <p class="muted" style="margin:0;">Tarih: {{ $attachment['created_at'] }}</p>
                    </div>

                    <div style="margin-top:16px;">
                        <a class="button" href="{{ $attachment['show_url'] }}">Aç</a>
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top:18px;">
            {{ $attachments->links() }}
        </div>
    @endif
@endsection
