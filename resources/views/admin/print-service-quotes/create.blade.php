@extends('layouts.prodelya-admin')

@section('title', 'Yeni Baskı Teklifi')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Yeni Baskı Teklifi</h1>
        <p class="pd-muted mt-1">Müşteri ürünü için baskı teklifi oluşturun.</p>
    </div>
    <div>
        <a href="{{ route('admin.print-service-quotes.index') }}" class="pd-btn pd-btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Geri Dön
        </a>
    </div>
</div>

<form action="{{ route('admin.print-service-quotes.store') }}" method="POST">
    @csrf
    
    <!-- Temel Bilgiler -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Temel Bilgiler</h3>
            
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Müşteri *</label>
                    <select name="customer_id" class="pd-input" required>
                        <option value="">Müşteri Seçin</option>
                        @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->legal_name ?? $customer->short_name ?? $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Referans Kod</label>
                    <input type="text" name="reference_code" class="pd-input" placeholder="Müşteri referans kodu">
                </div>
            </div>
        </div>
    </div>

    <!-- Baskı Kalemleri -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="pd-section-title">Baskı Kalemleri</h3>
                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" onclick="addPrintItem()">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Kalem Ekle
                </button>
            </div>
            
            <div id="printItems" class="space-y-4">
                <!-- İlk kalem -->
                <div class="pd-card" data-item="1">
                    <div class="pd-card-body">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium">Kalem 1</h4>
                            <button type="button" class="pd-btn pd-btn-sm pd-btn-danger" onclick="removePrintItem(1)">Sil</button>
                        </div>
                        
                        <div class="pd-form-grid-2">
                            <div>
                                <label class="pd-label">Müşteri Ürün Açıklaması *</label>
                                <textarea name="items[1][customer_product_description]" class="pd-input" rows="2" required placeholder="Müşterinin gönderdiği ürün açıklaması"></textarea>
                            </div>
                            
                            <div>
                                <label class="pd-label">Referans Kod</label>
                                <input type="text" name="items[1][reference_code]" class="pd-input" placeholder="Kalem referans kodu">
                            </div>
                        </div>
                        
                        <div class="pd-form-grid-3">
                            <div>
                                <label class="pd-label">Adet *</label>
                                <input type="number" name="items[1][quantity]" class="pd-input" min="1" required placeholder="Adet">
                            </div>
                            
                            <div>
                                <label class="pd-label">Müşteri Ürün Durumu</label>
                                <select name="items[1][customer_product_status]" class="pd-input">
                                    <option value="received">Alındı</option>
                                    <option value="pending">Bekliyor</option>
                                    <option value="approved">Onaylandı</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="pd-label">Baskı Türü *</label>
                                <select name="items[1][print_type]" class="pd-input" required>
                                    <option value="">Seçin</option>
                                    <option value="offset">Ofset Baskı</option>
                                    <option value="digital">Digital Baskı</option>
                                    <option value="screen">Serigrafi</option>
                                    <option value="flexo">Flexo Baskı</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="pd-form-grid-3">
                            <div>
                                <label class="pd-label">Baskı Seçeneği *</label>
                                <select name="items[1][print_option]" class="pd-input" required>
                                    <option value="">Seçin</option>
                                    <option value="1_color">1 Renk</option>
                                    <option value="2_color">2 Renk</option>
                                    <option value="4_color">4 Renk</option>
                                    <option value="full_color">Tam Renkli</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="pd-label">Baskı Yeri</label>
                                <select name="items[1][print_location]" class="pd-input">
                                    <option value="front">Ön Yüz</option>
                                    <option value="back">Arka Yüz</option>
                                    <option value="both">Çift Yüz</option>
                                    <option value="custom">Özel</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="pd-label">Baskı Rengi</label>
                                <input type="text" name="items[1][print_color]" class="pd-input" placeholder="Renk bilgisi">
                            </div>
                        </div>
                        
                        <div class="pd-form-grid-2">
                            <div>
                                <label class="pd-label">Klişe / Kalıp</label>
                                <select name="items[1][plate]" class="pd-input">
                                    <option value="none">Yok</option>
                                    <option value="plate">Klişe</option>
                                    <option value="mold">Kalıp</option>
                                    <option value="cliche">Cliche</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="pd-label">Baskı Miktarı</label>
                                <input type="number" name="items[1][print_quantity]" class="pd-input" min="1" placeholder="Baskı adeti">
                            </div>
                        </div>
                        
                        <div class="pd-form-grid-2">
                            <div>
                                <label class="pd-label">Birim Fiyat *</label>
                                <input type="number" name="items[1][unit_price]" class="pd-input" step="0.01" min="0" required placeholder="0.00">
                            </div>
                            
                            <div>
                                <label class="pd-label">Toplam</label>
                                <input type="number" name="items[1][total_price]" class="pd-input" step="0.01" min="0" readonly placeholder="0.00">
                            </div>
                        </div>
                        
                        <div>
                            <label class="pd-label">Notlar</label>
                            <textarea name="items[1][notes]" class="pd-input" rows="2" placeholder="Baskı ile ilgili notlar"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notlar -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Notlar</h3>
            <textarea name="notes" class="pd-input" rows="3" placeholder="Teklif ile ilgili genel notlar"></textarea>
        </div>
    </div>

    <!-- Butonlar -->
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.print-service-quotes.index') }}" class="pd-btn pd-btn-light">İptal</a>
        <div class="flex items-center space-x-2">
            <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
            <button type="submit" name="save_and_send" value="1" class="pd-btn pd-btn-success">Kaydet ve Gönder</button>
        </div>
    </div>
