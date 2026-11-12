<?php

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Symfony debug toolbar is off in dev (docs/UX-REVIEW.md, fix 3): the jury runs the dev
 * environment, and the toolbar covers the bottom of every phone page, where the booking bars sit.
 * It cannot follow an environment variable: the bundle decides at container compile time.
 * A developer sets it to true in config/packages/web_profiler.yaml; /_profiler stays available.
 */
final class DevToolbarTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function testTheToolbarIsOffInDev(): void
    {
        $config = Yaml::parseFile(self::ROOT.'/config/packages/web_profiler.yaml');

        self::assertFalse($config['when@dev']['web_profiler']['toolbar']);
    }

    public function testTheProfilerItselfStaysOnInDev(): void
    {
        $config = Yaml::parseFile(self::ROOT.'/config/packages/web_profiler.yaml');

        self::assertIsArray($config['when@dev']['framework']['profiler'] ?? null, 'the profiler still collects, /_profiler still works');
    }
}
