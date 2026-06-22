@extends('layouts.prodelya-admin')

@section('title', 'Yeni Sipariş')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="pd-section-title text-2xl">Yeni Sipariş</h1>
        <p class="pd-muted mt-1">Promosyon veya baskı siparişi oluşturun.</p>
    </div>
    <div>
        <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Geri Dön
        </a>
    </div>
</div>

<form action="{{ route('admin.orders.store') }}" method="POST">
    @csrf
    
    <!-- Temel Bilgiler -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <h3 class="pd-section-title mb-4">Temel Bilgiler</h3>
            
            <div class="pd-form-grid-2">
                <div>
                    <label class="pd-label">Sipariş Türü *</label>
                    <select name="order_family" class="pd-input" required onchange="updateOrderMode(this.value)">
                        <option value="">Seçin</option>
                        <option value="promotion">Promosyon</option>
                        <option value="print">Baskı</option>
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Müşteri *</label>
                    <select name="customer_id" class="pd-input" required>
                        <option value="">Müşteri Seçin</option>
                        @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->legal_name ?? $customer->short_name ?? $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            
            <div id="orderModeSection" class="pd-form-grid-2" style="display: none;">
                <div>
                    <label class="pd-label">Sipariş Modu *</label>
                    <select name="order_mode" class="pd-input" id="orderModeSelect">
                        <option value="">Seçin</option>
                        <option value="product_sale_print">Ürün Satışı + Baskı</option>
                        <option value="print_service_only">Sadece Baskı</option>
                    </select>
                </div>
                
                <div>
                    <label class="pd-label">Para Birimi</label>
                    <select name="currency" class="pd-input">
                        <option value="TL" selected>TL</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Sipariş Kalemleri -->
    <div class="pd-card mb-6">
        <div class="pd-card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="pd-section-title">Sipariş Kalemleri</h3>
                <button type="button" class="pd-btn pd-btn-sm pd-btn-light" onclick="addOrderItem()">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Kalem Ekle
                </button>
            </div>
            
            <div id="orderItems" class="space-y-4">
                <!-- İlk kalem -->
                <div class="pd-card" data-item="1">
                    <div class="pd-card-body">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium">Kalem 1</h4>
                            <button type="button" class="pd-btn pd-btn-sm pd-btn-danger" onclick="removeOrderItem(1)">Sil</button>
                        </div>
                        
                        <div class="pd-form-grid-2">
                            <div>
                                <label class="pd-label">Ürün Adı *</label>
                                <input type="text" name="items[1][product_name]" class="pd-input" required placeholder="Ürün adı">
                            </div>
                            
                            <div>
                                <label class="pd-label">Ürün Kodu</label>
                                <input type="text" name="items[1][product_code]" class="pd-input" placeholder="Ürün kodu">
                            </div>
                        </div>
                        
                        <div class="pd-form-grid-3">
                            <div>
                                <label class="pd-label">Adet *</label>
                                <input type="number" name="items[1][quantity]" class="pd-input" min="1" required placeholder="Adet">
                            </div>
                            
                            <div>
                                <label class="pd-label">Birim</label>
                                <select name="items[1][unit]" class="pd-input">
                                    <option value="Adet" selected>Adet</option>
                                    <option value="Kutu">Kutu</option>
                                    <option value="Paket">Paket</option>
                                    <option value="Metre">Metre</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="pd-label">Birim Fiyat *</label>
                                <input type="number" name="items[1][unit_price]" class="pd-input" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                        
                        <div>
                            <label class="pd-label">Açıklama</label>
                            <textarea name="items[1][description]" class="pd-input" rows="2" placeholder="Ürün açıklaması"></textarea>
                        </div>
                        
                        <div class="flex items-center space-x-4">
                            <label class="pd-label flex items-center">
                                <input type="checkbox" name="items[1][has_print]" class="pd-checkbox mr-2" onchange="togglePrintOptions(1)">
                                Baskı Hizmeti
                            </label>
                        </div>
                        
                        <div id="printOptions1" class="mt-4 space-y-4" style="display: none;">
                            <div class="pd-form-grid-2">
                                <div>
                                    <label class="pd-label">Baskı Türü</label>
                                    <select name="items[1][print_type]" class="pd-input">
                                        <option value="">Seçin</option>
                                        <option value="offset">Ofset Baskı</option>
                                        <option value="digital">Digital Baskı</option>
                                        <option value="screen">Serigrafi</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="pd-label">Baskı Miktarı</label>
                                    <input type="number" name="items[1][print_quantity]" class="pd-input" min="1" placeholder="Baskı adeti">
                                </div>
                            </div>
                            
                            <div class="pd-form-grid-2">
                                <div>
                                    <label class="pd-label">Baskı Birim Fiyat</label>
                                    <input type="number" name="items[1][print_unit_price]" class="pd-input" step="0.01" min="0" placeholder="0.00">
                                </div>
                                
                                <div>
                                    <label class="pd-label">Baskı Notları</label>
                                    <input type="text" name="items[1][print_notes]" class="pd-input" placeholder="Baskı notları">
                                </div>
                            </div>
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
            <textarea name="notes" class="pd-input" rows="3" placeholder="Sipariş ile ilgili genel notlar"></textarea>
        </div>
    </div>

    <!-- Butonlar -->
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.orders.index') }}" class="pd-btn pd-btn-light">İptal</a>
        <div class="flex items-center space-x-2">
            <button type="submit" class="pd-btn pd-btn-primary">Kaydet</button>
            <button type="submit" name="save_and_confirm" value="1" class="pd-btn pd-btn-success">Kaydet ve Onayla</button>
        </div>
    </div>
