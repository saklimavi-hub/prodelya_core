<?php

namespace App\Services\Currency;

use App\Contracts\Currency\ExchangeRateProviderInterface;
use App\DTOs\Currency\ExchangeRateBatch;
use App\DTOs\Currency\ExchangeRateData;
use App\Exceptions\Currency\ExchangeRateProviderException;
use App\Exceptions\Currency\UnsupportedCurrencyException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

class TcmbExchangeRateProvider implements ExchangeRateProviderInterface
{
    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly CurrencyMath $math,
    ) {
    }

    public function supports(string $provider): bool
    {
        return $provider === 'tcmb';
    }

    public function fetchForDate(CarbonInterface $date, string $rateType): ExchangeRateBatch
    {
        $config = config('prodelya_currency.providers.tcmb', []);

        if (!(bool) ($config['enabled'] ?? false)) {
            throw new ExchangeRateProviderException('TCMB provider devre dışı.');
        }

        $fieldName = (string) data_get($config, 'supported_rate_types.' . $rateType, '');

        if ($fieldName === '') {
            throw new ExchangeRateProviderException('Geçersiz rate type.', 'unsupported_rate_type', ['rate_type' => $rateType]);
        }

        $url = $this->buildUrl($date, (string) ($config['endpoint_template'] ?? ''));
        $this->guardAllowedUrl($url, (array) ($config['allowed_hosts'] ?? []));

        try {
            $response = Http::retry(
                (int) ($config['retry_times'] ?? 2),
                (int) ($config['retry_sleep_ms'] ?? 250)
            )
                ->timeout((int) ($config['timeout_seconds'] ?? 10))
                ->accept('application/xml, text/xml')
                ->get($url);
        } catch (RequestException $exception) {
            $response = $exception->response;

            if ($response !== null && $response->status() === 404) {
                throw ExchangeRateProviderException::unavailableForDate('tcmb', $date->toDateString());
            }

            throw new ExchangeRateProviderException(
                'TCMB isteği başarısız.',
                'http_error',
                ['status' => $response?->status()]
            );
        }

        if ($response->status() === 404) {
            throw ExchangeRateProviderException::unavailableForDate('tcmb', $date->toDateString());
        }

        if (!$response->successful()) {
            throw new ExchangeRateProviderException('TCMB yanıtı başarısız.', 'http_error', ['status' => $response->status()]);
        }

        $body = $response->body();
        $xml = $this->parseXml($body);
        $resolvedDate = $this->resolveRateDate($xml, $date);
        $fetchedAt = now()->toIso8601String();
        $payloadHash = sha1($body);
        $rates = [];

        foreach ($xml->Currency as $currencyNode) {
            try {
                $currencyCode = $this->normalizer->normalize((string) $currencyNode['CurrencyCode']);
            } catch (UnsupportedCurrencyException) {
                continue;
            }

            if (!in_array($currencyCode, ['USD', 'EUR'], true)) {
                continue;
            }

            $unit = max(1, (int) trim((string) $currencyNode->Unit));
            $rawRate = trim((string) $currencyNode->{$fieldName});

            if ($rawRate === '') {
                continue;
            }

            $normalizedRate = $this->math->ensurePositiveRate(
                $this->math->divide($rawRate, (string) $unit, (int) config('prodelya_currency.rate_precision', 8))
            );

            $rates[] = new ExchangeRateData(
                provider: 'tcmb',
                rateType: $rateType,
                sourceCurrency: $currencyCode,
                targetCurrency: 'TRY',
                rateDate: $resolvedDate->toDateString(),
                sourceUnit: 1,
                rate: $normalizedRate,
                fetchedAt: $fetchedAt,
                payloadHash: $payloadHash,
                meta: [
                    'original_unit' => $unit,
                    'source_date' => $resolvedDate->toDateString(),
                ],
            );
        }

        if (count($rates) < 2) {
            throw new ExchangeRateProviderException('TCMB yanıtında gerekli USD/EUR kurları bulunamadı.', 'missing_currency');
        }

        return new ExchangeRateBatch(
            provider: 'tcmb',
            rateType: $rateType,
            requestedDate: $date->toDateString(),
            resolvedRateDate: $resolvedDate->toDateString(),
            fetchedAt: $fetchedAt,
            payloadHash: $payloadHash,
            rates: $rates,
        );
    }

    private function buildUrl(CarbonInterface $date, string $template): string
    {
        return strtr($template, [
            '{year_month}' => $date->format('Ym'),
            '{date}' => $date->format('dmY'),
        ]);
    }

    private function guardAllowedUrl(string $url, array $allowedHosts): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $normalizedAllowedHosts = array_map(
            static fn (mixed $item): string => mb_strtolower(trim((string) $item), 'UTF-8'),
            $allowedHosts
        );

        if (!is_string($host) || !in_array(mb_strtolower($host, 'UTF-8'), $normalizedAllowedHosts, true)) {
            throw new ExchangeRateProviderException('TCMB endpoint host allowlist dışında.');
        }
    }

    private function parseXml(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$parsed instanceof SimpleXMLElement) {
            throw new ExchangeRateProviderException('TCMB XML verisi ayrıştırılamadı.', 'invalid_xml');
        }

        return $parsed;
    }

    private function resolveRateDate(SimpleXMLElement $xml, CarbonInterface $fallbackDate): CarbonInterface
    {
        $dateText = (string) ($xml['Date'] ?: $xml['Tarih'] ?: '');

        if ($dateText === '') {
            return $fallbackDate->copy();
        }

        $candidates = [];

        foreach (['d/m/Y', 'm/d/Y', 'Y-m-d'] as $format) {
            try {
                $resolved = \Carbon\CarbonImmutable::createFromFormat($format, $dateText);
            } catch (\Throwable) {
                continue;
            }

            if ($resolved instanceof \Carbon\CarbonImmutable && $resolved->format($format) === $dateText) {
                $candidates[] = $resolved;
            }
        }

        if ($candidates === []) {
            return $fallbackDate->copy();
        }

        usort(
            $candidates,
            static fn (\Carbon\CarbonImmutable $left, \Carbon\CarbonImmutable $right): int
                => abs($left->diffInDays($fallbackDate, false)) <=> abs($right->diffInDays($fallbackDate, false))
        );

        return $candidates[0];
    }
}
