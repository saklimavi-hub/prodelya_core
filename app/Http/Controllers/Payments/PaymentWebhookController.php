<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Services\Payments\PaymentWebhookProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentWebhookProcessingService $processingService
    ) {
    }

    public function receive(Request $request, PaymentProvider $paymentProvider): JsonResponse
    {
        $result = $this->processingService->handle($request, $paymentProvider);

        return response()->json($result['payload'], $result['code']);
    }
}
