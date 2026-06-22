<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SupplierProductRaw;
use Illuminate\View\View;

class SuperAdminRawProductController extends Controller
{
    public function index(): View
    {
        $products = SupplierProductRaw::query()
            ->with(['supplier', 'source', 'standardProduct'])
            ->withCount('variants')
            ->latest('updated_at')
            ->get();

        return view('super-admin.product-data-hub.raw-products', compact('products'));
    }
}
