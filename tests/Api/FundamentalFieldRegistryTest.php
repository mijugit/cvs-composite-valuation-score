<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FundamentalFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry is the single source of truth SuspectFieldDetector,
 * FundamentalOverrideMerger, and FundamentalsValidationService all read from
 * — these tests guard against the lists silently drifting apart internally
 * (e.g. a field added to SCORING_FIELDS without a matching FIELD_TYPES entry
 * would silently fail to merge back after validation).
 */
class FundamentalFieldRegistryTest extends TestCase
{
    public function test_every_scoring_field_has_a_type(): void
    {
        foreach (FundamentalFieldRegistry::SCORING_FIELDS as $field) {
            $this->assertArrayHasKey($field, FundamentalFieldRegistry::FIELD_TYPES, "SCORING_FIELDS entry '{$field}' has no FIELD_TYPES entry");
        }
    }

    public function test_every_expected_non_null_field_has_a_type(): void
    {
        foreach (FundamentalFieldRegistry::EXPECTED_NON_NULL as $field) {
            $this->assertArrayHasKey($field, FundamentalFieldRegistry::FIELD_TYPES, "EXPECTED_NON_NULL entry '{$field}' has no FIELD_TYPES entry");
        }
    }

    public function test_every_locally_computed_field_has_a_type(): void
    {
        foreach (FundamentalFieldRegistry::LOCALLY_COMPUTED as $field) {
            $this->assertArrayHasKey($field, FundamentalFieldRegistry::FIELD_TYPES, "LOCALLY_COMPUTED entry '{$field}' has no FIELD_TYPES entry");
        }
    }

    public function test_every_earnings_derived_field_has_a_type(): void
    {
        foreach (FundamentalFieldRegistry::EARNINGS_DATE_FIELDS as $derivedField) {
            $this->assertArrayHasKey($derivedField, FundamentalFieldRegistry::FIELD_TYPES, "Derived field '{$derivedField}' has no FIELD_TYPES entry");
        }
    }

    public function test_scoring_fields_has_no_duplicates(): void
    {
        $this->assertCount(count(array_unique(FundamentalFieldRegistry::SCORING_FIELDS)), FundamentalFieldRegistry::SCORING_FIELDS);
    }

    public function test_locally_computed_fields_are_not_sent_to_scoring_fields_llm_prompt(): void
    {
        // moving_average_200 must never be requested from Gemini — it is
        // resolved locally. Guards against it accidentally being added to
        // SCORING_FIELDS in the future.
        $this->assertNotContains('moving_average_200', FundamentalFieldRegistry::SCORING_FIELDS);
    }

    public function test_field_types_are_only_int_or_float(): void
    {
        foreach (FundamentalFieldRegistry::FIELD_TYPES as $field => $type) {
            $this->assertContains($type, ['int', 'float'], "FIELD_TYPES entry '{$field}' has an unexpected type '{$type}'");
        }
    }
}
