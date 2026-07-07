@php
    use App\Models\OrderItemPrintProduction;

    $isExternal = (bool) (($isExternalProduction ?? false) || ($isSubcontractedProduction ?? false));
    $remainingQuantity = (float) $production->remaining_quantity;
    $statusActions = [
        'assign_internal' => 'Üretime Başla',
        'assign_external' => 'Dış Üretime Ata',
        'sent_to_subcontractor' => 'Fasona Gönder',
        'returned_from_subcontractor' => 'Fasondan Geldi',
        'partial' => 'Kısmi Üretildi',
        'completed' => 'Tamamlandı',
        'issue' => 'Sorun Bildir',
        'cancel' => 'İptal Et',
    ];
    if ($qcUiEnabled) {
        $statusActions = array_merge($statusActions, [
            'qc_started' => 'Kalite Kontrol Başladı',
            'qc_passed' => 'Kalite Kontrol Uygun',
            'qc_failed' => 'Kalite Kontrol Sorunlu',
        ]);
    }
    $photosTabUrl = route('admin.productions.show', $production) . '?tab=fotograflar';
@endphp

<div class="prd-stack">
    <section class="prd-card">
        <h2 class="prd-section-title">Operasyon Yönetimi</h2>
        <p class="prd-section-subtitle">Durum, atama ve dış üretim bilgilerini tek operasyon panelinde yönetin.</p>

        <div class="prd-grid-2">
            <div class="prd-form-card">
                <h3 class="prd-side-title">Durum Güncelleme</h3>
                <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                    @csrf
                    @method('PATCH')

                    <div class="prd-form-grid">
                        <div>
                            <label class="form-label" for="status_action">Yeni Durum Seçimi</label>
                            <select id="status_action" name="action" class="form-control">
                                @foreach($statusActions as $action => $label)
                                    <option value="{{ $action }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="status_partial_quantity">Miktar</label>
                            <input id="status_partial_quantity" class="form-control" type="number" name="partial_quantity" min="0.0001" max="{{ $remainingQuantity > 0 ? number_format($remainingQuantity, 4, '.', '') : 0 }}" step="0.0001" placeholder="Kısmi üretim için">
                        </div>
                        <div>
                            <label class="form-label" for="status_company_id">Fason Firma</label>
                            <select id="status_company_id" name="production_company_id" class="form-control">
                                <option value="">Seçiniz</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" @selected((int) $production->production_company_id === (int) $company->id)>
                                        {{ $company->short_name ?: $company->legal_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="status_completed_quantity">Tamamlanan Adet</label>
                            <input id="status_completed_quantity" class="form-control" type="number" name="completed_quantity" min="0.0001" step="0.0001" placeholder="Tamamlama için">
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <label class="form-label" for="status_note">Açıklama</label>
                        <textarea id="status_note" name="note" class="form-control" rows="4" placeholder="Kısa açıklama girin"></textarea>
                    </div>

                    <div class="prd-form-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Durumu Güncelle</button>
                    </div>
                </form>
            </div>

            <div class="prd-form-card" id="atama-guncelle">
                <h3 class="prd-side-title">Atama / Sorumluluk</h3>
                <form method="POST" action="{{ route('admin.productions.update-assignment', $production) }}">
                    @csrf
                    @method('PATCH')

                    <div class="prd-form-grid">
                        <div>
                            <label class="form-label" for="assignment_operator">Operatör</label>
                            <select id="assignment_operator" name="assigned_to" class="form-control">
                                <option value="">Seçiniz</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected((int) $production->assigned_to === (int) $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="assignment_type">Üretim Türü</label>
                            <select id="assignment_type" name="production_type" class="form-control">
                                @foreach($typeLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($production->production_type === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="assignment_unit">Üretim Alanı</label>
                            <input id="assignment_unit" class="form-control" type="text" name="production_unit_name" value="{{ $production->production_unit_name }}" placeholder="Hat veya alan adı">
                        </div>
                        <div>
                            <label class="form-label" for="assignment_company">Fason Firma</label>
                            <select id="assignment_company" name="production_company_id" class="form-control">
                                <option value="">Seçiniz</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" @selected((int) $production->production_company_id === (int) $company->id)>
                                        {{ $company->short_name ?: $company->legal_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <label class="form-label" for="assignment_note">Not</label>
                        <textarea id="assignment_note" name="production_note" class="form-control" rows="4" placeholder="Operasyon notu">{{ $production->production_note }}</textarea>
                    </div>

                    @if($canViewFinancialData && $isExternal)
                        <div class="prd-form-grid" style="margin-top:12px;">
                            <div>
                                <label class="form-label" for="assignment_subcontractor_cost">Fason Maliyeti</label>
                                <input id="assignment_subcontractor_cost" class="form-control" type="number" name="subcontractor_cost" min="0" step="0.01" value="{{ $production->subcontractor_cost !== null ? number_format((float) $production->subcontractor_cost, 2, '.', '') : '' }}" placeholder="Tutar">
                            </div>
                            <div>
                                <label class="form-label" for="assignment_subcontractor_currency">Para Birimi</label>
                                <select id="assignment_subcontractor_currency" name="subcontractor_cost_currency" class="form-control">
                                    @foreach(['TRY', 'USD', 'EUR'] as $currency)
                                        <option value="{{ $currency }}" @selected(($production->subcontractor_cost_currency ?: 'TRY') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    <input type="hidden" name="cliche_required" value="{{ $production->cliche_required ? 1 : 0 }}">
                    @if($production->cliche_status)
                        <input type="hidden" name="cliche_status" value="{{ $production->cliche_status }}">
                    @endif

                    <div class="prd-form-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Atamayı Güncelle</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="prd-form-card" style="margin-top:14px;">
            <h3 class="prd-side-title">Diğer İşlemler</h3>
            <div class="prd-grid-3">
                <div class="prd-step-card is-muted" style="min-height: 152px;">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">1</span>
                        <div class="prd-step-title">Üretime Başla</div>
                        <div class="prd-step-copy">Üretimi mevcut akışa göre başlatır.</div>
                    </div>
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="{{ $isExternal ? 'assign_external' : 'assign_internal' }}">
                        @if($isExternal && $production->production_company_id)
                            <input type="hidden" name="production_company_id" value="{{ $production->production_company_id }}">
                        @endif
                        @if(!$isExternal)
                            <input type="hidden" name="production_unit_name" value="{{ $production->production_unit_name ?: 'İç üretim hattı' }}">
                        @endif
                        <button type="submit" class="btn btn-sm btn-success w-100">Üretime Başla</button>
                    </form>
                </div>

                <div class="prd-step-card is-muted" style="min-height: 152px;">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">2</span>
                        <div class="prd-step-title">Kısmi Üretildi</div>
                        <div class="prd-step-copy">Kısmi miktar işlemesi için hızlı alan.</div>
                    </div>
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="partial">
                        <input class="form-control form-control-sm" type="number" name="partial_quantity" min="0.0001" max="{{ $remainingQuantity > 0 ? number_format($remainingQuantity, 4, '.', '') : 0 }}" step="0.0001" placeholder="Adet" required>
                        <button type="submit" class="btn btn-sm btn-primary w-100" style="margin-top:8px;">Kısmi Üretildi</button>
                    </form>
                </div>

                <div class="prd-step-card is-muted" style="min-height: 152px;">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">3</span>
                        <div class="prd-step-title">Tamamlandı</div>
                        <div class="prd-step-copy">Planlanan adedi tamamlanmış olarak işler.</div>
                    </div>
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="completed">
                        <input type="hidden" name="completed_quantity" value="{{ $production->planned_quantity }}">
                        <button type="submit" class="btn btn-sm btn-success w-100">Tamamlandı</button>
                    </form>
                </div>

                <div class="prd-step-card is-muted" style="min-height: 152px;">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">4</span>
                        <div class="prd-step-title">Sorun Bildir</div>
                        <div class="prd-step-copy">Sorun notunu hızlıca kaydeder.</div>
                    </div>
                    <form method="POST" action="{{ route('admin.productions.update-status', $production) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="issue">
                        <input class="form-control form-control-sm" type="text" name="note" placeholder="Sorun notu">
                        <button type="submit" class="btn btn-sm btn-danger w-100" style="margin-top:8px;">Sorun Bildir</button>
                    </form>
                </div>

                <div class="prd-step-card is-muted" style="min-height: 152px;">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">5</span>
                        <div class="prd-step-title">Fotoğraf Yükle</div>
                        <div class="prd-step-copy">Üretim fotoğraf alanına geçiş sağlar.</div>
                    </div>
                    <a href="{{ $photosTabUrl }}" class="btn btn-sm btn-outline-primary w-100">Fotoğraf Yükle</a>
                </div>

                <div class="prd-step-card is-muted" style="min-height: 152px;" id="hizli-not">
                    <div class="prd-step-top">
                        <span class="prd-step-badge">6</span>
                        <div class="prd-step-title">Not Ekle</div>
                        <div class="prd-step-copy">Operasyon notunu atama alanından güncelleyebilirsiniz.</div>
                    </div>
                    <a href="#atama-guncelle" class="btn btn-sm btn-outline-primary w-100">Not Alanına Git</a>
                </div>
            </div>
        </div>
    </section>
</div>
