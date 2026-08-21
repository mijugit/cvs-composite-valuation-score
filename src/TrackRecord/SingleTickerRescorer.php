<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\Ai\FairPriceCalculator;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\MedianResolver;
use CVS\Execution\AtrZoneCalculator;

/**
 * Rescores and persists a single ticker with full parity to bin/rescore.php's
 * per-ticker body (score, snapshot, ATR zone, alert digest) — change:
 * fundamentals-validation.
 *
 * A faithful parallel extraction of bin/rescore.php:204-252's logic, NOT a
 * refactor of that script — bin/rescore.php is left untouched (see plan.md's
 * Non-Goals/Guardrails: the daily batch must not change). Both this class and
 * bin/rescore.php independently call the same underlying collaborators
 * (CVSModel, SnapshotWriter, FairPriceCalculator, AtrZoneCalculator,
 * AlertService) in the same order, so the two call sites can never disagree
 * about the scoring pipeline itself — only about what triggers a run.
 *
 * Caller responsibilities the constructor does NOT take on: fetching
 * $financials, merging overrides (FundamentalOverrideMerger), running
 * PayloadCompleteness, and calling AlertService::flushDigests() once after
 * rescore() returns — flushDigests() is a batch-sender, not meant to fire
 * per-ticker (mirrors bin/rescore.php:257's single end-of-run call).
 */
final class SingleTickerRescorer
{
    /**
     * @param array<string, mixed>  $atrZonesConfig
     * @param array<string, string> $peerBucketOverrides ticker (uppercase) => bucket_key
     */
    public function __construct(
        private readonly CVSModel $model,
        private readonly SnapshotWriter $writer,
        private readonly MedianResolver $medianResolver,
        private readonly PriceAlertRepository $priceAlertRepo,
        private readonly AlertService $alertService,
        private readonly array $atrZonesConfig,
        private readonly array $peerBucketOverrides = [],
    ) {
    }

    /**
     * @param array<string, mixed> $financials       already fetched AND already merged with
     *                                                any confirmed fundamental_overrides
     * @param array<string, mixed> $cvsWeightsConfig config/cvs-weights.php contents
     */
    public function rescore(string $ticker, array $financials, array $cvsWeightsConfig): SingleTickerRescoreResult
    {
        $ovr = $this->peerBucketOverrides[strtoupper($ticker)] ?? null;
        if ($ovr !== null && $ovr !== '') {
            $financials['peer_bucket_override'] = $ovr;
        }

        $result = $this->model->calculate($ticker, $financials);

        $price          = isset($financials['current_price'])   ? (float)  $financials['current_price']   : null;
        $sector         = isset($financials['sector'])          ? (string) $financials['sector']          : null;
        $industry       = isset($financials['industry'])        ? (string) $financials['industry']        : null;
        $companyName    = isset($financials['long_name'])       ? (string) $financials['long_name']       : null;
        $fxRateToUsd    = isset($financials['fx_rate_to_usd'])  ? (float)  $financials['fx_rate_to_usd']  : null;
        $nativeCurrency = isset($financials['native_currency']) ? (string) $financials['native_currency'] : null;
        $nativePrice    = isset($financials['native_price'])    ? (float)  $financials['native_price']    : null;

        $fairValue = FairPriceCalculator::compute($financials, $cvsWeightsConfig, $this->medianResolver);

        $this->writer->persist(
            $result,
            $price,
            $sector,
            $industry,
            CvsSnapshotRepository::ORIGIN_RESCORE,
            $companyName,
            $fxRateToUsd,
            $nativeCurrency,
            $nativePrice,
            $fairValue
        );

        $zone = null;
        if ($price !== null && !empty($financials['daily_ohlc'])) {
            $zone = AtrZoneCalculator::compute($financials['daily_ohlc'], $price, $this->atrZonesConfig);
            if ($zone['has_zone']) {
                $this->priceAlertRepo->upsertZone($ticker, $zone, $fxRateToUsd);
            }
        }

        $this->alertService->checkAndNotify($ticker, $result->toArray(), $companyName, $price, $zone);

        return new SingleTickerRescoreResult($result->qualityGatePassed, $result, $fairValue);
    }
}
