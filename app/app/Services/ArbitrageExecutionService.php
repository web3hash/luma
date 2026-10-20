<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Client\Response;
use RuntimeException;

class ArbitrageExecutionService
{
    /**
     * Execute both legs of a route and mark it as a transaction test.
     * The caller is responsible for selecting a deliberately small quantity.
     */
    public function test(string $pair, string $direction, float $quantity): array
    {
        Log::channel('profitable_trades')->info('Transaction test started', [
            'pair' => $pair,
            'direction' => $direction,
            'quantity' => $quantity,
        ]);

        try {
            $result = $this->execute($pair, $direction, $quantity, false);
        } catch (\Throwable $e) {
            Log::channel('profitable_trades')->error('Transaction test failed', [
                'pair' => $pair,
                'direction' => $direction,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }

        Log::channel('profitable_trades')->info('Transaction test succeeded', [
            'pair' => $pair,
            'direction' => $direction,
            'quantity' => $quantity,
            'realized' => $result['realized'],
        ]);

        return $result;
    }

    public function execute(
        string $pair,
        string $direction,
        float $quantity,
        bool $respectCooldown = true
    ): array
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Trade quantity must be greater than zero.');
        }

        [$buyExchange, $sellExchange] = str_starts_with($direction, 'Binance')
            ? ['binance', 'kraken']
            : ['kraken', 'binance'];

        $key = 'market:execution:' . sha1($pair . ':' . $direction);
        $cooldown = (int) config('luma.execution_cooldown_seconds', 10);
        if ($respectCooldown && $cooldown > 0 && !Redis::set($key, (string) microtime(true), 'EX', $cooldown, 'NX')) {
            throw new RuntimeException('The same arbitrage route was executed recently.');
        }

        try {
            $buy = $this->marketOrder($buyExchange, $pair, 'BUY', $quantity);
            $sell = $this->marketOrder($sellExchange, $pair, 'SELL', $quantity);
        } catch (\Throwable $e) {
            // A failed attempt must not prevent an immediate retry.
            if ($respectCooldown) {
                Redis::del($key);
            }
            Log::channel('profitable_trades')->error('Arbitrage execution failed', [
                'pair' => $pair,
                'direction' => $direction,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $realized = $this->realizedProfit($buy, $sell);

        Log::channel('profitable_trades')->info('Arbitrage executed', [
            'pair' => $pair,
            'direction' => $direction,
            'quantity' => $quantity,
            'buy_order' => $buy,
            'sell_order' => $sell,
            'realized' => $realized,
        ]);

        return ['buy' => $buy, 'sell' => $sell, 'realized' => $realized];
    }

    private function marketOrder(string $exchange, string $pair, string $side, float $quantity): array
    {
        return match ($exchange) {
            'binance' => $this->binanceOrder($pair, $side, $quantity),
            'kraken' => $this->krakenOrder($pair, $side, $quantity),
            default => throw new RuntimeException('Unsupported exchange.'),
        };
    }

    private function binanceOrder(string $pair, string $side, float $quantity): array
    {
        $this->guardRateLimit('binance');
        $symbol = str_replace('/', '', strtoupper($pair));
        $quantity = $this->normalizeBinanceQuantity($symbol, $quantity);
        $params = [
            'symbol' => $symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.'),
            'recvWindow' => 5000,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        $query = http_build_query($params);
        $params['signature'] = hash_hmac('sha256', $query, (string) config('services.binance.api_secret'));

        $response = Http::asForm()
            ->withHeaders(['X-MBX-APIKEY' => (string) config('services.binance.api_key')])
            ->post('https://api.binance.com/api/v3/order', $params);
        $this->observeResponse('binance', '/api/v3/order', $response);
        $order = $response->throw()->json();

        $executed = (float) ($order['executedQty'] ?? 0);
        $cost = (float) ($order['cummulativeQuoteQty'] ?? 0);
        return $this->orderFill($executed, $cost, $this->binanceFee($order));
    }

    private function normalizeBinanceQuantity(string $symbol, float $quantity): float
    {
        $response = Http::get('https://api.binance.com/api/v3/exchangeInfo', [
            'symbol' => $symbol,
        ])->throw()->json();

        $filters = $response['symbols'][0]['filters'] ?? [];
        $lotSize = collect($filters)->firstWhere('filterType', 'LOT_SIZE');

        if (!is_array($lotSize) || (float) ($lotSize['stepSize'] ?? 0) <= 0) {
            throw new RuntimeException("Binance LOT_SIZE rules unavailable for {$symbol}.");
        }

        $stepSize = (float) $lotSize['stepSize'];
        $minQuantity = (float) ($lotSize['minQty'] ?? $stepSize);
        $normalized = floor(($quantity + 1e-12) / $stepSize) * $stepSize;
        $normalized = round($normalized, 12);

        if ($normalized < $minQuantity) {
            throw new RuntimeException(sprintf(
                'Quantity %.12f is below Binance minimum %.12f for %s (step %.12f).',
                $quantity,
                $minQuantity,
                $symbol,
                $stepSize
            ));
        }

        $notionalFilter = collect($filters)->first(
            static fn (array $filter): bool => in_array(
                $filter['filterType'] ?? '',
                ['NOTIONAL', 'MIN_NOTIONAL'],
                true
            )
        );

        if (is_array($notionalFilter) && (float) ($notionalFilter['minNotional'] ?? 0) > 0) {
            $price = (float) Http::get('https://api.binance.com/api/v3/ticker/price', [
                'symbol' => $symbol,
            ])->throw()->json('price');
            $notional = $normalized * $price;
            $minimum = (float) $notionalFilter['minNotional'];

            if ($notional < $minimum) {
                $required = ceil(($minimum / max($price, PHP_FLOAT_EPSILON)) / $stepSize) * $stepSize;
                throw new RuntimeException(sprintf(
                    'Quantity %.12f for %s is below Binance minimum notional %.8f (current %.8f). Use at least %.12f.',
                    $normalized,
                    $symbol,
                    $minimum,
                    $notional,
                    round($required, 12)
                ));
            }
        }

        return $normalized;
    }

    private function krakenOrder(string $pair, string $side, float $quantity): array
    {
        $this->guardRateLimit('kraken');
        $path = '/0/private/AddOrder';
        $nonce = $this->nextKrakenNonce();
        $data = [
            'nonce' => $nonce,
            'ordertype' => 'market',
            'type' => strtolower($side),
            'volume' => rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.'),
            'pair' => $this->krakenPair($pair),
        ];
        $post = http_build_query($data);
        $signature = base64_encode(hash_hmac(
            'sha512', $path . hash('sha256', $nonce . $post, true),
            base64_decode((string) config('services.kraken.api_secret'), true), true
        ));
        $response = Http::asForm()->withHeaders([
            'API-Key' => (string) config('services.kraken.api_key'),
            'API-Sign' => $signature,
        ])->post('https://api.kraken.com' . $path, $data);
        $this->observeResponse('kraken', $path, $response);
        $payload = $response->json();

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'Kraken HTTP %d: %s',
                $response->status(),
                is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $response->body()
            ));
        }

        $response = is_array($payload) ? $payload : [];

        if (!empty($response['error'])) {
            throw new RuntimeException(implode(', ', $response['error']));
        }

        $txid = $response['result']['txid'][0] ?? null;
        if (!is_string($txid)) {
            throw new RuntimeException('Kraken did not return a transaction id.');
        }

        // AddOrder returns an acknowledgement; QueryOrders contains the actual fill.
        usleep(250000);
        $order = $this->krakenQueryOrder($txid);
        $executed = (float) ($order['vol_exec'] ?? 0);
        $cost = (float) ($order['cost'] ?? 0);
        return $this->orderFill($executed, $cost, (float) ($order['fee'] ?? 0));
    }

    private function krakenPair(string $pair): string
    {
        [$base] = explode('/', strtoupper($pair), 2);
        $quote = strtoupper((string) config('luma.kraken_quote_asset', 'USD'));

        return match ($base) {
            'BTC', 'XBT', 'XXBT' => 'XBT' . $quote,
            'ETH', 'XETH' => 'ETH' . $quote,
            default => $base . $quote,
        };
    }

    private function orderFill(float $quantity, float $cost, float $fee): array
    {
        if ($quantity <= 0 || $cost <= 0) {
            throw new RuntimeException('Exchange returned an order without a completed fill.');
        }

        return [
            'executed_quantity' => $quantity,
            'quote_amount' => $cost,
            'fee_quote' => $fee,
            'average_price' => $cost / $quantity,
        ];
    }

    private function realizedProfit(array $buy, array $sell): array
    {
        $quantity = min($buy['executed_quantity'], $sell['executed_quantity']);
        $buyAmount = $buy['average_price'] * $quantity + $buy['fee_quote'];
        $sellAmount = $sell['average_price'] * $quantity - $sell['fee_quote'];
        $profit = $sellAmount - $buyAmount;

        return [
            'quantity' => $quantity,
            'buy_amount' => $buyAmount,
            'sell_amount' => $sellAmount,
            'profit_quote' => $profit,
            'profit_percent' => $buyAmount > 0 ? $profit / $buyAmount * 100 : 0,
        ];
    }

    private function binanceFee(array $order): float
    {
        $fee = 0.0;
        foreach ($order['fills'] ?? [] as $fill) {
            if (!is_array($fill) || !is_numeric($fill['commission'] ?? null)) {
                continue;
            }
            $commission = (float) $fill['commission'];
            // Binance charges buys in base asset and sells in quote asset.
            $fee += strtoupper((string) ($fill['commissionAsset'] ?? '')) ===
                strtoupper((string) config('luma.binance_quote_asset', 'USDT'))
                ? $commission
                : $commission * (float) ($fill['price'] ?? 0);
        }
        return $fee;
    }

    private function krakenQueryOrder(string $txid): array
    {
        $path = '/0/private/QueryOrders';
        $nonce = $this->nextKrakenNonce();
        $data = ['nonce' => $nonce, 'txid' => $txid];
        $post = http_build_query($data);
        $signature = base64_encode(hash_hmac(
            'sha512', $path . hash('sha256', $nonce . $post, true),
            base64_decode((string) config('services.kraken.api_secret'), true), true
        ));
        $this->guardRateLimit('kraken');
        $response = Http::asForm()->withHeaders([
            'API-Key' => (string) config('services.kraken.api_key'),
            'API-Sign' => $signature,
        ])->post('https://api.kraken.com' . $path, $data);
        $this->observeResponse('kraken', $path, $response);
        $payload = $response->json();

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'Kraken HTTP %d: %s',
                $response->status(),
                is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $response->body()
            ));
        }

        $response = is_array($payload) ? $payload : [];

        if (!empty($response['error'])) {
            throw new RuntimeException(implode(', ', $response['error']));
        }
        return $response['result'][$txid] ?? throw new RuntimeException('Kraken order details unavailable.');
    }

    private function guardRateLimit(string $exchange): void
    {
        $limit = (int) config("luma.api_limits.{$exchange}.requests_per_minute", 60);
        if ($limit <= 0) {
            return;
        }

        $key = "market:api-rate:{$exchange}:" . now()->format('YmdHi');
        $count = (int) Redis::incr($key);
        Redis::expire($key, 70);

        if ($count > $limit) {
            throw new RuntimeException("{$exchange} local API rate limit reached ({$limit}/min).");
        }
    }

    private function observeResponse(string $exchange, string $endpoint, Response $response): void
    {
        $fields = [
            'exchange' => $exchange,
            'endpoint' => $endpoint,
            'status' => $response->status(),
        ];

        foreach ([
            'X-MBX-USED-WEIGHT-1M' => 'binance_used_weight_1m',
            'X-MBX-ORDER-COUNT-10S' => 'binance_order_count_10s',
            'X-MBX-ORDER-COUNT-1D' => 'binance_order_count_1d',
        ] as $header => $name) {
            if ($response->header($header) !== null) {
                $fields[$name] = $response->header($header);
            }
        }

        Log::channel('profitable_trades')->info('Exchange API usage', $fields);
    }

    private function nextKrakenNonce(): string
    {
        $key = 'market:kraken:nonce';
        $now = (int) floor(microtime(true) * 1_000_000);
        $last = (int) (Redis::get($key) ?: 0);
        $nonce = max($now, $last + 1);
        Redis::set($key, (string) $nonce);

        return (string) $nonce;
    }
}
