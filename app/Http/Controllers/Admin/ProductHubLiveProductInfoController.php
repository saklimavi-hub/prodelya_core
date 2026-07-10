<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProductDataHub\ProductHubLiveProductInfoService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductHubLiveProductInfoController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly ProductHubLiveProductInfoService $liveProductInfoService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if (!$tenant) {
            return response()->json([
                'ok' => false,
                'public_safe_message' => 'Tenant bilgisi olmadan urun bilgisi okunamaz.',
                'warnings' => ['Tenant baglami bulunamadi.'],
            ], 403);
        }

        $result = $this->liveProductInfoService->resolve($tenant, $request->all(), $request->user());

        return response()->json($result['body'], $result['status']);
    }
}
