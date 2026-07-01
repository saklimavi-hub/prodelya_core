<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentCheckoutSession;
use App\Services\Payments\PaymentCheckoutStatusService;
use Illuminate\Contracts\View\View;

class PaymentCheckoutCallbackController extends Controller
{
    public function __construct(
        protected PaymentCheckoutStatusService $checkoutStatusService
    ) {
    }

    public function success(PaymentCheckoutSession $paymentCheckout): View
    {
        $paymentCheckout = $this->checkoutStatusService->applyStatus(
            $paymentCheckout,
            $paymentCheckout->status,
            'customer_return_success',
            ['callback' => 'success'],
            'Müşteri provider başarı ekranından döndü; kesin tahsilat webhook ile doğrulanır.'
        );

        return view('payments.checkout-callback', [
            'session' => $paymentCheckout,
            'statusLabel' => 'Ödeme Tamamlandı',
            'statusTone' => 'green',
            'message' => 'Provider callback başarısı alındı. Nihai ödeme durumu webhook ile senkronize edilir.',
        ]);
    }

    public function failure(PaymentCheckoutSession $paymentCheckout): View
    {
        if ($paymentCheckout->status === PaymentCheckoutSession::STATUS_PENDING) {
            $paymentCheckout = $this->checkoutStatusService->applyStatus(
                $paymentCheckout,
                PaymentCheckoutSession::STATUS_FAILED,
                'customer_return_failure',
                ['callback' => 'failure'],
                'Müşteri provider başarısız ödeme ekranından döndü.'
            );
        }

        return view('payments.checkout-callback', [
            'session' => $paymentCheckout,
            'statusLabel' => 'Ödeme Başarısız',
            'statusTone' => 'red',
            'message' => 'Provider başarısız callback döndürdü. Detay webhook ve provider loglarında takip edilmelidir.',
        ]);
    }

    public function cancel(PaymentCheckoutSession $paymentCheckout): View
    {
        if ($paymentCheckout->status === PaymentCheckoutSession::STATUS_PENDING) {
            $paymentCheckout = $this->checkoutStatusService->applyStatus(
                $paymentCheckout,
                PaymentCheckoutSession::STATUS_CANCELLED,
                'customer_return_cancelled',
                ['callback' => 'cancel'],
                'Müşteri checkout akışını iptal etti.'
            );
        }

        return view('payments.checkout-callback', [
            'session' => $paymentCheckout,
            'statusLabel' => 'Ödeme İptal Edildi',
            'statusTone' => 'amber',
            'message' => 'Checkout iptal edildi. Gerekirse yeni bir ödeme oturumu oluşturulabilir.',
        ]);
    }
}