</form>
@endsection

@section('summary')
<div class="pd-quote-summary">
    <div class="pd-card-body">
        <div class="pd-summary-title">Sipariş Özeti</div>
        
        <div id="orderSummary" class="space-y-3">
            <div class="pd-summary-row">
                <span>Kalem Sayısı:</span>
                <span class="font-medium" id="itemCount">1</span>
            </div>
            <div class="pd-summary-row">
                <span>Toplam Tutar:</span>
                <span class="font-medium" id="totalAmount">0.00 TL</span>
            </div>
            <div class="pd-summary-row">
                <span>Baskı Tutarı:</span>
                <span class="font-medium" id="printTotalAmount">0.00 TL</span>
            </div>
            <div class="pd-summary-row">
                <span>Genel Toplam:</span>
                <span class="font-medium" id="grandTotalAmount">0.00 TL</span>
            </div>
        </div>
        
        <div class="mt-6 space-y-2">
            <button type="submit" form="mainForm" class="pd-btn pd-btn-primary pd-btn-sm pd-btn-block">
                Siparişi Kaydet
            </button>
            <button type="submit" form="mainForm" name="save_and_confirm" value="1" class="pd-btn pd-btn-success pd-btn-sm pd-btn-block">
                Kaydet ve Müşteriye Onayla
            </button>
        </div>
        
        <div class="pd-note mt-4">
            <div class="font-medium mb-1">Sipariş Türleri</div>
            <div class="text-sm text-gray-600">
                <p>• <strong>Promosyon:</strong> Ürün satışı + baskı hizmeti</p>
                <p>• <strong>Baskı:</strong> Sadece baskı hizmeti (müşteri ürünü)</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
let itemCount = 1;

function updateOrderMode(orderFamily) {
    const orderModeSection = document.getElementById('orderModeSection');
    const orderModeSelect = document.getElementById('orderModeSelect');
    
    if (orderFamily === 'promotion') {
        orderModeSection.style.display = 'block';
        orderModeSelect.innerHTML = `
            <option value="">Seçin</option>
            <option value="product_sale_print">Ürün Satışı + Baskı</option>
            <option value="print_service_only">Sadece Baskı</option>
        `;
    } else if (orderFamily === 'print') {
        orderModeSection.style.display = 'block';
        orderModeSelect.innerHTML = `
            <option value="print_service_only" selected>Sadece Baskı</option>
        `;
    } else {
        orderModeSection.style.display = 'none';
    }
}