</form>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Teklif Özeti</div>
        
        <div id="quoteSummary" class="space-y-3">
            <div class="pd-summary-row">
                <span>Kalem Sayısı:</span>
                <span class="font-medium" id="itemCount">1</span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Tutar:</span>
                <span class="font-medium" id="totalAmount">0.00 TL</span>
            </div>
            <div class="pd-summary-row">
                <span>Ortalama Birim Fiyat:</span>
                <span class="font-medium" id="avgUnitPrice">0.00 TL</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button type="submit" form="mainForm" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Teklifi Kaydet
            </button>
            <button type="submit" form="mainForm" name="save_and_send" value="1" class="pd-btn pd-btn-success pd-btn-sm pd-btn-block">
                Kaydet ve Müşteriye Gönder
            </button>
        </div>
        
        <div class="pd-note mt-4">
            <div class="font-medium mb-1">Önemli Not</div>
            <div class="text-sm text-gray-600">
                Bu modda müşteri ürünü gönderir, sadece baskı hizmeti sunulur. Ürün satışı yapılmaz.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
let itemCount = 1;

function addPrintItem() {
    itemCount++;
    const itemHtml = `
        <div class="pd-card" data-item="${itemCount}">
            <div class="pd-card-body">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-medium">Kalem ${itemCount}</h4>
                    <button type="button" class="pd-btn pd-btn-sm pd-btn-danger" onclick="removePrintItem(${itemCount})">Sil</button>
                </div>
                
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">Müşteri Ürün Açıklaması *</label>
                        <textarea name="items[${itemCount}][customer_product_description]" class="pd-input" rows="2" required placeholder="Müşterinin gönderdiği ürün açıklaması"></textarea>
                    </div>
                    
                    <div>
                        <label class="pd-label">Referans Kod</label>
                        <input type="text" name="items[${itemCount}][reference_code]" class="pd-input" placeholder="Kalem referans kodu">
                    </div>
                </div>
                
                <div class="pd-form-grid-3">
                    <div>
                        <label class="pd-label">Adet *</label>
                        <input type="number" name="items[${itemCount}][quantity]" class="pd-input" min="1" required placeholder="Adet">
                    </div>
                    
                    <div>
                        <label class="pd-label">Müşteri Ürün Durumu</label>
                        <select name="items[${itemCount}][customer_product_status]" class="pd-input">
                            <option value="received">Alındı</option>
                            <option value="pending">Bekliyor</option>
                            <option value="approved">Onaylandı</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="pd-label">Baskı Türü *</label>
                        <select name="items[${itemCount}][print_type]" class="pd-input" required>
                            <option value="">Seçin</option>
                            <option value="offset">Ofset Baskı</option>
                            <option value="digital">Digital Baskı</option>
                            <option value="screen">Serigrafi</option>
                            <option value="flexo">Flexo Baskı</option>
                        </select>
                    </div>
                </div>
                
                <div class="pd-form-grid-3">
                    <div>
                        <label class="pd-label">Baskı Seçeneği *</label>
                        <select name="items[${itemCount}][print_option]" class="pd-input" required>
                            <option value="">Seçin</option>
                            <option value="1_color">1 Renk</option>
                            <option value="2_color">2 Renk</option>
                            <option value="4_color">4 Renk</option>
                            <option value="full_color">Tam Renkli</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="pd-label">Baskı Yeri</label>
                        <select name="items[${itemCount}][print_location]" class="pd-input">
                            <option value="front">Ön Yüz</option>
                            <option value="back">Arka Yüz</option>
                            <option value="both">Çift Yüz</option>
                            <option value="custom">Özel</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="pd-label">Baskı Rengi</label>
                        <input type="text" name="items[${itemCount}][print_color]" class="pd-input" placeholder="Renk bilgisi">
                    </div>
                </div>
                
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">Klişe / Kalıp</label>
                        <select name="items[${itemCount}][plate]" class="pd-input">
                            <option value="none">Yok</option>
                            <option value="plate">Klişe</option>
                            <option value="mold">Kalıp</option>
                            <option value="cliche">Cliche</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="pd-label">Baskı Miktarı</label>
                        <input type="number" name="items[${itemCount}][print_quantity]" class="pd-input" min="1" placeholder="Baskı adeti">
                    </div>
                </div>
                
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">Birim Fiyat *</label>
                        <input type="number" name="items[${itemCount}][unit_price]" class="pd-input" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    
                    <div>
                        <label class="pd-label">Toplam</label>
                        <input type="number" name="items[${itemCount}][total_price]" class="pd-input" step="0.01" min="0" readonly placeholder="0.00">
                    </div>
                </div>
                
                <div>
                    <label class="pd-label">Notlar</label>
                    <textarea name="items[${itemCount}][notes]" class="pd-input" rows="2" placeholder="Baskı ile ilgili notlar"></textarea>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('printItems').insertAdjacentHTML('beforeend', itemHtml);
    updateSummary();
}

function removePrintItem(itemId) {
    const item = document.querySelector(`[data-item="${itemId}"]`);
    if (item) {
        item.remove();
        updateSummary();
    }
}

function updateSummary() {
    const items = document.querySelectorAll('[data-item]');
    const itemCount = items.length;
    let totalAmount = 0;
    
    items.forEach(item => {
        const totalPrice = item.querySelector('input[name$="[total_price]"]');
        if (totalPrice && totalPrice.value) {
            totalAmount += parseFloat(totalPrice.value);
        }
    });
    
    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('totalAmount').textContent = totalAmount.toFixed(2) + ' TL';
    document.getElementById('avgUnitPrice').textContent = itemCount > 0 ? (totalAmount / itemCount).toFixed(2) + ' TL' : '0.00 TL';
}

// Event listener for price calculations
document.addEventListener('input', function(e) {
    if (e.target.name && e.target.name.includes('[unit_price]')) {
        const item = e.target.closest('[data-item]');
        const quantity = item.querySelector('input[name$="[quantity]"]');
        const unitPrice = e.target;
        const totalPrice = item.querySelector('input[name$="[total_price]"]');
        
        if (quantity && unitPrice && totalPrice) {
            const qty = parseFloat(quantity.value) || 0;
            const price = parseFloat(unitPrice.value) || 0;
            totalPrice.value = (qty * price).toFixed(2);
            updateSummary();
        }
    }
    
    if (e.target.name && e.target.name.includes('[quantity]')) {
        const item = e.target.closest('[data-item]');
        const quantity = e.target;
        const unitPrice = item.querySelector('input[name$="[unit_price]"]');
        const totalPrice = item.querySelector('input[name$="[total_price]"]');
        
        if (quantity && unitPrice && totalPrice) {
            const qty = parseFloat(quantity.value) || 0;
            const price = parseFloat(unitPrice.value) || 0;
            totalPrice.value = (qty * price).toFixed(2);
            updateSummary();
        }
    }
});

// Form ID'sini ekle
document.querySelector('form').id = 'mainForm';
</script>
@endpush
