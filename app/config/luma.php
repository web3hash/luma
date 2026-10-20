<?php

return [
    'binance_quote_asset' => strtoupper((string) env('LUMA_BINANCE_QUOTE_ASSET', 'USDT')),
    'kraken_quote_asset' => strtoupper((string) env('LUMA_KRAKEN_QUOTE_ASSET', 'USD')),
    'reserve_percent' => (float) env('LUMA_RESERVE_PERCENT', 10),
    'balance_refresh_seconds' => (int) env('LUMA_BALANCE_REFRESH_SECONDS', 60),
    'binance_websocket_url' => env(
        'LUMA_BINANCE_WEBSOCKET_URL',
        'wss://stream.binance.com:9443/stream'
    ),

    'kraken_websocket_url' => env(
        'LUMA_KRAKEN_WEBSOCKET_URL',
        'wss://ws.kraken.com/v2'
    ),

    // Базовые активы через запятую. Котировки задаются отдельно для бирж.
    'pairs' => array_values(array_filter(array_map(
        static fn (string $asset): string => strtoupper(trim($asset)) . '/'
            . strtoupper((string) env('LUMA_BINANCE_QUOTE_ASSET', 'USDT')),
        explode(',', (string) env(
            'LUMA_PAIRS',
            'BTC,ETH,LINK'
        ))
    ))),

    // Минимальная чистая доходность для сигнала, в процентах.
    'min_net_spread_pct' => (float)env(
        'LUMA_MIN_NET_SPREAD_PCT',
        0.3
    ),

    // Максимальный возраст котировки в секундах.
    'max_quote_age_seconds' => (float)env(
        'LUMA_MAX_QUOTE_AGE_SECONDS',
        5
    ),

    'refresh_seconds' => (int)env(
        'LUMA_REFRESH_SECONDS',
        2
    ),

    'capital_usdt' => (float)env(
        'LUMA_CAPITAL_USDT',
        100
    ),

    // Show all configured pairs without requiring funds on either exchange.
    'show_all_pairs' => filter_var(
        env('LUMA_SHOW_ALL_PAIRS', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    'execution_cooldown_seconds' => (int) env('LUMA_EXECUTION_COOLDOWN_SECONDS', 10),

    // Quantity used by market:test-transactions unless --quantity is supplied.
    'test_transaction_quantity' => (float) env('LUMA_TEST_TRANSACTION_QUANTITY', 0.0001),

    'api_limits' => [
        'binance' => [
            'requests_per_minute' => (int) env('LUMA_BINANCE_REQUESTS_PER_MINUTE', 100),
        ],
        'kraken' => [
            'requests_per_minute' => (int) env('LUMA_KRAKEN_REQUESTS_PER_MINUTE', 60),
        ],
    ],

];
