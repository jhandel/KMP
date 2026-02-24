<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Services\ContainerRegistryService;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

class ContainerRegistryServiceTest extends TestCase
{
    public function testLimitToPrimaryChannelHeadsKeepsOnlyReleaseDevNightly(): void
    {
        $service = new ContainerRegistryService();
        $method = new ReflectionMethod(ContainerRegistryService::class, 'limitToPrimaryChannelHeads');
        $method->setAccessible(true);

        $input = [
            ['tag' => 'v1.4.97', 'channel' => 'release'],
            ['tag' => 'v1.4.96', 'channel' => 'release'],
            ['tag' => 'dev-1.4.97', 'channel' => 'dev'],
            ['tag' => 'dev-1.4.96', 'channel' => 'dev'],
            ['tag' => 'nightly-2026-02-23', 'channel' => 'nightly'],
            ['tag' => 'nightly-2026-02-22', 'channel' => 'nightly'],
            ['tag' => 'v1.0.0-rc1', 'channel' => 'beta'],
        ];

        /** @var array<int, array{tag:string, channel:string}> $filtered */
        $filtered = $method->invoke($service, $input);

        $this->assertCount(3, $filtered);
        $this->assertSame('v1.4.97', $filtered[0]['tag']);
        $this->assertSame('dev-1.4.97', $filtered[1]['tag']);
        $this->assertSame('nightly-2026-02-23', $filtered[2]['tag']);
    }

    public function testIsUpgradeCandidateRequiresHigherSemverWhenKnown(): void
    {
        $service = new ContainerRegistryService();
        $method = new ReflectionMethod(ContainerRegistryService::class, 'isUpgradeCandidate');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '1.4.98', '1.4.97', false));
        $this->assertFalse($method->invoke($service, '1.4.94', '1.4.97', false));
        $this->assertFalse($method->invoke($service, '1.4.97', '1.4.97', true));
    }

    public function testExtractNextTagsPageUrlHandlesRelativeLink(): void
    {
        $service = new ContainerRegistryService();
        $method = new ReflectionMethod(ContainerRegistryService::class, 'extractNextTagsPageUrl');
        $method->setAccessible(true);

        $url = $method->invoke(
            $service,
            '</v2/jhandel/kmp/tags/list?n=100&last=dev-1.4.94>; rel="next"',
            'ghcr.io',
        );

        $this->assertSame(
            'https://ghcr.io/v2/jhandel/kmp/tags/list?n=100&last=dev-1.4.94',
            $url,
        );
    }

    public function testExtractNextTagsPageUrlReturnsNullWhenNoNext(): void
    {
        $service = new ContainerRegistryService();
        $method = new ReflectionMethod(ContainerRegistryService::class, 'extractNextTagsPageUrl');
        $method->setAccessible(true);

        $url = $method->invoke($service, '', 'ghcr.io');

        $this->assertNull($url);
    }
}
