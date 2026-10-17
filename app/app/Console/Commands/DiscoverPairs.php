<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('app:discover-pairs')]
#[Description('Command description')]
class DiscoverPairs extends Command
{
    protected $signature = 'app:discover-pairs';
    protected $description = 'Find common spot trading pairs on Binance and Kraken';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $binanceResponse = Http::timeout(20)
                ->retry(2, 500)
                ->get('https://api.binance.com/api/v3/exchangeInfo')
                ->throw()
                ->json();

            $krakenResponse = Http::timeout(20)
                ->retry(2, 500)
                ->get('https://api.kraken.com/0/public/AssetPairs')
                ->throw()
                ->json();
        } catch (Throwable $e) {
            $this->error('Failed to fetch market data: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (
            !is_array($binanceResponse['symbols'] ?? null)
            || !is_array($krakenResponse['result'] ?? null)
            || !empty($krakenResponse['error'])
        ) {
            $this->error('Unexpected exchange API response.');

            return self::FAILURE;
        }

        $allowedQuotes = ['USDT', 'USD', 'EUR'];
        $binancePairs = [];
        $krakenPairs = [];

        foreach ($binanceResponse['symbols'] as $symbol) {
            if (
                ($symbol['status'] ?? null) !== 'TRADING'
                || !($symbol['isSpotTradingAllowed'] ?? false)
            ) {
                continue;
            }

            $base = $this->normalizeAsset(
                $symbol['baseAsset'] ?? ''
            );

            $quote = $this->normalizeAsset(
                $symbol['quoteAsset'] ?? ''
            );

            if (
                $base === ''
                || !in_array($quote, $allowedQuotes, true)
            ) {
                continue;
            }

            $key = "{$base}/{$quote}";
            $binancePairs[$key] = $symbol['symbol'];
        }

        foreach ($krakenResponse['result'] as $pair) {
            if (
                isset($pair['status'])
                && $pair['status'] !== 'online'
            ) {
                continue;
            }

            $wsname = $pair['wsname'] ?? null;

            if (!is_string($wsname) || !str_contains($wsname, '/')) {
                continue;
            }

            [$base, $quote] = explode('/', $wsname, 2);

            $base = $this->normalizeAsset($base);
            $quote = $this->normalizeAsset($quote);

            if (
                $base === ''
                || !in_array($quote, $allowedQuotes, true)
            ) {
                continue;
            }

            $key = "{$base}/{$quote}";
            $krakenPairs[$key] = $wsname;
        }

        $common = array_intersect_key(
            $binancePairs,
            $krakenPairs
        );

        ksort($common);

        $this->info('Common spot pairs: ' . count($common));

        $rows = [];

        foreach ($common as $pair => $binanceSymbol) {
            $rows[] = [
                $pair,
                $binanceSymbol,
                $krakenPairs[$pair],
            ];
        }

        if ($rows === []) {
            $this->warn(
                'No common pairs found for the selected quote assets.'
            );

            return self::SUCCESS;
        }

        $this->table(
            ['Normalized pair', 'Binance symbol', 'Kraken pair'],
            $rows
        );

        return self::SUCCESS;
    }

    private function normalizeAsset(string $asset): string
    {
        return match (strtoupper($asset)) {
            'XBT', 'XXBT' => 'BTC',
            'XETH' => 'ETH',
            'ZUSD' => 'USD',
            'ZEUR' => 'EUR',
            default => strtoupper($asset),
        };
    }
}
