@extends('layouts.prodelya-admin')

@section('title', 'Finans Detayı')
@section('page_title', 'Finans Detayı')
@section('page_subtitle', 'Sipariş snapshot toplamlarını, KDV kırılımını ve tahsilat hareketlerini güvenli yetki sınırlarıyla inceleyin.')

@section('content')
@php
    $currency = $summary['currency'] ?? ($order->currency ?: 'TL');
    $money = static fn ($amount) => number_format((float) $amount, 2, ',', '.') . ' ' . $currency;
    $paymentStatusTones = [
        'odeme_bekliyor' => 'amber',
        'kismi_odeme' => 'blue',
        'odendi' => 'green',
        'fazla_odeme' => 'purple',
        'vade_bekliyor' => 'gray',
        'tahsilat_uyarisi' => 'red',
        'iptal' => 'gray',
    ];
    $vatScopeLabels = [
        'product' => 'Ürün',
        'print' => 'Baskı',
        'general' => 'Genel',
    ];
@endphp

<style>
    .fnd-page { display: grid; gap: 14px; }
    .fnd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .fnd-body { padding: 16px; }
    .fnd-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 14px; }
    .fnd-section-title { margin: 0 0 10px; font-size: 16px; font-weight: 700; color: #111827; }
    .fnd-box { border: 1px solid #e5e7eb; border-radius: 6px; background: #fbfcfe; padding: 12px; }
    .fnd-mini-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 12px; }
    .fnd-label { color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 4px; }
    .fnd-value { color: #111827; font-size: 13px; font-weight: 600; line-height: 1.45; word-break: break-word; }
    .fnd-money { color: #111827; font-size: 15px; font-weight: 700; white-space: nowrap; }
    .fnd-note { border: 1px solid #dbeafe; border-radius: 6px; background: #f8fbff; padding: 10px 12px; color: #6b7280; font-size: 12px; line-height: 1.45; }
    .fnd-table-wrap { overflow: auto; }
    .fnd-table { width: 100%; border-collapse: collapse; }
    .fnd-table th, .fnd-table td { padding: 11px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    .fnd-table th { background: #fbfcfe; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
    .fnd-table tr:last-child td { border-bottom: 0; }
    .fnd-stack { display: grid; gap: 10px; }
    .fnd-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .fnd-placeholder { border: 1px dashed #d1d5db; border-radius: 6px; background: #fafafa; padding: 12px; color: #6b7280; font-size: 12px; line-height: 1.5; }
    .fnd-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 12px; font-weight: 600; }
    .fnd-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .fnd-row-cancelled td { opacity: .75; background: #fafafa; }
    .fnd-strike { text-decoration: line-through; }
    .fnd-subnote { margin-top: 4px; color: #6b7280; font-size: 11px; }
    @media (max-width: 980px) { .fnd-grid, .fnd-mini-grid { grid-template-columns: 1fr; } }
</style>

<div class="fnd-page">
    @if(session('success'))
        <div class="pd-alert">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="pd-alert-warning">{{ $errors->first() }}</div>
    @endif

    <div class="fnd-actions">
        <a href="{{ route('admin.finance.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
        <a href="{{ route('admin.orders.show', $order) }}" class="pd-btn pd-btn-primary">Sipariş Detayı</a>
        @if($workForm)
            <a href="{{ route('admin.work-forms.show', $workForm) }}" class="pd-btn pd-btn-light">İş Formu Aç</a>
            <a href="{{ route('admin.work-forms.pdf', $workForm) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">İş Formu PDF</a>
            <a href="{{ route('public.work-forms.track', $workForm->public_tracking_token) }}" class="pd-btn pd-btn-light" target="_blank" rel="noopener">Müşteri Takip Linki</a>
        @endif
        @if($delivery)
            <a href="{{ route('admin.deliveries.show', $delivery) }}" class="pd-btn pd-btn-light">Teslimat Ekranı</a>
        @endif
    </div>

    <div class="fnd-grid">
        <div class="fnd-stack">
            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">A) Sipariş Özeti</h3>
                    <div class="fnd-mini-grid">
                        <div class="fnd-box"><div class="fnd-label">Sipariş No</div><div class="fnd-value">{{ $summary['order_number'] ?? $order->document_number }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Kaynak Teklif No</div><div class="fnd-value">{{ $summary['source_quote_number'] ?? ($order->source_quote_number ?: '-') }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Müşteri</div><div class="fnd-value">{{ $summary['customer_name'] ?? ($order->customer?->legal_name ?: '-') }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Sipariş Sahibi</div><div class="fnd-value">{{ $order->creator?->name ?: '-' }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Belge Türü</div><div class="fnd-value">{{ $summary['invoice_status_label'] ?? 'Fiş' }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Sipariş Tarihi</div><div class="fnd-value">{{ optional($order->quote_date)->format('d.m.Y') ?: optional($order->created_at)->format('d.m.Y') ?: '-' }}</div></div>
                    </div>
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">B) Sipariş Snapshot Toplamları</h3>
                    <div class="fnd-mini-grid">
                        <div class="fnd-box"><div class="fnd-label">Ürün Toplamı</div><div class="fnd-money">{{ $money($summary['product_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Baskı Toplamı</div><div class="fnd-money">{{ $money($summary['print_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Ara Toplam</div><div class="fnd-money">{{ $money($summary['subtotal'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">KDV</div><div class="fnd-money">{{ $money($summary['vat_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Genel Toplam</div><div class="fnd-money">{{ $money($summary['grand_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Para Birimi</div><div class="fnd-value">{{ $currency }}</div></div>
                    </div>
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">C) KDV Kırılımı</h3>
                    @if(($summary['invoice_status'] ?? 'fis') !== 'fatura')
                        <div class="fnd-note">
                            Fiş seçildiği için KDV hesaplanmaz. Bu siparişte genel toplam, ara toplam ile aynıdır.
                        </div>
                    @else
                        <div class="fnd-table-wrap">
                            <table class="fnd-table">
                                <thead>
                                    <tr>
                                        <th>Kapsam</th>
                                        <th>Oran</th>
                                        <th>Tutar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(($summary['vat_breakdown'] ?? []) as $slice)
                                        <tr>
                                            <td>{{ $vatScopeLabels[$slice['scope'] ?? 'general'] ?? ucfirst((string) ($slice['scope'] ?? 'Genel')) }}</td>
                                            <td>%{{ rtrim(rtrim(number_format((float) ($slice['rate'] ?? 0), 2, ',', '.'), '0'), ',') }}</td>
                                            <td>{{ $money($slice['total'] ?? 0) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="fnd-note">KDV kırılımı bulunamadı.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">D) Payment Summary</h3>
                    <div class="fnd-mini-grid">
                        <div class="fnd-box"><div class="fnd-label">Tahsil Edilen</div><div class="fnd-money">{{ $money($summary['paid_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">İade Toplamı</div><div class="fnd-money">{{ $money($summary['refunded_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Düzeltme Toplamı</div><div class="fnd-money">{{ $money($summary['adjustment_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Net Tahsilat</div><div class="fnd-money">{{ $money($summary['net_paid_total'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Kalan</div><div class="fnd-money">{{ $money($summary['balance_due'] ?? 0) }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Payment Count</div><div class="fnd-value">{{ $summary['payment_count'] ?? 0 }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Last Payment Date</div><div class="fnd-value">{{ filled($summary['last_payment_at'] ?? null) ? \Illuminate\Support\Carbon::parse($summary['last_payment_at'])->format('d.m.Y H:i') : '-' }}</div></div>
                        <div class="fnd-box"><div class="fnd-label">Next Due Date</div><div class="fnd-value">{{ filled($summary['next_due_date'] ?? null) ? \Illuminate\Support\Carbon::parse($summary['next_due_date'])->format('d.m.Y H:i') : '-' }}</div></div>
                        <div class="fnd-box" style="grid-column: 1 / -1;"><div class="fnd-label">Finans Durumu</div><div class="fnd-value"><span class="pd-badge pd-badge-{{ $paymentStatusTones[$summary['payment_status'] ?? 'odeme_bekliyor'] ?? 'gray' }}">{{ $summary['payment_status_label'] ?? '-' }}</span></div></div>
                    </div>
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">E) Tahsilat Hareketleri</h3>
                    <div class="fnd-table-wrap">
                        <table class="fnd-table">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tür</th>
                                    <th>Yöntem</th>
                                    <th>Tutar</th>
                                    <th>Para Birimi</th>
                                    <th>Referans</th>
                                    <th>Açıklama</th>
                                    <th>Durum</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($payments as $payment)
                                    <tr class="{{ $payment->isCancelled() ? 'fnd-row-cancelled' : '' }}">
                                        <td>{{ optional($payment->paid_at ?? $payment->created_at)->format('d.m.Y H:i') ?: '-' }}</td>
                                        <td>{{ $payment->safePaymentTypeLabel() }}</td>
                                        <td>{{ $payment->safePaymentMethodLabel() ?: '-' }}</td>
                                        <td><span class="{{ $payment->isCancelled() ? 'fnd-strike' : '' }}">{{ number_format((float) $payment->amount, 2, ',', '.') }}</span></td>
                                        <td>{{ $payment->currency ?: $currency }}</td>
                                        <td>{{ $payment->payment_reference ?: '-' }}</td>
                                        <td>
                                            {{ $payment->payment_note ?: '-' }}
                                            @if($payment->isCancelled())
                                                <div class="fnd-subnote">Summary hesabına dahil edilmez.</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($payment->isCancelled())
                                                <span class="pd-badge pd-badge-gray">İptal / Hesap dışı</span>
                                            @elseif($payment->paid_at)
                                                <span class="pd-badge pd-badge-green">İşlendi</span>
                                            @elseif($payment->due_date)
                                                <span class="pd-badge pd-badge-amber">Vade Bekliyor</span>
                                            @else
                                                <span class="pd-badge pd-badge-gray">Bekliyor</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if(!$payment->isCancelled() && $canManagePayments)
                                                <form method="POST" action="{{ route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="pd-btn pd-btn-sm pd-btn-light">İptal Et</button>
                                                </form>
                                            @else
                                                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" disabled>{{ $payment->isCancelled() ? 'İptal Edildi' : 'Yetki Yok' }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="fnd-note">Henüz tahsilat hareketi bulunmuyor.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="fnd-stack">
            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">F) Teslimat Finans Uyarısı</h3>
                    <div class="fnd-box">
                        <div class="fnd-label">Teslimat ekranına giden güvenli label</div>
                        <div class="fnd-value">{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</div>
                    </div>
                    <div class="fnd-note" style="margin-top:12px;">
                        Bu alan teslimat modülüne yalnız fiyatsız etiket olarak gider. Bakiye tutarı, KDV ve tahsilat detayları teslimat tarafında görünmez.
                    </div>
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">G) Güvenlik Notu</h3>
                    <div class="fnd-note">
                        İş Formu ve public tracking finansal tutar göstermez. Teslimat kullanıcısı tutar değil yalnız güvenli uyarı etiketi görür. Public takipte ödeme durumu, bakiye, KDV ve toplam paylaşılmaz.
                    </div>
                </div>
            </section>

            <section class="fnd-card">
                <div class="fnd-body">
                    <h3 class="fnd-section-title">Tahsilat Ekle</h3>
                    <form method="POST" action="{{ route('admin.finance.payments.store', $order) }}" class="fnd-stack">
                        @csrf
                        <div class="fnd-form-grid">
                            <div class="fnd-field">
                                <label>Tahsilat Türü</label>
                                <select name="payment_type" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                                    @foreach($paymentTypeLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_type', 'tahsilat') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="fnd-field">
                                <label>Tutar</label>
                                <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" placeholder="0,00" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="fnd-field">
                                <label>Para Birimi</label>
                                <input type="text" name="currency" value="{{ old('currency', $currency) }}" readonly>
                            </div>
                            <div class="fnd-field">
                                <label>Ödeme Yöntemi</label>
                                <select name="payment_method" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                                    <option value="">Seçiniz</option>
                                    @foreach($paymentMethodLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_method') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="fnd-field">
                                <label>Tahsilat Tarihi</label>
                                <input type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="fnd-field">
                                <label>Vade Tarihi</label>
                                <input type="datetime-local" name="due_date" value="{{ old('due_date') }}" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="fnd-field">
                                <label>Referans No</label>
                                <input type="text" name="payment_reference" value="{{ old('payment_reference') }}" placeholder="EFT / POS / Evrak no" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>
                            </div>
                            <div class="fnd-field" style="grid-column: 1 / -1;">
                                <label>Açıklama</label>
                                <textarea name="payment_note" rows="3" placeholder="Tahsilat notu" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>{{ old('payment_note') }}</textarea>
                            </div>
                        </div>
                        <div class="fnd-note">
                            V1’de tahsilat para birimi sipariş para birimiyle aynı olmalıdır. Sipariş toplamları ödeme işlemleriyle yeniden hesaplanmaz.
                        </div>
                        <div class="fnd-actions">
                            <button type="submit" class="pd-btn pd-btn-primary" @disabled(!$canManagePayments && !$canMarkPaymentsReceived)>Tahsilat Kaydet</button>
                        </div>
                    </form>

                    <div class="fnd-actions" style="margin-top:12px;">
                        <form method="POST" action="{{ route('admin.finance.mark-paid', $order) }}">
                            @csrf
                            <input type="hidden" name="payment_method" value="diger">
                            <button
                                type="submit"
                                class="pd-btn pd-btn-success"
                                @disabled(!$canManagePayments && !$canMarkPaymentsReceived || (($summary['balance_due'] ?? 0) <= 0))
                            >
                                Ödendi İşaretle
                            </button>
                        </form>
                    </div>
                    @if(($summary['balance_due'] ?? 0) <= 0)
                        <div class="fnd-subnote">Sipariş zaten ödenmiş veya fazla ödemeli görünüyor; yeni “ödendi” kaydı oluşturulmaz.</div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

@section('summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Tahsilat Özeti</div>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Sipariş No</span><strong>{{ $summary['order_number'] ?? $order->document_number }}</strong></div>
            <div class="pd-status-row"><span>Belge Türü</span><strong>{{ $summary['invoice_status_label'] ?? 'Fiş' }}</strong></div>
            <div class="pd-status-row"><span>Genel Toplam</span><strong>{{ $money($summary['grand_total'] ?? 0) }}</strong></div>
            <div class="pd-status-row"><span>Tahsil Edilen</span><strong>{{ $money($summary['net_paid_total'] ?? 0) }}</strong></div>
            <div class="pd-status-row"><span>Kalan</span><strong>{{ $money($summary['balance_due'] ?? 0) }}</strong></div>
            <div class="pd-status-row"><span>Finans Durumu</span><strong>{{ $summary['payment_status_label'] ?? '-' }}</strong></div>
            <div class="pd-status-row"><span>Teslimat Uyarısı</span><strong>{{ $summary['delivery_financial_warning_label'] ?? 'Finans uyarısı yok' }}</strong></div>
        </div>
        <div class="pd-side-note">
            Finance summary order snapshot toplamlarını korur. Tahsilat akışı order payment kayıtlarından türetilir; belge türü ve ödeme durumu ayrı tutulur.
        </div>
    </div>
</div>
@endsection
