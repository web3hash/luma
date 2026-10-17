<?php

namespace App\Console\Commands;

use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Websocket\Client\connect;

#[Signature('app:watch-market-data')]
#[Description('Command description')]
class WatchMarketData extends Command
{
    protected $signature = 'app:watch-market-data';

    protected $description = 'Watch Binance and Kraken top-of-book data';

    public function handle(): int
    {
        $pairs = config('spreadradar.pairs', []);

        if (!is_array($pairs) || $pairs === []) {
            $this->error('No currency pairs configured.');

            return self::FAILURE;
        }

        $symbols = [];

        foreach ($pairs as $pair) {
            $symbols[str_replace('/', '', $pair)] = $pair;
        }

        $krakenSymbols = $this->resolveKrakenSymbols($pairs);

        if ($krakenSymbols === []) {
            $this->error('No configured pairs are supported by Kraken WebSocket v2.');

            return self::FAILURE;
        }

        $latest = [];

        $store = function (
            string $exchange,
            string $pair,
            array  $data
        ) use (&$latest): void {
            $record = array_merge($data, [
                'exchange' => $exchange,
                'pair' => $pair,
                'received_at' => microtime(true),
            ]);

            $latest["{$exchange}:{$pair}"] = $record;

            try {
                Redis::setex(
                    "market:top:{$exchange}:{$pair}",
                    10,
                    json_encode($record, JSON_THROW_ON_ERROR)
                );
            } catch (Throwable $e) {
                $this->error('Redis write failed: ' . $e->getMessage());
            }
        };

        /*
         * Binance: combined bookTicker stream.
         */
        $binanceUrl = config(
            'spreadradar.binance_websocket_url'
        ) . '?streams='
            . implode('/', array_map(
                fn(string $symbol) => strtolower($symbol) . '@bookTicker',
                array_keys($symbols)
            ));

        $binanceFuture = async(function () use (
            $binanceUrl,
            $symbols,
            $store
        ): void {
            while (true) {
                $connection = null;

                try {
                    $connection = connect($binanceUrl);

                    $this->info('Binance WebSocket connected');

                    while ($message = $connection->receive()) {
                        $payload = json_decode(
                            $message->buffer(),
                            true
                        );

                        if (!is_array($payload)) {
                            continue;
                        }

                        $data = $payload['data'] ?? $payload;
                        $symbol = $data['s'] ?? null;

                        if (!is_string($symbol) || !isset($symbols[$symbol])) {
                            continue;
                        }

                        $store('binance', $symbols[$symbol], [
                            'bid' => $data['b'] ?? null,
                            'bid_qty' => $data['B'] ?? null,
                            'ask' => $data['a'] ?? null,
                            'ask_qty' => $data['A'] ?? null,
                            'exchange_timestamp' => null,
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->warn(
                        'Binance connection error: ' . $e->getMessage()
                    );
                } finally {
                    if ($connection !== null) {
                        try {
                            $connection->close();
                        } catch (Throwable) {
                            // Ignore errors while closing.
                        }
                    }
                }

                $this->warn('Binance reconnecting in 5 seconds...');
                delay(5);
            }
        });

        /*
         * Kraken: WebSocket v2 ticker channel.
         */
        $krakenUrl = config('spreadradar.kraken_websocket_url');

        $krakenFuture = async(function () use (
            $krakenUrl,
            $krakenSymbols,
            $store
        ): void {
            while (true) {
                $connection = null;

                try {
                    $connection = connect($krakenUrl);

                    $this->info('Kraken WebSocket connected');

                    $connection->sendText(json_encode([
                        'method' => 'subscribe',
                        'params' => [
                            'channel' => 'ticker',
                            'symbol' => $krakenSymbols,
                            'event_trigger' => 'bbo',
                            'snapshot' => true,
                        ],
                        'req_id' => 1,
                    ], JSON_THROW_ON_ERROR));

                    while ($message = $connection->receive()) {
                        $payload = json_decode(
                            $message->buffer(),
                            true
                        );

                        if (!is_array($payload)) {
                            continue;
                        }

                        if (($payload['success'] ?? true) === false) {
                            $this->error(
                                'Kraken subscription error: '
                                . ($payload['error'] ?? 'unknown error')
                            );

                            continue;
                        }

                        if (($payload['channel'] ?? null) !== 'ticker') {
                            continue;
                        }

                        foreach ($payload['data'] ?? [] as $data) {
                            $pair = $data['symbol'] ?? null;

                            if (!is_string($pair)) {
                                continue;
                            }

                            $store('kraken', $this->canonicalKrakenPair($pair), [
                                'bid' => $data['bid'] ?? null,
                                'bid_qty' => $data['bid_qty'] ?? null,
                                'ask' => $data['ask'] ?? null,
                                'ask_qty' => $data['ask_qty'] ?? null,
                                'exchange_timestamp' =>
                                    $data['timestamp'] ?? null,
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    $this->warn(
                        'Kraken connection error: ' . $e->getMessage()
                    );
                } finally {
                    if ($connection !== null) {
                        try {
                            $connection->close();
                        } catch (Throwable) {
                            // Ignore errors while closing.
                        }
                    }
                }

                $this->warn('Kraken reconnecting in 5 seconds...');
                delay(5);
            }
        });

        /*
         * Print latest prices every 2 seconds.
         */
        $statusFuture = async(function () use (&$latest): void {
            while (true) {
                delay(2);

                $this->newLine();
                $this->line('--- Top of book ---');

                if ($latest === []) {
                    $this->warn('Waiting for market data...');
                    continue;
                }

                foreach ($latest as $record) {
                    $age = microtime(true) - $record['received_at'];

                    $this->line(sprintf(
                        '%-7s %-10s bid=%-14s ask=%-14s age=%.1fs',
                        $record['exchange'],
                        $record['pair'],
                        $record['bid'] ?? 'n/a',
                        $record['ask'] ?? 'n/a',
                        $age
                    ));
                }
            }
        });

        $this->info(
            'SpreadRadar market watcher started. Press Ctrl+C to stop.'
        );

        await([
            $binanceFuture,
            $krakenFuture,
            $statusFuture,
        ]);

        return self::SUCCESS;
    }

    private function resolveKrakenSymbols(array $pairs): array
    {
        try {
            $result = Http::timeout(15)
                ->get('https://api.kraken.com/0/public/AssetPairs')
                ->throw()
                ->json('result', []);
        } catch (Throwable $e) {
            $this->error('Kraken pair list failed: ' . $e->getMessage());

            return [];
        }

        $available = [];
        foreach ($result as $item) {
            $wsname = $item['wsname'] ?? null;

            if (is_string($wsname) && str_contains($wsname, '/')) {
                $available[$this->normalizePair($wsname)] = $wsname;
            }
        }

        $resolved = [];
        foreach ($pairs as $pair) {
            [$base] = explode('/', strtoupper($pair), 2);
            $key = $this->normalizePair(
                $base . '/' . config('spreadradar.kraken_quote_asset')
            );

            if (isset($available[$key])) {
                $resolved[] = $available[$key];
            } else {
                $this->warn("Kraken pair is unavailable in WebSocket v2: {$pair}");
            }
        }

        return array_values(array_unique($resolved));
    }

    private function normalizePair(string $pair): string
    {
        [$base, $quote] = explode('/', strtoupper($pair), 2);

        return match ($base) {
            'XBT', 'XXBT' => 'BTC/' . $quote,
            'XETH' => 'ETH/' . $quote,
            default => $base . '/' . $quote,
        };
    }

    private function canonicalKrakenPair(string $pair): string
    {
        [$base] = explode('/', strtoupper($pair), 2);

        return $this->normalizePair(
            $base . '/' . config('spreadradar.binance_quote_asset')
        );
    }
}
