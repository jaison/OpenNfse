<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Controllers;

use OpenNfse\Controllers\ConfigController;
use PHPUnit\Framework\TestCase;

/**
 * Opções do código de serviço em Configurações (Tributação e mapeamento por grupo).
 */
final class ConfigControllerServiceCodeOptionsTest extends TestCase
{
    /** Monta as opções como ServiceNbsCatalogRepository::getServiceOptions(). */
    private function options(bool $includeDefault, array $codigos): array
    {
        $options = $includeDefault ? ['' => 'Padrão'] : [];
        foreach ($codigos as $codigo) {
            $options[$codigo] = $codigo;
        }

        return $options;
    }

    public function testCodigoSemZeroAEsquerdaViraChaveIntEFicaSelecionado(): void
    {
        $options = $this->options(false, ['010701', '140101']);
        $this->assertSame(['010701', 140101], array_keys($options), 'O PHP guarda "140101" como chave int.');

        $html = ConfigController::renderServiceCodeOptions(['' => 'Selecione'] + $options, '140101');

        $this->assertStringContainsString('<option value="140101" selected>140101</option>', $html);
        $this->assertStringContainsString('<option value="">Selecione</option>', $html);
        $this->assertStringContainsString('<option value="010701">010701</option>', $html);
        $this->assertSame(1, substr_count($html, ' selected'));
    }

    public function testCodigoComZeroAEsquerdaContinuaSelecionado(): void
    {
        $html = ConfigController::renderServiceCodeOptions($this->options(false, ['010701', '140101']), '010701');

        $this->assertStringContainsString('<option value="010701" selected>010701</option>', $html);
        $this->assertStringContainsString('<option value="140101">140101</option>', $html);
        $this->assertSame(1, substr_count($html, ' selected'));
    }

    public function testMapeamentoPorGrupoMarcaOCodigoSalvoOuPadrao(): void
    {
        $options = $this->options(true, ['010701', '140101']);

        $html = ConfigController::renderServiceCodeOptions($options, '140101');
        $this->assertStringContainsString('<option value="140101" selected>140101</option>', $html);
        $this->assertStringContainsString('<option value="">Padrão</option>', $html);

        $html = ConfigController::renderServiceCodeOptions($options, '010701');
        $this->assertStringContainsString('<option value="010701" selected>010701</option>', $html);

        $html = ConfigController::renderServiceCodeOptions($options, '');
        $this->assertStringContainsString('<option value="" selected>Padrão</option>', $html);
        $this->assertSame(1, substr_count($html, ' selected'));
    }

    public function testValorForaDoCatalogoAcrescentadoComoChaveIntTambemFicaSelecionado(): void
    {
        $options = $this->options(true, ['010701']);
        $options['140101'] = '140101 (Atual fora do catálogo)';

        $html = ConfigController::renderServiceCodeOptions($options, '140101');
        $this->assertStringContainsString('<option value="140101" selected>140101 (Atual fora do catálogo)</option>', $html);
    }

    public function testEscapaValoresERotulos(): void
    {
        $html = ConfigController::renderServiceCodeOptions(['a"b' => '<x>'], 'a"b');
        $this->assertSame('<option value="a&quot;b" selected>&lt;x&gt;</option>', $html);
    }
}
