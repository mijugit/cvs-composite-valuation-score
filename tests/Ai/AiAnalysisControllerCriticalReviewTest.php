<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiAnalysisController;
use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiDivergenceService;
use CVS\Api\FinancialDataFetcher;
use CVS\CVS\CVSModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for criticalReview() (POST, starts background job) and
 * criticalReviewStatus() (GET, polls the job) — change: cvs-ai-critical-review.
 *
 * Same constraint as AiAnalysisControllerShareTest: these actions call
 * Response::json(), which exits the process, so we cannot invoke them
 * end-to-end in a unit test. Coverage strategy mirrors that file:
 *   (1) Structural: methods exist with the correct signature.
 *   (2) Route: both endpoints are registered in routes.php.
 *   (3) Constructor: injecting test doubles skips the critical-review
 *       repository too (no real DB hit), same gating as gate/usageRepo.
 *   (4) Repository gateway: exact conditions the controller branches on
 *       (isPending/isFresh/status shapes) are covered in
 *       AiCriticalReviewRepositoryTest — not duplicated here.
 *
 * End-to-end HTTP behaviour (status codes, session, CSRF, exec() firing) is
 * verified manually after deployment to Cyber_Folks (see plan.md Phase 3).
 */
class AiAnalysisControllerCriticalReviewTest extends TestCase
{
    // ------------------------------------------------------------------
    // (1) Structural: methods exist with the correct signature
    // ------------------------------------------------------------------

    public function test_critical_review_method_exists_and_is_public(): void
    {
        $rc = new ReflectionClass(AiAnalysisController::class);
        $this->assertTrue($rc->hasMethod('criticalReview'));

        $method = $rc->getMethod('criticalReview');
        $this->assertTrue($method->isPublic());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('req', $params[0]->getName());

        $return = $method->getReturnType();
        $this->assertNotNull($return);
        $this->assertSame('void', (string) $return);
    }

    public function test_critical_review_status_method_exists_and_is_public(): void
    {
        $rc = new ReflectionClass(AiAnalysisController::class);
        $this->assertTrue($rc->hasMethod('criticalReviewStatus'));

        $method = $rc->getMethod('criticalReviewStatus');
        $this->assertTrue($method->isPublic());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('req', $params[0]->getName());

        $return = $method->getReturnType();
        $this->assertNotNull($return);
        $this->assertSame('void', (string) $return);
    }

    // ------------------------------------------------------------------
    // (2) Route: both endpoints are registered in routes.php
    // ------------------------------------------------------------------

    public function test_critical_review_routes_are_registered_in_routes_php(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/src/Core/routes.php';
        $this->assertFileExists($routesFile);
        $contents = file_get_contents($routesFile);
        $this->assertIsString($contents);
        $this->assertStringContainsString(
            "post('/analysis/{ticker}/critical-review'",
            $contents,
            'routes.php must register POST /analysis/{ticker}/critical-review'
        );
        $this->assertStringContainsString(
            "get('/analysis/{ticker}/critical-review/status'",
            $contents,
            'routes.php must register GET /analysis/{ticker}/critical-review/status'
        );
    }

    /**
     * Guards against an accidental route-shape change: the `provider` param
     * (change: critical-review-models) travels as a request param, not a
     * path segment, so no new route was added for it — still exactly the
     * same 2 critical-review routes as before.
     */
    public function test_exactly_two_critical_review_routes_are_registered(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/src/Core/routes.php';
        $contents   = file_get_contents($routesFile);
        $this->assertIsString($contents);

        $count = substr_count($contents, "/analysis/{ticker}/critical-review'")
            + substr_count($contents, "/analysis/{ticker}/critical-review/status'");

        $this->assertSame(2, $count, 'Expected exactly 2 critical-review route registrations');
    }

    /**
     * Regression guard for a real production bug (2026-08-25): `provider`
     * was read via `Request::param()`, which ONLY reads {ticker}-style
     * route params — never POST body or query string. Every "Gemini"
     * trigger/poll silently fell back to the 'claude' default instead,
     * so the Gemini worker was never invoked and its poll always returned
     * Claude's row. `provider` must be read via input() (POST body, in
     * criticalReview()) and query() (query string, in criticalReviewStatus()) —
     * never via param() for this key.
     */
    public function test_provider_param_is_read_via_input_and_query_never_via_param(): void
    {
        $controllerFile = dirname(__DIR__, 2) . '/src/Ai/AiAnalysisController.php';
        $contents       = file_get_contents($controllerFile);
        $this->assertIsString($contents);

        $this->assertStringNotContainsString(
            "\$req->param('provider'",
            $contents,
            "Request::param() only reads route-path params — 'provider' is a POST body/query value, not a route segment."
        );
        $this->assertStringContainsString("\$req->input('provider'", $contents, 'criticalReview() must read provider via input() (POST body).');
        $this->assertStringContainsString("\$req->query('provider'", $contents, 'criticalReviewStatus() must read provider via query() (query string).');
    }

    // ------------------------------------------------------------------
    // (3) Constructor: injected test doubles skip the critical-review repo too
    // ------------------------------------------------------------------

    public function test_constructor_with_injected_deps_leaves_critical_review_repo_null(): void
    {
        $controller = new AiAnalysisController(
            [],
            $this->createMock(AiAnalysisRepository::class),
            $this->createMock(FinancialDataFetcher::class),
            $this->createMock(CVSModel::class),
            $this->createMock(AiDivergenceService::class)
        );

        $rc   = new ReflectionClass($controller);
        $prop = $rc->getProperty('criticalReviewRepo');
        $prop->setAccessible(true);

        $this->assertNull(
            $prop->getValue($controller),
            'criticalReviewRepo must stay null when test doubles are injected — no real DB hit'
        );
    }
}
