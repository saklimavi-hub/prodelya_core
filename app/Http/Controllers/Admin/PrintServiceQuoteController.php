<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

class PrintServiceQuoteController extends Controller
{
    public function __construct()
    {
        // TODO: Add middleware for print service quotes
        // $this->middleware('permission:manage_print_service_quotes');
    }

    /**
     * Display a listing of print service quotes
     */
    public function index(): View
    {
        // Demo/placeholder veriler
        $quotes = [
            [
                'id' => 1,
                'quote_number' => 'TK-2024-0001',
                'customer' => 'Demo Müşteri A.Ş.',
                'customer_code' => 'CUS-001',
                'reference_code' => 'REF-001',
                'total_amount' => 1500.00,
                'currency' => 'TL',
                'status' => 'draft',
                'created_at' => now()->subDays(2),
                'items_count' => 3,
            ],
            [
                'id' => 2,
                'quote_number' => 'TK-2024-0002',
                'customer' => 'Test Müşteri Ltd.',
                'customer_code' => 'CUS-002',
                'reference_code' => 'REF-002',
                'total_amount' => 2500.00,
                'currency' => 'TL',
                'status' => 'sent',
                'created_at' => now()->subDays(1),
                'items_count' => 5,
            ],
            [
                'id' => 3,
                'quote_number' => 'TK-2024-0003',
                'customer' => 'Örnek Müşteri',
                'customer_code' => 'CUS-003',
                'reference_code' => 'REF-003',
                'total_amount' => 800.00,
                'currency' => 'TL',
                'status' => 'approved',
                'created_at' => now()->subHours(6),
                'items_count' => 2,
            ],
        ];

        return view('admin.print-service-quotes.index', compact('quotes'));
    }

    /**
     * Show the form for creating a new print service quote
     */
    public function create(): View
    {
        $customers = $this->getCustomers();

        return view('admin.print-service-quotes.create', compact('customers'));
    }

    /**
     * Store a newly created print service quote
     */
    public function store(Request $request): RedirectResponse
    {
        // TODO: Implement actual quote creation logic
        return redirect()
            ->route('admin.print-service-quotes.index')
            ->with('success', 'Baskı teklifi başarıyla oluşturuldu.');
    }

    /**
     * Display the specified print service quote
     */
    public function show($id): View
    {
        // Demo teklif detayı
        $quote = [
            'id' => $id,
            'quote_number' => 'TK-2024-0001',
            'customer' => 'Demo Müşteri A.Ş.',
            'customer_code' => 'CUS-001',
            'reference_code' => 'REF-001',
            'total_amount' => 1500.00,
            'currency' => 'TL',
            'status' => 'draft',
            'created_at' => now()->subDays(2),
            'notes' => 'Demo notlar buraya gelecek',
            'items' => [
                [
                    'id' => 1,
                    'customer_product_description' => 'Kurumsal Kimlik Kartı',
                    'quantity' => 500,
                    'print_type' => 'Ofset Baskı',
                    'print_option' => '2 Renk',
                    'print_location' => 'Ön Yüz',
                    'print_color' => 'Mavi-Beyaz',
                    'plate' => 'Klişe',
                    'print_quantity' => 500,
                    'unit_price' => 2.50,
                    'total_price' => 1250.00,
                    'notes' => 'Kaliteli karton kullanılacak',
                ],
                [
                    'id' => 2,
                    'customer_product_description' => 'Broşür',
                    'quantity' => 1000,
                    'print_type' => 'Digital Baskı',
                    'print_option' => '4 Renk',
                    'print_location' => 'Çift Yüz',
                    'print_color' => 'Tam Renkli',
                    'plate' => 'Yok',
                    'print_quantity' => 1000,
                    'unit_price' => 0.25,
                    'total_price' => 250.00,
                    'notes' => 'Hızlı teslimat',
                ],
            ],
        ];

        return view('admin.print-service-quotes.show', compact('quote'));
    }

    /**
     * Show the form for editing the specified print service quote
     */
    public function edit($id): View
    {
        // Demo teklif detayı
        $quote = [
            'id' => $id,
            'quote_number' => 'TK-2024-0001',
            'customer_id' => 1,
            'customer' => 'Demo Müşteri A.Ş.',
            'customer_code' => 'CUS-001',
            'reference_code' => 'REF-001',
            'total_amount' => 1500.00,
            'currency' => 'TL',
            'status' => 'draft',
            'created_at' => now()->subDays(2),
            'notes' => 'Demo notlar buraya gelecek',
        ];

        $customers = $this->getCustomers();

        return view('admin.print-service-quotes.edit', compact('quote', 'customers'));
    }

    /**
     * Update the specified print service quote
     */
    public function update(Request $request, $id): RedirectResponse
    {
        // TODO: Implement actual quote update logic
        return redirect()
            ->route('admin.print-service-quotes.show', $id)
            ->with('success', 'Baskı teklifi başarıyla güncellendi.');
    }

    /**
     * Remove the specified print service quote
     */
    public function destroy($id): RedirectResponse
    {
        // TODO: Implement actual quote deletion logic
        return redirect()
            ->route('admin.print-service-quotes.index')
            ->with('success', 'Baskı teklifi başarıyla silindi.');
    }

    /**
     * Convert quote to order
     */
    public function convertToOrder($id): RedirectResponse
    {
        // TODO: Implement quote to order conversion logic
        return redirect()
            ->route('admin.print-service-quotes.show', $id)
            ->with('info', 'Tekliften siparişe çevirme işlemi sonraki aşamada geliştirilecek.');
    }

    /**
     * Send quote to customer
     */
    public function sendToCustomer($id): RedirectResponse
    {
        // TODO: Implement quote sending logic
        return redirect()
            ->route('admin.print-service-quotes.show', $id)
            ->with('info', 'Teklif gönderme işlemi sonraki aşamada geliştirilecek.');
    }

    private function getCustomers(): Collection
    {
        try {
            return Company::query()
                ->whereHas('companyRoles', function ($query) {
                    $query->where('role_key', 'customer');
                })
                ->limit(10)
                ->get();
        } catch (\Throwable $exception) {
            return collect();
        }
    }
}
