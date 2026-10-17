<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class ListAvailablePairs extends Command
{
    protected $signature = 'market:list-pairs
                            {--limit=100 : Number of pairs to show}
                            {--quote=USDT : Quote asset}
                            {--exchange=both : binance, kraken or both}';

    protected $description = 'List common spot pairs available on Binance and Kraken';

    public function handle(): int
    {
        $quote = strtoupper(trim((string) $this->option('quote')));
        $exchange = strtolower(trim((string) $this->option('exchange')));
        $limit = max(0, (int) $this->option('limit'));

        if (!in_array($exchange, ['binance', 'kraken', 'both'], true)) {
            $this->error('Exchange must be binance, kraken or both.');

            return self::INVALID;
        }

        try {
            $binance = Http::timeout(20)
                ->get('https://api.binance.com/api/v3/exchangeInfo')
                ->throw()->json('symbols', []);
            $tickers = Http::timeout(20)
                ->get('https://api.binance.com/api/v3/ticker/24hr')
                ->throw()->json();
            $kraken = Http::timeout(20)
                ->get('https://api.kraken.com/0/public/AssetPairs')
                ->throw()->json('result', []);
            $krakenTickers = Http::timeout(20)
                ->get('https://api.kraken.com/0/public/Ticker')
                ->throw()->json('result', []);
        } catch (Throwable $e) {
            $this->error('Failed to fetch pair lists: ' . $e->getMessage());

            return self::FAILURE;
        }

        $volumes = [];
        foreach ($tickers as $ticker) {
            if (isset($ticker['symbol'], $ticker['quoteVolume'])) {
                $volumes[$ticker['symbol']] = (float) $ticker['quoteVolume'];
            }
        }

        $binancePairs = [];
        foreach ($binance as $symbol) {
            if (($symbol['status'] ?? null) !== 'TRADING'
                || ($symbol['quoteAsset'] ?? null) !== $quote
                || !($symbol['isSpotTradingAllowed'] ?? false)
            ) {
                continue;
            }

            $pair = strtoupper($symbol['baseAsset']) . '/' . $quote;
            $binancePairs[$pair] = [
                'symbol' => $symbol['symbol'],
                'volume' => $volumes[$symbol['symbol']] ?? 0,
            ];
        }

        $krakenPairs = [];
        foreach ($kraken as $item) {
            $wsname = $item['wsname'] ?? null;

            if (!is_string($wsname) || !str_contains($wsname, '/')) {
                continue;
            }

            [$base, $pairQuote] = explode('/', strtoupper($wsname), 2);
            if ($pairQuote !== $quote) {
                continue;
            }

            $base = match ($base) {
                'XBT', 'XXBT' => 'BTC',
                'XETH' => 'ETH',
                default => $base,
            };

            $pair = $base . '/' . $quote;
            $ticker = $krakenTickers[$item['altname'] ?? '']
                ?? $krakenTickers[$item['wsname'] ?? '']
                ?? [];

            $krakenPairs[$pair] = [
                'symbol' => $wsname,
                'volume' => (float) ($ticker['v'][1] ?? 0),
            ];
        }

        $rows = [];
        if ($exchange === 'binance') {
            foreach ($binancePairs as $pair => $data) {
                $rows[] = [$pair, $data['symbol'], '-', $data['volume'], 0];
            }
        } elseif ($exchange === 'kraken') {
            foreach ($krakenPairs as $pair => $data) {
                $rows[] = [$pair, '-', $data['symbol'], 0, $data['volume']];
            }
        } else {
            foreach (array_intersect_key($binancePairs, $krakenPairs) as $pair => $data) {
                $rows[] = [
                    $pair,
                    $data['symbol'],
                    $krakenPairs[$pair]['symbol'],
                    $data['volume'],
                    $krakenPairs[$pair]['volume'],
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b[3] <=> $a[3]);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $label = $exchange === 'both' ? 'common' : $exchange;
        $this->info("{$label} spot pairs with {$quote}: " . count($rows));
        $this->table(
            ['#', 'Pair', 'Binance', 'Kraken', 'Binance 24h volume', 'Kraken 24h volume'],
            array_map(
                static fn (int $index, array $row): array => [
                    $index + 1,
                    $row[0],
                    $row[1],
                    $row[2],
                    number_format($row[3], 2, '.', ','),
                    number_format($row[4], 2, '.', ','),
                ],
                array_keys($rows),
                $rows
            )
        );

        return self::SUCCESS;
    }
}
