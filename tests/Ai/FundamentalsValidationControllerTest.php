<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\FundamentalsValidationController;
use CVS\Ai\FundamentalsValidationRunRepository;
use CVS\Alerts\AlertService;
use CVS\Api\FinancialDataFetcher;
use CVS\Api\FundamentalOverrideRepository;
use CVS\Auth\UserRepository;
use CVS\TrackRecord\SingleTickerRescorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for trigger()/status()/confirm() — change: fundamentals-validation.
 *
 * Same constraint as AiAnalysisControllerCriticalReviewTest: every path
 * through these methods ends in Response::json(), which calls exit() by
 * default (src/Core/Response.php:32-41) — invoking them directly would kill
 * the PHPUnit process, not just fail a test. Coverage strategy mirrors that
 * file exactly:
 *   (1) Structural: methods exist with the correct signature.
 *   (2) Route: all three endpoints are registered in routes.php.
 *   (3) Constructor: injecting all six test doubles skips the heavy
 *       production wiring (CVSModel/SnapshotWriter/AlertService/
 *       SingleTickerRescorer) — no real DB hit.
 *   (4) Repository/service-level gateways (isPending, findByTicker status
 *       shapes, upsert casting) are covered in
 *       FundamentalsValidationRunRepositoryTest / FundamentalOverrideRepositoryTest
 *       / FundamentalOverrideMergerTest — not duplicated here.
 *
 * End-to-end HTTP behaviour (status codes, admin gate, CSRF, exec() firing,
 * confirm's full pipeline) is verified manually (see plan.md Phase 4 Manual
 * Verification).
 */
class FundamentalsValidationControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // (1) Structural: methods exist with the correct signature
    // ------------------------------------------------------------------

    #[DataProvider('methodNamesProvider')]
    public function test_method_exists_and_is_public(string $methodName): void
    {
        $rc = new ReflectionClass(FundamentalsValidationController::class);
        $this->assertTrue($rc->hasMethod($methodName));

        $method = $rc->getMethod($methodName);
        $this->assertTrue($method->isPublic());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('req', $params[0]->getName());

        $return = $method->getReturnType();
        $this->assertNotNull($return);
        $this->assertSame('void', (string) $return);
    }

    /** @return array<int, array{0: string}> */
    public static function methodNamesProvider(): array
    {
        return [['trigger'], ['status'], ['confirm']];
    }

    // ------------------------------------------------------------------
    // (2) Route: all three endpoints are registered in routes.php
    // ------------------------------------------------------------------

    public function test_routes_are_registered_in_routes_php(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/src/Core/routes.php';
        $this->assertFileExists($routesFile);
        $contents = file_get_contents($routesFile);
        $this->assertIsString($contents);

        $this->assertStringContainsString(
            "post('/analysis/{ticker}/validate-fundamentals'",
            $contents,
            'routes.php must register POST /analysis/{ticker}/validate-fundamentals'
        );
        $this->assertStringContainsString(
            "get('/analysis/{ticker}/validate-fundamentals/status'",
            $contents,
            'routes.php must register GET /analysis/{ticker}/validate-fundamentals/status'
        );
        $this->assertStringContainsString(
            "post('/analysis/{ticker}/validate-fundamentals/confirm'",
            $contents,
            'routes.php must register POST /analysis/{ticker}/validate-fundamentals/confirm'
        );
    }

    // ------------------------------------------------------------------
    // (3) Constructor: injected test doubles skip production wiring
    // ------------------------------------------------------------------

    public function test_constructor_with_all_injected_deps_leaves_rescorer_and_alert_service_as_given(): void
    {
        $rescorer     = $this->createMock(SingleTickerRescorer::class);
        $alertService = $this->createMock(AlertService::class);

        $controller = new FundamentalsValidationController(
            $this->createMock(FundamentalsValidationRunRepository::class),
            $this->createMock(FundamentalOverrideRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(FinancialDataFetcher::class),
            $rescorer,
            $alertService
        );

        $rc = new ReflectionClass($controller);

        $rescorerProp = $rc->getProperty('rescorer');
        $rescorerProp->setAccessible(true);
        $this->assertSame($rescorer, $rescorerProp->getValue($controller));

        $alertProp = $rc->getProperty('alertService');
        $alertProp->setAccessible(true);
        $this->assertSame($alertService, $alertProp->getValue($controller));
    }

    // Deliberately NO "constructor with zero injected deps" test: that branch
    // calls PeerBucketOverrideRepository::findBucketMap(), which hits
    // Database::connection() eagerly (unlike the other collaborators, which
    // only connect lazily on first query) — there is no offline-safe way to
    // exercise it in this test suite, same reasoning
    // AiAnalysisControllerCriticalReviewTest applies to its own "no deps"
    // branch (never tested there either).
}
