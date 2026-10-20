<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\ExchangeBalanceService;
use App\Services\ExchangeFeeService;
use App\Services\ArbitrageExecutionService;
use Throwable;

class CalculateSpreads extends Command
{
    protected $signature = 'market:calculate-spreads
                            {--once : Calculate once and exit}
                            {--execute : Execute SIGNAL rows as live market orders}';

    protected $description = 'Calculate cross-exchange arbitrage spreads';

    public function handle(
        ExchangeBalanceService $balanceService,
        ExchangeFeeService $feeService,
        ArbitrageExecutionService $executionService
    ): int
    {
        $pairs = config('luma.pairs', []);

        if (!is_array($pairs) || $pairs === []) {
            $this->error('No currency pairs configured.');

            return self::FAILURE;
        }

        $threshold = (float)config(
            'luma.min_net_spread_pct',
            0.3
        );

        $maxAge = (float)config(
            'luma.max_quote_age_seconds',
            5
        );

        $interval = max(
            1,
            (int)config('luma.refresh_seconds', 2)
        );

        $capital = (float) config('luma.capital_usdt', 100);
        $showAllPairs = (bool) config('luma.show_all_pairs', false);
        if ($capital <= 0) {
            $this->error('Capital must be greater than zero.');
            return self::FAILURE;
        }

        if (
            $threshold < 0
            || $maxAge <= 0
        ) {
            $this->error('Invalid Luma configuration.');

            return self::FAILURE;
        }

        $this->info('Luma spread calculator started.');
        $this->line(sprintf(
            'Fees: account-specific taker fees | Signal threshold: %.3f%% | Balance check: %s',
            $threshold,
            $showAllPairs ? 'disabled' : 'enabled'
        ));

        do {
            $binanceBalances = $showAllPairs
                ? []
                : $balanceService->fresh('binance');
            $krakenBalances = $showAllPairs
                ? []
                : $balanceService->fresh('kraken');
            $rows = [];
            $now = microtime(true);

            foreach ($pairs as $pair) {
                $binanceFeeForPair = $feeService->get('binance', $pair);
                $krakenFeeForPair = $feeService->get('kraken', $pair);
                [$base, $quote] = explode('/', $pair, 2);
                $binanceQuote = config('luma.binance_quote_asset');
                $krakenQuote = config('luma.kraken_quote_asset');
                $binanceBuyBalance = $showAllPairs
                    ? $capital
                    : ($binanceBalances[$binanceQuote] ?? null);
                $krakenBuyBalance = $showAllPairs
                    ? $capital
                    : ($krakenBalances[$krakenQuote] ?? null);
                $binanceSellBalance = $showAllPairs
                    ? PHP_FLOAT_MAX
                    : ($binanceBalances[$base] ?? null);
                $krakenSellBalance = $showAllPairs
                    ? PHP_FLOAT_MAX
                    : ($krakenBalances[$base] ?? null);

                $missingBalances = [];

                if ($binanceBalances === null) {
                    $missingBalances[] = 'Binance: balance unavailable/stale';
                } else {
                    if ($binanceBuyBalance === null) {
                        $missingBalances[] = "Binance: {$binanceQuote}";
                    }

                    if ($binanceSellBalance === null) {
                        $missingBalances[] = "Binance: {$base}";
                    }
                }

                if ($krakenBalances === null) {
                    $missingBalances[] = 'Kraken: balance unavailable/stale';
                } else {
                    if ($krakenBuyBalance === null) {
                        $missingBalances[] = "Kraken: {$krakenQuote}";
                    }

                    if ($krakenSellBalance === null) {
                        $missingBalances[] = "Kraken: {$base}";
                    }
                }

                $binance = $this->getQuote(
                    'binance',
                    $pair,
                    $maxAge,
                    $now
                );

                $kraken = $this->getQuote(
                    'kraken',
                    $pair,
                    $maxAge,
                    $now
                );

                if ($binance === null || $kraken === null) {
                    $missing = [];

                    if ($binance === null) {
                        $missing[] = 'Binance';
                    }

                    if ($kraken === null) {
                        $missing[] = 'Kraken';
                    }

                    $rows[] = [
                        $pair,
                        implode(' + ', $missing),
                        '-',
                        '-',
                        '-',
                        '-',
                        'SKIP: quote missing/stale: ' . implode(', ', $missing),
                    ];

                    continue;
                }

                if (!$showAllPairs && $missingBalances !== []) {
                    $rows[] = [
                        $pair, '-', '-', '-', '-', '-', '-', '-', '-',
                        'SKIP: missing ' . implode(', ', $missingBalances),
                    ];
                    continue;
                }

                if ($binanceFeeForPair === null || $krakenFeeForPair === null) {
                    $rows[] = [
                        $pair, '-', '-', '-', '-', '-', '-', '-', '-',
                        'SKIP: fee unavailable',
                    ];
                    continue;
                }

                // Buy on Binance, sell on Kraken.
                $binanceToKraken = $this->calculateRoute(
                    $pair,
                    'Binance → Kraken',
                    $binance,
                    $kraken,
                    $binanceFeeForPair,
                    $krakenFeeForPair,
                    $threshold,
                    $binanceBuyBalance,
                    $krakenSellBalance,
                    count($pairs)
                );
                $rows[] = $binanceToKraken;
                $this->logProfitableTrade($binanceToKraken, $executionService);

                // Buy on Kraken, sell on Binance.
                $krakenToBinance = $this->calculateRoute(
                    $pair,
                    'Kraken → Binance',
                    $kraken,
                    $binance,
                    $krakenFeeForPair,
                    $binanceFeeForPair,
                    $threshold,
                    $krakenBuyBalance,
                    $binanceSellBalance,
                    count($pairs)
                );
                $rows[] = $krakenToBinance;
                $this->logProfitableTrade($krakenToBinance, $executionService);
            }

            $this->newLine();
            $this->line('--- Arbitrage opportunities ---');

            $this->table(
                [
                    'Pair',
                    'Direction',
                    'Buy ask',
                    'Sell bid',
                    'Qty',
                    'Cost USDT',
                    'Gross %',
                    'Net %',
                    'Profit USDT',
                    'Status',
                ],
                $rows
            );

            $this->line(
                'Net % includes estimated taker fees on both exchanges.'
            );

            if ($this->option('once')) {
                break;
            }

            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }

    private function logProfitableTrade(array $row, ArbitrageExecutionService $executionService): void
    {
        $profit = (float) str_replace(',', '', (string) ($row[8] ?? 0));

        if ($profit <= 0) {
            return;
        }

        Log::channel('profitable_trades')->info('Potentially profitable trade', [
            'pair' => $row[0],
            'direction' => $row[1],
            'buy_ask' => $row[2],
            'sell_bid' => $row[3],
            'quantity' => $row[4],
            'cost_usdt' => $row[5],
            'gross_percent' => $row[6],
            'net_percent' => $row[7],
            'profit_usdt' => $row[8],
        ]);

        if ($this->option('execute') && $row[9] === 'SIGNAL') {
            try {
                $execution = $executionService->execute($row[0], $row[1], (float) $row[4]);
                $realized = $execution['realized'];
                Log::channel('profitable_trades')->info('Actual arbitrage trade', [
                    'pair' => $row[0],
                    'direction' => $row[1],
                    'quantity' => $realized['quantity'],
                    'buy_average_price' => $execution['buy']['average_price'],
                    'sell_average_price' => $execution['sell']['average_price'],
                    'buy_amount' => $realized['buy_amount'],
                    'sell_amount' => $realized['sell_amount'],
                    'buy_fee_quote' => $execution['buy']['fee_quote'],
                    'sell_fee_quote' => $execution['sell']['fee_quote'],
                    'profit_quote' => $realized['profit_quote'],
                    'profit_percent' => $realized['profit_percent'],
                ]);
                Log::channel('profitable_trades')->info('Realized arbitrage profitability', [
                    'pair' => $row[0],
                    'direction' => $row[1],
                    ...$realized,
                ]);
                $this->info(sprintf(
                    'Executed %s %s: real profit %.8f (%.4f%%), qty %.8f',
                    $row[1], $row[0], $realized['profit_quote'],
                    $realized['profit_percent'], $realized['quantity']
                ));
            } catch (Throwable $e) {
                $this->error('Execution failed: ' . $e->getMessage());
            }
        }
    }

    private function getQuote(
        string $exchange,
        string $pair,
        float  $maxAge,
        float  $now
    ): ?array
    {
        try {
            $raw = Redis::get("market:top:{$exchange}:{$pair}");
        } catch (Throwable $e) {
            $this->error('Redis read failed: ' . $e->getMessage());

            return null;
        }

        if (!is_string($raw)) {
            return null;
        }

        $quote = json_decode($raw, true);

        if (!is_array($quote)) {
            return null;
        }

        foreach (['bid', 'ask', 'bid_qty', 'ask_qty', 'received_at'] as $field) {
            if (
                !isset($quote[$field])
                || !is_numeric($quote[$field])
            ) {
                return null;
            }
        }

        $bid = (float)$quote['bid'];
        $ask = (float)$quote['ask'];
        $bidQty = (float)$quote['bid_qty'];
        $askQty = (float)$quote['ask_qty'];
        $age = $now - (float)$quote['received_at'];

        if (
            $bid <= 0
            || $ask <= 0
            || $bidQty <= 0
            || $askQty <= 0
            || $ask < $bid
            || $age < 0
            || $age > $maxAge
        ) {
            return null;
        }

        return [
            'bid' => $bid,
            'ask' => $ask,
            'bid_qty' => $bidQty,
            'ask_qty' => $askQty,
            'age' => $age,
        ];
    }



    private function calculateRoute(
        string $pair,
        string $direction,
        array $buy,
        array $sell,
        float $buyFee,
        float $sellFee,
        float $threshold,
        float $buyQuoteBalance,
        float $sellInventoryBalance,
        int $pairCount
    ): array {
        $buyPrice = $buy['ask'];
        $sellPrice = $sell['bid'];

        $buyCostPerUnit = $buyPrice * (1 + $buyFee);
        $sellRevenuePerUnit = $sellPrice * (1 - $sellFee);

        // Reserve the available inventory across the tracked pairs.
        $inventoryQty = $sellInventoryBalance / max(1, $pairCount);

        // Available quantity for each side of the trade.
        $capitalQty = $buyQuoteBalance / $buyCostPerUnit;
        $qty = min(
            $capitalQty,
            $inventoryQty,
            $buy['ask_qty'],
            $sell['bid_qty']
        );

        $invested = $qty * $buyCostPerUnit;
        $profit = $qty * ($sellRevenuePerUnit - $buyCostPerUnit);

        $grossPct = (($sellPrice / $buyPrice) - 1) * 100;
        $netPct = (($sellRevenuePerUnit / $buyCostPerUnit) - 1) * 100;

        $status = $netPct >= $threshold
            ? 'SIGNAL'
            : 'NO EDGE';

        return [
            $pair,
            $direction,
            number_format($buyPrice, 8, '.', ''),
            number_format($sellPrice, 8, '.', ''),
            number_format($qty, 8, '.', ''),
            number_format($invested, 4, '.', ''),
            number_format($grossPct, 4, '.', '').'%',
            number_format($netPct, 4, '.', '').'%',
            number_format($profit, 4, '.', ''),
            $status,
        ];
    }


}
