<?php

namespace App\Console\Commands;

use App\Services\ExchangeBalanceService;
use App\Services\ExchangeFeeService;
use Illuminate\Console\Command;
use Throwable;

class RefreshBalances extends Command
{
    protected $signature = 'market:refresh-balances';

    protected $description = 'Fetch and display available exchange balances';

    public function handle(
        ExchangeBalanceService $service,
        ExchangeFeeService $feeService
    ): int
    {
        foreach (['binance', 'kraken'] as $exchange) {
            try {
                $balances = $service->refresh($exchange);
            } catch (Throwable $e) {
                $this->error(sprintf(
                    '%s: balance request failed: %s',
                    ucfirst($exchange),
                    $e->getMessage()
                ));

                continue;
            }

            $rows = [];
            foreach ($balances as $asset => $amount) {
                if ((float) $amount > 0) {
                    $rows[] = [$asset, number_format((float) $amount, 12, '.', '')];
                }
            }

            $this->info(ucfirst($exchange));
            $this->table(['Asset', 'Available after reserve'], $rows);
        }

        $feeRows = [];
        foreach (config('luma.pairs', []) as $pair) {
            foreach (['binance', 'kraken'] as $exchange) {
                try {
                    $fee = $feeService->get($exchange, $pair);
                    $feeRows[] = [
                        ucfirst($exchange),
                        $pair,
                        $fee === null
                            ? 'unavailable: ' . ($feeService->lastError() ?? 'unknown error')
                            : number_format($fee * 100, 4, '.', '') . '%',
                    ];
                } catch (Throwable $e) {
                    $feeRows[] = [ucfirst($exchange), $pair, 'error'];
                    $this->warn(sprintf(
                        '%s fee request failed: %s',
                        ucfirst($exchange),
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->info('Personal taker fees');
        $this->table(['Exchange', 'Pair', 'Taker fee'], $feeRows);

        return self::SUCCESS;
    }
}
