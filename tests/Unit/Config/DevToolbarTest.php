<?php

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

/**
 * The Symfony debug toolbar is off by default in dev (docs/UX-REVIEW.md, fix 3): the jury runs
 * the dev environment, and the toolbar covers the bottom of every phone page, where the booking
 * bars sit. A developer turns it on with PROFILER_TOOLBAR=1 in .env.local; /_profiler stays.
 */
final class DevToolbarTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function testTheToolbarFollowsAnEnvironmentVariable(): void
    {
        $config = Yaml::parseFile(self::ROOT.'/config/packages/web_profiler.yaml');

        self::assertSame('%env(bool:PROFILER_TOOLBAR)%', $config['when@dev']['web_profiler']['toolbar']);
    }

    public function testTheVariableIsOffByDefault(): void
    {
        $values = (new Dotenv())->parse((string) file_get_contents(self::ROOT.'/.env'));

        self::assertArrayHasKey('PROFILER_TOOLBAR', $values);
        self::assertSame('0', $values['PROFILER_TOOLBAR']);
    }
}
