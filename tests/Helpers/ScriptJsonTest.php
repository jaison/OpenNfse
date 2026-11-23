<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\ScriptJson;
use PHPUnit\Framework\TestCase;

final class ScriptJsonTest extends TestCase
{
    public function testByteInvalidoViraJsonValido(): void
    {
        $json = ScriptJson::encode([
            '010701' => ['115031000' => "Serviço \xB1 nacional"],
        ]);

        $this->assertNotFalse(json_decode($json, true));
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('115031000', $decoded['010701']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['010701']['115031000']);
    }

    public function testEscapaFechamentoDeScript(): void
    {
        $json = ScriptJson::encode('</script><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringContainsString('\\u003C', $json);
        $this->assertSame('</script><img src=x onerror=alert(1)>', json_decode($json));
    }

    public function testFalhaDeCodificacaoDevolveLiteralVazio(): void
    {
        $this->assertSame('{}', ScriptJson::encode([NAN]));
        $this->assertSame('""', ScriptJson::encode(NAN));
    }
}
