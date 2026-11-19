<?php

namespace ErnestDefoe\Docket\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The stylesheets compile against this Flarum's LESS. A variable that core
 * no longer defines is a 500 on every page, and nothing else here would
 * notice.
 */
class AssetsTest extends TestCase
{
    public static function frontends(): array
    {
        return [['forum'], ['admin']];
    }

    #[Test]
    #[DataProvider('frontends')]
    public function the_stylesheet_compiles(string $frontend): void
    {
        $this->extension('flarum-flags', 'flarum-tags', 'ernestdefoe-docket');

        $css = $this->app()->getContainer()->make("flarum.assets.$frontend")->makeCss();
        $css->commit(true);

        $disk = $this->app()->getContainer()->make('filesystem')->disk('flarum-assets');
        $this->assertStringContainsString('.Docket', $disk->get($css->getFilename()));
    }
}
