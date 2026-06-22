<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TenantResolver;
use Illuminate\Http\Request;

class CompanyImportController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Show the import screen.
     */
    public function index()
    {
        return view('admin.companies.import.index');
    }

    /**
     * Preview uploaded file and show field mapping.
     */
    public function preview(Request $request)
    {
        return redirect()
            ->route('admin.companies.import.index')
            ->with('info', 'Cari kart içe aktarma modülü hazırlık aşamasındadır. Bu ekranda gerçek önizleme ve alan eşleme henüz açılmadı.');
    }

    /**
     * Store the imported companies.
     */
    public function store(Request $request)
    {
        return redirect()
            ->route('admin.companies.import.index')
            ->withErrors([
                'import' => 'Cari kart içe aktarma modülü henüz aktif değildir. Gerçek parser, mükerrer kontrolü ve audit akışı tamamlanmadan import başlatılamaz.',
            ]);
    }

    /**
     * Download CSV template.
     */
    public function template()
    {
        // TODO: Generate real CSV template
        // For now, return a simple CSV response
        
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="cari_kart_sablon.csv"',
        ];

        $csvContent = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csvContent .= "Firma Ünvanı,Kısa Ad,Cari Tipi,Vergi Dairesi,Vergi No,E-posta,Telefon,Mobil,Yetkili Adı,Yetkili Ünvanı,Adres,İl,İlçe,Grup / Etiket,Notlar\n";
        $csvContent .= "Örnek Firma A,Örnek A,Müşteri,Merkez,1234567890,info@orneka.com,0212 123 45 67,0532 123 45 67,Yetkili Kişi,Ünvan,Adres,İstanbul,İlçe,Grup,Notlar\n";
        $csvContent .= "Örnek Firma B,Örnek B,Tedarikçi,Merkez,0987654321,info@ornekb.com,0216 987 65 43,0533 987 65 43,Yetkili Kişi,Ünvan,Adres,İstanbul,İlçe,Grup,Notlar\n";

        return response($csvContent, 200, $headers);
    }
}