function addOrderItem() {
    itemCount++;
    const itemHtml = `
        <div class="pd-card" data-item="${itemCount}">
            <div class="pd-card-body">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-medium">Kalem ${itemCount}</h4>
                    <button type="button" class="pd-btn pd-btn-sm pd-btn-danger" onclick="removeOrderItem(${itemCount})">Sil</button>
                </div>
                
                <div class="pd-form-grid-2">
                    <div>
                        <label class="pd-label">Ürün Adı *</label>
                        <input type="text" name="items[${itemCount}][product_name]" class="pd-input" required placeholder="Ürün adı">
                    </div>
                    
                    <div>
                        <label class="pd-label">Ürün Kodu</label>
                        <input type="text" name="items[${itemCount}][product_code]" class="pd-input" placeholder="Ürün kodu">
                    </div>
                </div>
                
                <div class="pd-form-grid-3">
                    <div>
                        <label class="pd-label">Adet *</label>
                        <input type="number" name="items[${itemCount}][quantity]" class="pd-input" min="1" required placeholder="Adet">
                    </div>
                    
                    <div>
                        <label class="pd-label">Birim</label>
                        <select name="items[${itemCount}][unit]" class="pd-input">
                            <option value="Adet" selected>Adet</option>
                            <option value="Kutu">Kutu</option>
                            <option value="Paket">Paket</option>
                            <option value="Metre">Metre</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="pd-label">Birim Fiyat *</label>
                        <input type="number" name="items[${itemCount}][unit_price]" class="pd-input" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                
                <div>
                    <label class="pd-label">Açıklama</label>
                    <textarea name="items[${itemCount}][description]" class="pd-input" rows="2" placeholder="Ürün açıklaması"></textarea>
                </div>
                
                <div class="flex items-center space-x-4">
                    <label class="pd-label flex items-center">
                        <input type="checkbox" name="items[${itemCount}][has_print]" class="pd-checkbox mr-2" onchange="togglePrintOptions(${itemCount})">
                        Baskı Hizmeti
                    </label>
                </div>
                
                <div id="printOptions${itemCount}" class="mt-4 space-y-4" style="display: none;">
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">Baskı Türü</label>
                            <select name="items[${itemCount}][print_type]" class="pd-input">
                                <option value="">Seçin</option>
                                <option value="offset">Ofset Baskı</option>
                                <option value="digital">Digital Baskı</option>
                                <option value="screen">Serigrafi</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="pd-label">Baskı Miktarı</label>
                            <input type="number" name="items[${itemCount}][print_quantity]" class="pd-input" min="1" placeholder="Baskı adeti">
                        </div>
                    </div>
                    
                    <div class="pd-form-grid-2">
                        <div>
                            <label class="pd-label">Baskı Birim Fiyat</label>
                            <input type="number" name="items[${itemCount}][print_unit_price]" class="pd-input" step="0.01" min="0" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="pd-label">Baskı Notları</label>
                            <input type="text" name="items[${itemCount}][print_notes]" class="pd-input" placeholder="Baskı notları">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('orderItems').insertAdjacentHTML('beforeend', itemHtml);
    updateSummary();
}

function removeOrderItem(itemId) {
    const item = document.querySelector(`[data-item="${itemId}"]`);
    if (item) {
        item.remove();
        updateSummary();
    }
}

function togglePrintOptions(itemId) {
    const checkbox = document.querySelector(`[data-item="${itemId}"] input[name$="[has_print]"]`);
    const printOptions = document.getElementById(`printOptions${itemId}`);
    
    if (checkbox && printOptions) {
        printOptions.style.display = checkbox.checked ? 'block' : 'none';
        updateSummary();
    }
}

function updateSummary() {
    const items = document.querySelectorAll('[data-item]');
    const itemCount = items.length;
    let totalAmount = 0;
    let printTotalAmount = 0;
    
    items.forEach(item => {
        const quantity = item.querySelector('input[name$="[quantity]"]');
        const unitPrice = item.querySelector('input[name$="[unit_price]"]');
        const hasPrint = item.querySelector('input[name$="[has_print]"]');
        const printQuantity = item.querySelector('input[name$="[print_quantity]"]');
        const printUnitPrice = item.querySelector('input[name$="[print_unit_price]"]');
        
        if (quantity && unitPrice) {
            const qty = parseFloat(quantity.value) || 0;
            const price = parseFloat(unitPrice.value) || 0;
            totalAmount += qty * price;
        }
        
        if (hasPrint && hasPrint.checked && printQuantity && printUnitPrice) {
            const printQty = parseFloat(printQuantity.value) || 0;
            const printPrice = parseFloat(printUnitPrice.value) || 0;
            printTotalAmount += printQty * printPrice;
        }
    });
    
    const grandTotal = totalAmount + printTotalAmount;
    
    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('totalAmount').textContent = totalAmount.toFixed(2) + ' TL';
    document.getElementById('printTotalAmount').textContent = printTotalAmount.toFixed(2) + ' TL';
    document.getElementById('grandTotalAmount').textContent = grandTotal.toFixed(2) + ' TL';
}

// Event listener for price calculations
document.addEventListener('input', function(e) {
    if (e.target.name && (e.target.name.includes('[unit_price]') || e.target.name.includes('[quantity]') || e.target.name.includes('[print_quantity]') || e.target.name.includes('[print_unit_price]'))) {
        updateSummary();
    }
});

// Form ID'sini ekle
document.querySelector('form').id = 'mainForm';
</script>
@endpush
