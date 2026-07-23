<?php

namespace App\Http\Requests\Admin;

use App\Models\OrderPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $order = $this->route('order');
        $orderCurrency = $this->normalizeCurrency($order?->currency ?: 'TRY');

        return [
            'payment_type' => ['required', Rule::in([
                OrderPayment::TYPE_COLLECTION,
                OrderPayment::TYPE_REFUND,
                OrderPayment::TYPE_ADJUSTMENT,
            ])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => [
                'required',
                Rule::in(['TL', 'TRY', 'USD', 'EUR']),
                function (string $attribute, mixed $value, \Closure $fail) use ($orderCurrency): void {
                    if ($this->normalizeCurrency($value) !== $orderCurrency) {
                        $fail('Tahsilat para birimi sipariş para birimi ile aynı olmalıdır.');
                    }
                },
            ],
            'payment_method' => ['nullable', Rule::in([
                OrderPayment::METHOD_CASH,
                OrderPayment::METHOD_BANK_TRANSFER,
                OrderPayment::METHOD_CREDIT_CARD,
                OrderPayment::METHOD_CHEQUE,
                OrderPayment::METHOD_PROMISSORY,
                OrderPayment::METHOD_OTHER,
            ])],
            'paid_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_type.required' => 'Tahsilat türü seçilmelidir.',
            'payment_type.in' => 'Geçerli bir tahsilat türü seçin.',
            'amount.required' => 'Tutar alanı zorunludur.',
            'amount.numeric' => 'Tutar sayısal olmalıdır.',
            'amount.min' => 'Tutar 0,01 değerinden büyük olmalıdır.',
            'currency.required' => 'Para birimi zorunludur.',
            'currency.in' => 'Geçerli bir para birimi seçin.',
            'payment_method.in' => 'Geçerli bir ödeme yöntemi seçin.',
            'paid_at.date' => 'Tahsilat tarihi geçerli bir tarih olmalıdır.',
            'due_date.date' => 'Vade tarihi geçerli bir tarih olmalıdır.',
            'payment_reference.max' => 'Referans numarası en fazla 100 karakter olabilir.',
            'payment_note.max' => 'Açıklama en fazla 1000 karakter olabilir.',
        ];
    }

    private function normalizeCurrency(mixed $currency): string
    {
        $value = mb_strtoupper(trim((string) $currency));

        return $value === 'TL' ? 'TRY' : $value;
    }
}
