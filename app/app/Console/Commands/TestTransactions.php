<?php

namespace App\Console\Commands;

use App\Services\ArbitrageExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestTransactions extends Command
{
    protected $signature = 'market:test-transactions
                            {--execute : Submit real market orders; without this flag only validate the plan}
                            {--quantity= : Base asset quantity for every route}';

    protected $description = 'Test both arbitrage directions for every configured currency pair';

    public function handle(ArbitrageExecutionService $executionService): int
    {
        $pairs = config('luma.pairs', []);
        $quantity = (float) ($this->option('quantity') ?? config('luma.test_transaction_quantity', 0));

        if (!is_array($pairs) || $pairs === []) {
            $this->error('No currency pairs configured.');
            return self::FAILURE;
        }

        if ($quantity <= 0) {
            $this->error('Quantity must be greater than zero.');
            return self::FAILURE;
        }

        $routes = [];
        foreach ($pairs as $pair) {
            foreach (['Binance → Kraken', 'Kraken → Binance'] as $direction) {
                $routes[] = [$pair, $direction];
            }
        }

        $this->info(sprintf(
            'Transaction test: %d routes, quantity %.8f, mode: %s',
            count($routes),
            $quantity,
            $this->option('execute') ? 'LIVE' : 'DRY-RUN'
        ));

        if (!$this->option('execute')) {
            $this->table(['Pair', 'Direction', 'Quantity', 'Status'], array_map(
                static fn (array $route): array => [$route[0], $route[1], number_format($quantity, 8, '.', ''), 'READY'],
                $routes
            ));
            $this->warn('No orders were sent. Add --execute to test with real market orders.');
            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($routes as [$pair, $direction]) {
            try {
                $result = $executionService->test($pair, $direction, $quantity);
                $this->info(sprintf(
                    'OK %s %s (filled %.8f)',
                    $pair,
                    $direction,
                    $result['realized']['quantity']
                ));
            } catch (Throwable $e) {
                $failed++;
                Log::error('Transaction test route failed', [
                    'pair' => $pair,
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $this->error(sprintf('FAILED %s %s: %s', $pair, $direction, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $this->error(sprintf('%d of %d transaction routes failed.', $failed, count($routes)));
            return self::FAILURE;
        }

        $this->info('All transaction routes completed successfully.');
        return self::SUCCESS;
    }
}
