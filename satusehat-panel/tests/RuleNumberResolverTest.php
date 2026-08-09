<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Util\RuleNumberResolver;

/**
 * RuleNumberResolver: official Indonesian descriptions resolve from the
 * generated dictionary; unknown numbers degrade gracefully.
 */
final class RuleNumberResolverTest extends TestCase
{
    public function testKnownRulesResolve(): void
    {
        $this->assertNotNull(RuleNumberResolver::message(20002));
        $this->assertStringContainsString('duplikasi', RuleNumberResolver::message(20002));
        $this->assertNotNull(RuleNumberResolver::message(10393));
        $this->assertNotNull(RuleNumberResolver::message(10464));
    }

    public function testUnknownRuleReturnsNullAndDescribes(): void
    {
        $this->assertNull(RuleNumberResolver::message(999999));
        $this->assertSame('RuleNumber 999999', RuleNumberResolver::describe(999999));
        $this->assertStringStartsWith('RuleNumber 20002:', RuleNumberResolver::describe(20002));
    }

    public function testDictionaryIsLargeEnough(): void
    {
        $this->assertGreaterThan(500, count(RuleNumberResolver::all()));
    }
}
