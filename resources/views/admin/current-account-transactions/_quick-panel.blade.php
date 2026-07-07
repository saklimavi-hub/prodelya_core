@php
    $quickPanelId = $quickPanelId ?? 'hizli-islem-paneli';
    $quickPanelHeading = $quickPanelHeading ?? 'Hızlı Tahsilat / Ödeme';
    $quickPanelDescription = $quickPanelDescription ?? 'Cari hareket kaydedildiğinde ekstre ve bakiye anında güncellenir.';
    $quickPanelFormAction = $quickPanelFormAction ?? route('admin.current-accounts.transactions.store', $account);
    $quickPanelReturnUrl = $quickPanelReturnUrl ?? request()->fullUrl();
    $quickPanelSubmitAction = $quickPanelSubmitAction ?? 'save';
    $quickPanelIsOpen = $quickPanelIsOpen ?? false;
    $quickPanelTransactionTypeOptions = $quickPanelTransactionTypeOptions ?? $manualTransactionTypeOptions ?? [];
    $quickPanelDefaultType = $quickPanelDefaultType ?? ($manualFormDefaults['transaction_type'] ?? array_key_first($quickPanelTransactionTypeOptions));
    $quickPanelTypeLabels = [];

    foreach ($quickPanelTransactionTypeOptions as $value => $label) {
        $quickPanelTypeLabels[$value] = match ($value) {
            \App\Models\CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT => 'Tahsilat',
            \App\Models\CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            \App\Models\CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            \App\Models\CurrentAccountTransaction::TYPE_CARRIER_PAYMENT => 'Ödeme',
            default => $label,
        };
    }
@endphp

<div
    id="{{ $quickPanelId }}"
    class="pd-quick-panel"
    data-quick-panel
    data-open="{{ $quickPanelIsOpen ? '1' : '0' }}"
    style="{{ $quickPanelIsOpen ? '' : 'display:none;' }}"
>
    <div class="pd-quick-panel__backdrop" data-quick-panel-close></div>
    <div class="pd-quick-panel__dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $quickPanelId }}-title">
        <div class="pd-quick-panel__header">
            <div>
                <h3 id="{{ $quickPanelId }}-title" class="pd-card-title">{{ $quickPanelHeading }}</h3>
                <p class="pd-card-subtitle">{{ $quickPanelDescription }}</p>
            </div>
            <button type="button" class="pd-btn pd-btn-light pd-btn-sm" data-quick-panel-close>Vazgeç</button>
        </div>

        <div class="pd-quick-panel__summary">
            <div>
                <div class="pd-quick-panel__label">Cari adı</div>
                <div class="pd-quick-panel__value">{{ $account->safeDisplayName() }}</div>
            </div>
            <div>
                <div class="pd-quick-panel__label">Cari rolleri</div>
                <div class="pd-quick-panel__badges">
                    @forelse($account->roles as $role)
                        <span class="pd-badge pd-badge-gray">{{ $role->safeRoleLabel() }}</span>
                    @empty
                        <span class="pd-badge pd-badge-gray">Rol yok</span>
                    @endforelse
                </div>
            </div>
        </div>

        @if($errors->any())
            <div class="pd-note" style="margin-bottom:14px; border-color:#fecaca; background:#fef2f2; color:#991b1b;">
                Formu kaydetmeden önce işaretli alanları kontrol edin.
            </div>
        @endif

        <form method="POST" action="{{ $quickPanelFormAction }}" data-quick-panel-form>
            @csrf
            <input type="hidden" name="redirect_to" value="{{ $quickPanelReturnUrl }}">
            <input type="hidden" name="submit_action" value="{{ $quickPanelSubmitAction }}" data-submit-action>

            <div class="pd-form-grid-3">
                <div>
                    <label class="text-sm font-medium">Cari adı</label>
                    <input type="text" value="{{ $account->safeDisplayName() }}" readonly>
                </div>
                <div>
                    <label class="text-sm font-medium">İşlem Türü</label>
                    <select name="transaction_type" data-transaction-type-select>
                        @foreach($quickPanelTypeLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($manualFormDefaults['transaction_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('transaction_type')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div data-manual-direction-wrap>
                    <label class="text-sm font-medium">İşlem Yönü</label>
                    <select name="direction">
                        @foreach(\App\Models\CurrentAccountTransaction::directionLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(($manualFormDefaults['direction'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="text-xs text-gray-600" style="margin-top:6px;">Yalnız serbest hareketlerde kullanılır.</div>
                    @error('direction')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label class="text-sm font-medium">Tutar</label>
                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}">
                    @error('amount')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium">Para Birimi</label>
                    <select name="currency">
                        @foreach(['TL', 'TRY', 'USD', 'EUR'] as $currency)
                            <option value="{{ $currency }}" @selected(($manualFormDefaults['currency'] ?? '') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                    @error('currency')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium">Tarih</label>
                    <input type="date" name="transaction_date" value="{{ $manualFormDefaults['transaction_date'] ?? now()->toDateString() }}">
                    @error('transaction_date')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>

                <div data-due-date-wrap>
                    <label class="text-sm font-medium">Vade Tarihi</label>
                    <input type="date" name="due_date" value="{{ $manualFormDefaults['due_date'] ?? '' }}">
                    @error('due_date')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium">Durum</label>
                    <select name="status" data-status-select>
                        @foreach($manualStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($manualFormDefaults['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium">Ödeme Yöntemi</label>
                    <select name="payment_method">
                        <option value="">Seçilmedi</option>
                        @foreach($paymentMethodOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($manualFormDefaults['payment_method'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>

                <div>
                    <label class="text-sm font-medium">Belge No</label>
                    <input type="text" name="document_number" value="{{ $manualFormDefaults['document_number'] ?? '' }}" placeholder="Örn. THS-2026-001">
                    @error('document_number')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="text-sm font-medium">Sipariş Bağlantısı</label>
                    <select name="order_id">
                        <option value="">Sipariş seçmeden devam et</option>
                        @foreach($orderOptions as $order)
                            <option value="{{ $order->id }}" @selected((string) ($manualFormDefaults['order_id'] ?? '') === (string) $order->id)>
                                {{ $order->document_number }} / {{ $order->status }}
                            </option>
                        @endforeach
                    </select>
                    @error('order_id')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div class="pd-quick-panel__hint-box">
                    <strong>Hızlı kullanım</strong>
                    <div class="text-sm text-gray-600" style="margin-top:6px;">
                        Müşteri cari için tahsilat, tedarikçi ve fason cari için ödeme varsayılan gelir.
                    </div>
                </div>

                <div style="grid-column:1 / -1;">
                    <label class="text-sm font-medium">Açıklama</label>
                    <textarea name="description" rows="3">{{ $manualFormDefaults['description'] ?? '' }}</textarea>
                    @error('description')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div style="grid-column:1 / -1;">
                    <label class="text-sm font-medium">İç Not</label>
                    <textarea name="internal_note" rows="2">{{ $manualFormDefaults['internal_note'] ?? '' }}</textarea>
                    <div class="text-xs text-gray-600" style="margin-top:6px;">İç not ekstrede ham teknik bilgi olarak gösterilmez.</div>
                    @error('internal_note')<div class="text-xs text-red-700" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="pd-quick-panel__footer">
                <button type="button" class="pd-btn pd-btn-light" data-quick-panel-close>Vazgeç</button>
                <button type="submit" class="pd-btn pd-btn-primary" data-submit-mode="save">Kaydet</button>
                <button type="submit" class="pd-btn pd-btn-light" data-submit-mode="save_and_new">Kaydet ve Yeni Hareket</button>
            </div>
        </form>
    </div>
</div>
