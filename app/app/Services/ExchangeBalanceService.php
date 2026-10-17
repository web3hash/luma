<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class ExchangeBalanceService
{
    private const CACHE_PREFIX = 'market:balances:';

    public function balances(string $exchange): ?array
    {
        $raw = Redis::get(self::CACHE_PREFIX . $exchange);

        if (!is_string($raw)) {
            return null;
        }

        $cached = json_decode($raw, true);
        $maxAge = (int) config('spreadradar.balance_refresh_seconds', 60) * 2;

        if (!is_array($cached)
            || !isset($cached['fetched_at'], $cached['balances'])
            || microtime(true) - (float) $cached['fetched_at'] > $maxAge
        ) {
            return null;
        }

        return is_array($cached['balances']) ? $cached['balances'] : null;
    }

    public function refresh(string $exchange): array
    {
        $balances = match ($exchange) {
            'binance' => $this->binance(),
            'kraken' => $this->kraken(),
            default => throw new RuntimeException('Unsupported exchange.'),
        };

        Redis::set(self::CACHE_PREFIX . $exchange, json_encode([
            'fetched_at' => microtime(true),
            'balances' => $balances,
        ], JSON_THROW_ON_ERROR));

        return $balances;
    }

    public function fresh(string $exchange): ?array
    {
        $balances = $this->balances($exchange);
        $refresh = (int) config('spreadradar.balance_refresh_seconds', 60);

        $raw = Redis::get(self::CACHE_PREFIX . $exchange);
        $cached = is_string($raw) ? json_decode($raw, true) : null;

        if ($balances !== null && is_array($cached)
            && microtime(true) - (float) ($cached['fetched_at'] ?? 0) < $refresh
        ) {
            return $balances;
        }

        try {
            return $this->refresh($exchange);
        } catch (\Throwable) {
            return $balances;
        }
    }

    private function binance(): array
    {
        $timestamp = (int) round(microtime(true) * 1000);
        $query = http_build_query([
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
        ]);
        $signature = hash_hmac(
            'sha256',
            $query,
            (string) config('services.binance.api_secret')
        );

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => (string) config('services.binance.api_key'),
        ])->get('https://api.binance.com/api/v3/account?' . $query
            . '&signature=' . $signature)->throw()->json();

        $result = [];
        foreach ($response['balances'] ?? [] as $item) {
            $free = (float) ($item['free'] ?? 0);
            if ($free > 0) {
                $result[$this->asset($item['asset'] ?? '')] = $free;
            }
        }

        return $this->applyReserve($result);
    }

    private function kraken(): array
    {
        $path = '/0/private/Balance';
        $nonce = $this->nextKrakenNonce();
        $post = http_build_query(['nonce' => $nonce]);
        $signature = base64_encode(hash_hmac(
            'sha512',
            $path . hash('sha256', $nonce . $post, true),
            base64_decode((string) config('services.kraken.api_secret'), true),
            true
        ));

        $response = Http::asForm()->withHeaders([
            'API-Key' => (string) config('services.kraken.api_key'),
            'API-Sign' => $signature,
        ])->post('https://api.kraken.com' . $path, [
            'nonce' => $nonce,
        ])->throw()->json();

        if (!empty($response['error'])) {
            throw new RuntimeException(implode(', ', $response['error']));
        }

        $result = [];
        foreach ($response['result'] ?? [] as $asset => $amount) {
            $result[$this->asset($asset)] = (float) $amount;
        }

        return $this->applyReserve($result);
    }

    private function applyReserve(array $balances): array
    {
        $factor = 1 - ((float) config('spreadradar.reserve_percent', 10) / 100);
        return array_map(static fn (float $value): float => max(0, $value * $factor), $balances);
    }

    private function asset(string $asset): string
    {
        return match (strtoupper($asset)) {
            'XBT', 'XXBT' => 'BTC',
            'XETH' => 'ETH',
            'ZUSD' => 'USD',
            'ZEUR' => 'EUR',
            default => strtoupper($asset),
        };
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
