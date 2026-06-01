<?php

/**
 * Temporary test script — AI divergence service live test.
 * Run: /usr/local/bin/php84 bin/test_ai.php
 * Remove after verification.
 */

if (PHP_SAPI !== 'cli') { exit(1); }

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/vendor/autoload.php';

$envFile = ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $_ENV[trim($parts[0])] = trim($parts[1]);
    }
}

$aiConfig = require ROOT_PATH . '/config/ai.php';

use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClientFactory;

$client  = ClaudeClientFactory::fromConfig($aiConfig);
$service = new AiDivergenceService($client);

// Synthetic test data — no real API call to Yahoo
$cvsResult = [
    'ticker'       => 'NVDA',
    'quality_gate' => true,
    'swing'        => ['cvs' => 42.0, 'recommendation' => 'NEUTRALNIE'],
    'fundamental'  => ['cvs' => 71.0, 'recommendation' => 'AKUMULUJ'],
    'golden_signal'=> 'watchlist',
    'pillar_scores'=> ['valuation' => 28.0, 'momentum_swing' => 85.0, 'momentum_fund' => 62.0, 'quality' => 72.0],
    'gate_failures'=> [],
];

$financials = [
    'sector'        => 'Technology',
    'current_price' => 875.0,
    'forecast' => [
        'targets'             => ['mean' => 950.0, 'low' => 800.0, 'high' => 1100.0, 'upside' => 0.086],
        'recommendation_mean' => 1.6,
        'num_analysts'        => 52,
        'latest' => ['strong_buy' => 28, 'buy' => 16, 'hold' => 6, 'sell' => 1, 'strong_sell' => 1],
        'trend'               => [],
    ],
];

echo "Calling Claude API for NVDA divergence analysis...\n\n";
$result = $service->generate('NVDA', $cvsResult, $financials);

if ($result->ok) {
    echo "=== AI ANALYSIS ===\n\n";
    echo $result->text . "\n\n";
    echo "=== USAGE ===\n";
    echo "Input tokens:  " . ($result->usage?->inputTokens ?? 'N/A') . "\n";
    echo "Output tokens: " . ($result->usage?->outputTokens ?? 'N/A') . "\n";
    echo "Cache creation tokens: " . ($result->usage?->cacheCreationInputTokens ?? 0) . "\n";
    echo "Cache read tokens: " . ($result->usage?->cacheReadInputTokens ?? 0) . "\n";
} else {
    echo "FAILED: " . $result->failureMessage . "\n";
    echo "Kind: " . ($result->failureKind?->value ?? 'unknown') . "\n";
}
