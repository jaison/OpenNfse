<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\NbsPost;
use PHPUnit\Framework\TestCase;

final class NbsPostTest extends TestCase
{
    public function testCampoAusentePreservaNbsPadrao(): void
    {
        $this->assertSame('115031000', NbsPost::padrao([], '115031000'));
        $this->assertSame('115031000', NbsPost::padrao(['codigo_servico' => '010701'], '115.031.000'));
    }

    public function testCampoVazioExplicitoApagaNbsPadrao(): void
    {
        $this->assertNull(NbsPost::padrao(['nbs_padrao' => ''], '115031000'));
        $this->assertSame('115031000', NbsPost::padrao(['nbs_padrao' => '115031000'], null));
    }

    public function testNbsDeGrupoAusentePreservaEVazioExplicitoNao(): void
    {
        $this->assertSame('115031000', NbsPost::doGrupo([], 7, '115031000'));
        $this->assertSame('', NbsPost::doGrupo([7 => ''], 7, '115031000'));
        $this->assertSame('115061000', NbsPost::doGrupo(['7' => '115061000'], 7, '115031000'));
    }
}
