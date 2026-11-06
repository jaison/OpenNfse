<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\WebRoot;
use PHPUnit\Framework\TestCase;

final class WebRootTest extends TestCase
{
    public function testInsideAndOutside(): void
    {
        $root = sys_get_temp_dir() . '/opennfse-webroot-' . bin2hex(random_bytes(4));
        mkdir($root . '/public_html/sub', 0755, true);
        mkdir($root . '/public_html_old', 0755);
        try {
            $roots = [$root . '/public_html'];
            $this->assertTrue(WebRoot::contains($root . '/public_html/sub', $roots));
            $this->assertTrue(WebRoot::contains($root . '/public_html', $roots));
            $this->assertFalse(WebRoot::contains($root . '/public_html_old', $roots));
            $this->assertFalse(WebRoot::contains($root . '/nao-existe', $roots));
        } finally {
            rmdir($root . '/public_html/sub');
            rmdir($root . '/public_html');
            rmdir($root . '/public_html_old');
            rmdir($root);
        }
    }
}
