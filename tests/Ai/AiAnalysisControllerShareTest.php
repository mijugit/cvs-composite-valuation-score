<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiAnalysisController;
use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiDivergenceService;
use CVS\Ai\ExportPromptBuilder;
use CVS\Api\FinancialDataFetcher;
use CVS\CVS\CVSModel;
use CVS\Core\Request;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for the sharePrompt() endpoint.
 *
 * AiAnalysisController actions call Response::json() which exits the process,
 * so we cannot test through the full HTTP handler in unit tests (same constraint
 * as every other controller in this project).
 *
 * Coverage strategy:
 *   (1) Structural: sharePrompt() exists with the correct signature.
 *   (2) Route: share-prompt is registered in routes.php.
 *   (3) Repository gateway: the "no cached analysis" path drives a 409.
 *       Verified by confirming findByTicker() returns null when no row exists
 *       — the exact condition the controller branches on.
 *   (4) Prompt content: when analysis IS present, the assembled prompt
 *       contains the ticker and differs by language — using ExportPromptBuilder
 *       (the same object the controller instantiates) with a synthetic data block.
 *
 * End-to-end HTTP behaviour (status codes, session, CSRF) is verified manually
 * after deployment to Cyber_Folks (see plan.md § 2.4-2.6).
 */
class AiAnalysisControllerShareTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_analyses (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker        TEXT    NOT NULL UNIQUE,
                content       TEXT    NOT NULL,
                model         TEXT    NULL,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by  INTEGER NULL,
                generated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return $pdo;
    }

    // ------------------------------------------------------------------
    // (1) Structural: method exists with the correct signature
    // ------------------------------------------------------------------

    public function test_share_prompt_method_exists_and_is_public(): void
    {
        $rc = new ReflectionClass(AiAnalysisController::class);
        $this->assertTrue(
            $rc->hasMethod('sharePrompt'),
            'AiAnalysisController must have a sharePrompt() method'
        );

        $method = $rc->getMethod('sharePrompt');
        $this->assertTrue($method->isPublic());
    }

    public function test_share_prompt_accepts_request_and_returns_void(): void
    {
        $rc     = new ReflectionClass(AiAnalysisController::class);
        $method = $rc->getMethod('sharePrompt');

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('req', $params[0]->getName());

        $return = $method->getReturnType();
        $this->assertNotNull($return);
        $this->assertSame('void', (string) $return);
    }

    // ------------------------------------------------------------------
    // (2) Route: share-prompt is registered in routes.php
    // ------------------------------------------------------------------

    public function test_share_prompt_route_is_registered_in_routes_php(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/src/Core/routes.php';
        $this->assertFileExists($routesFile);
        $contents = file_get_contents($routesFile);
        $this->assertIsString($contents);
        $this->assertStringContainsString(
            '/analysis/{ticker}/share-prompt',
            $contents,
            'routes.php must register POST /analysis/{ticker}/share-prompt'
        );
    }

    // ------------------------------------------------------------------
    // (3) Repository gateway: no cached analysis → the row is absent
    //     (this is the exact condition that causes the controller to 409)
    // ------------------------------------------------------------------

    public function test_repo_returns_null_when_no_analysis_cached(): void
    {
        $repo = new AiAnalysisRepository($this->makePdo());
        $this->assertNull(
            $repo->findByTicker('AAPL'),
            'No cached analysis must return null — controller maps this to 409'
        );
    }

    public function test_repo_returns_row_when_analysis_exists(): void
    {
        $repo = new AiAnalysisRepository($this->makePdo());
        $repo->save('AAPL', 'Analiza testowa', 'claude-test', 100, 200, 1);

        $row = $repo->findByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertSame('Analiza testowa', $row['content']);
    }

    // ------------------------------------------------------------------
    // (4) Prompt content: ticker + PL/EN differ
    //     Mirrors what sharePrompt() does after the repo check passes.
    // ------------------------------------------------------------------

    private function syntheticDataBlock(): string
    {
        return "TICKER: AAPL\nCVS SWING: 72.5/100\nCVS FUNDAMENTAL: 64.0/100\nSECTOR: Technology";
    }

    private function syntheticAnalysis(): string
    {
        return "Model CVS wskazuje na solidne fundamenty.\nMomentum umiarkowane.";
    }

    public function test_assembled_pl_prompt_contains_ticker(): void
    {
        $builder = new ExportPromptBuilder();
        $prompt  = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'pl');
        $this->assertStringContainsString('AAPL', $prompt);
    }

    public function test_assembled_en_prompt_contains_ticker(): void
    {
        $builder = new ExportPromptBuilder();
        $prompt  = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'en');
        $this->assertStringContainsString('AAPL', $prompt);
    }

    public function test_assembled_pl_prompt_contains_cached_analysis(): void
    {
        $builder  = new ExportPromptBuilder();
        $analysis = $this->syntheticAnalysis();
        $prompt   = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $analysis, 'pl');
        $this->assertStringContainsString($analysis, $prompt);
    }

    public function test_pl_and_en_prompts_differ(): void
    {
        $builder = new ExportPromptBuilder();
        $pl = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'pl');
        $en = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'en');
        $this->assertNotSame($pl, $en);
    }

    public function test_pl_prompt_contains_polish_section_header(): void
    {
        $builder = new ExportPromptBuilder();
        $prompt  = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'pl');
        $this->assertStringContainsString('TWOJE ZADANIA', $prompt);
    }

    public function test_en_prompt_contains_english_section_header(): void
    {
        $builder = new ExportPromptBuilder();
        $prompt  = $builder->build('AAPL', 'Technology', $this->syntheticDataBlock(), $this->syntheticAnalysis(), 'en');
        $this->assertStringContainsString('YOUR TASKS', $prompt);
    }

    // ------------------------------------------------------------------
    // (5) Constructor with all four deps injected does not hit MySQL
    // ------------------------------------------------------------------

    public function test_constructor_with_all_injected_deps_does_not_need_real_db(): void
    {
        $this->expectNotToPerformAssertions();

        // All four deps injected → constructor skips ProGate/DB initialization.
        $controller = new AiAnalysisController(
            [],
            new AiAnalysisRepository($this->makePdo()),
            $this->createMock(FinancialDataFetcher::class),
            $this->createMock(CVSModel::class),
            $this->createMock(AiDivergenceService::class)
        );
        unset($controller);
    }
}
