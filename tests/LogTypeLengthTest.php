<?php

declare(strict_types=1);

namespace OpenNfse\Tests;

use PHPUnit\Framework\TestCase;

/**
 * mod_opennfse_logs.tipo é VARCHAR(30): um tipo maior falha no MySQL estrito, e o catch que
 * envolve o log engole o erro. Nenhum literal em MAIÚSCULAS_COM_UNDERLINE do src/ pode passar disso.
 */
final class LogTypeLengthTest extends TestCase
{
    public function testNoUppercaseLiteralInSrcIsLongerThanLogTypeColumn(): void
    {
        $root = dirname(__DIR__) . '/src';
        $tooLong = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/'([A-Z][A-Z0-9_]{30,})'/", $content, $m)) {
                foreach ($m[1] as $literal) {
                    $tooLong[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $literal;
                }
            }
        }

        $this->assertSame([], $tooLong, 'Tipos de log com mais de 30 caracteres (mod_opennfse_logs.tipo é VARCHAR(30)).');
    }
}
