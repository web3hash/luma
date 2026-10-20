<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class ExchangeFeeService
{
    private const KEY = 'market:fees';

    private ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function get(string $exchange, string $pair): ?float
    {
        $raw = Redis::get(self::KEY . ':' . $exchange);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $maxAge = 21600;

        if (!is_array($data)
            || microtime(true) - (float) ($data['fetched_at'] ?? 0) > $maxAge
        ) {
            try {
                $data = $this->refresh($exchange);
                $this->lastError = null;
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
                Log::warning('Exchange fee request failed', [
                    'exchange' => $exchange,
                    'pair' => $pair,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        $fee = $data['fees'][$pair] ?? null;

        // The cached response may have been created before a newly supported
        // pair was added. Refresh once when the requested pair is missing.
        if ($fee === null) {
            try {
                $data = $this->refresh($exchange);
                $this->lastError = null;
                $fee = $data['fees'][$pair] ?? null;
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
                Log::warning('Exchange fee refresh failed', [
                    'exchange' => $exchange,
                    'pair' => $pair,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($fee === null) {
            $this->lastError = sprintf(
                '%s did not return a fee for %s (tried configured and source quotes)',
                ucfirst($exchange),
                $pair
            );
        } else {
            $this->lastError = null;
        }

        return is_numeric($fee) ? (float) $fee : null;
    }

    public function refresh(string $exchange): array
    {
        $fees = match ($exchange) {
            'binance' => $this->binance(),
            'kraken' => $this->kraken(),
            default => throw new RuntimeException('Unsupported exchange.'),
        };

        $data = ['fetched_at' => microtime(true), 'fees' => $fees];
        Redis::set(self::KEY . ':' . $exchange, json_encode($data, JSON_THROW_ON_ERROR));
        return $data;
    }

    private function binance(): array
    {
        $fees = [];
        foreach (config('luma.pairs', []) as $pair) {
            $query = http_build_query([
                'symbol' => str_replace('/', '', $pair),
                'timestamp' => (int) round(microtime(true) * 1000),
            ]);
            $signature = hash_hmac('sha256', $query, (string) config('services.binance.api_secret'));
            $response = Http::withHeaders([
                'X-MBX-APIKEY' => (string) config('services.binance.api_key'),
            ])->get('https://api.binance.com/sapi/v1/asset/tradeFee?'
                . $query . '&signature=' . $signature)->throw()->json();

            $fees[$pair] = ((float) ($response[0]['takerCommission'] ?? 0)) / 100;
        }
        return $fees;
    }

    private function kraken(): array
    {
        $path = '/0/private/TradeVolume';
        $nonce = $this->nextKrakenNonce();
        $post = http_build_query(['nonce' => $nonce]);
        $signature = base64_encode(hash_hmac(
            'sha512', $path . hash('sha256', $nonce . $post, true),
            base64_decode((string) config('services.kraken.api_secret'), true), true
        ));
        $response = $this->krakenTradeVolume($nonce, $post, $signature);

        if (!empty($response['error'])) {
            throw new RuntimeException(implode(', ', $response['error']));
        }

        $fees = [];
        foreach (config('luma.pairs', []) as $pair) {
            [$base, $sourceQuote] = array_pad(
                explode('/', strtoupper($pair), 2),
                2,
                null
            );
            $wanted = array_unique(array_filter([
                $this->normalizeKrakenPair(
                    $base . config('luma.kraken_quote_asset')
                ),
                $sourceQuote === null
                    ? null
                    : $this->normalizeKrakenPair($base . $sourceQuote),
            ]));

            foreach ($response['result']['fees'] ?? [] as $key => $entry) {
                $normalized = $this->normalizeKrakenPair((string) $key);

                if (in_array($normalized, $wanted, true) && is_array($entry)
                    && isset($entry['fee'])
                ) {
                    $fees[$pair] = (float) $entry['fee'] / 100;
                    break;
                }
            }

            if (isset($fees[$pair])) {
                continue;
            }

            foreach ($wanted as $candidate) {
                try {
                    $candidateResponse = $this->krakenTradeVolumeForPair($candidate);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($candidateResponse['result']['fees'] ?? [] as $key => $entry) {
                    if ($this->normalizeKrakenPair((string) $key) === $candidate
                        && is_array($entry)
                        && isset($entry['fee'])
                    ) {
                        $fees[$pair] = (float) $entry['fee'] / 100;
                        break 2;
                    }
                }
            }
        }
        return $fees;
    }

    private function krakenTradeVolume(string $nonce, string $post, string $signature): array
    {
        return Http::asForm()->withHeaders([
            'API-Key' => (string) config('services.kraken.api_key'),
            'API-Sign' => $signature,
        ])->post('https://api.kraken.com/0/private/TradeVolume', ['nonce' => $nonce])
            ->throw()->json();
    }

    private function krakenTradeVolumeForPair(string $pair): array
    {
        $path = '/0/private/TradeVolume';
        $nonce = $this->nextKrakenNonce();
        $post = http_build_query(['nonce' => $nonce, 'pair' => $pair]);
        $signature = base64_encode(hash_hmac(
            'sha512', $path . hash('sha256', $nonce . $post, true),
            base64_decode((string) config('services.kraken.api_secret'), true), true
        ));

        $response = Http::asForm()->withHeaders([
            'API-Key' => (string) config('services.kraken.api_key'),
            'API-Sign' => $signature,
        ])->post('https://api.kraken.com' . $path, [
            'nonce' => $nonce,
            'pair' => $pair,
        ])->throw()->json();

        if (!empty($response['error'])) {
            throw new RuntimeException(implode(', ', $response['error']));
        }

        return $response;
    }

    private function normalizeKrakenPair(string $pair): string
    {
        $pair = strtoupper(str_replace(['/', '-', '_'], '', $pair));
        $pair = preg_replace('/^X/', '', $pair) ?? $pair;
        $pair = preg_replace('/^ZUSD$/', 'USD', $pair) ?? $pair;
        $pair = str_replace('ZUSD', 'USD', $pair);
        // Kraken uses XBT for Bitcoin, while the application uses BTC.
        $pair = preg_replace('/^XBT/', 'BTC', $pair) ?? $pair;

        return $pair;
    }

    private function nextKrakenNonce(): string
    {
        $key = 'market:kraken:nonce';
        $now = (int) floor(microtime(true) * 1_000_000);
        $last = (int) (Redis::get($key) ?: 0);
        $nonce = (string) max($now, $last + 1);
        Redis::set($key, $nonce);

        return $nonce;
    }
}
