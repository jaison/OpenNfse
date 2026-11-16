<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Updater;

use OpenNfse\Controllers\UpdaterController;
use PHPUnit\Framework\TestCase;

final class PauseSettleStatusTest extends TestCase
{
    public function testPayloadReportsWhetherThePauseHasSettled(): void
    {
        $this->assertSame(['settled' => false], UpdaterController::settleStatusPayload(false));
        $this->assertSame(['settled' => true], UpdaterController::settleStatusPayload(true));
    }

    public function testWaitingPauseCountsDownAndOffersAManualLink(): void
    {
        $html = UpdaterController::pauseWaitingHtml(false);

        $this->assertStringContainsString('Aguardando o processamento em andamento terminar.', $html);
        $this->assertStringContainsString('id="nfse-pause-countdown"', $html);
        $this->assertStringContainsString('Atualizando em 10 s…', $html);
        $this->assertStringContainsString('>Atualizar agora<', $html);
        $this->assertStringContainsString('href="addonmodules.php?module=OpenNfse&amp;action=updater"', $html);
        $this->assertStringContainsString('action=updaterSettleStatus', $html);
        $this->assertStringContainsString(
            'window.location.assign("addonmodules.php?module=OpenNfse&action=updater")',
            $html
        );
        $this->assertStringNotContainsString('http-equiv="refresh"', $html);
        $this->assertStringNotContainsString('location.reload', $html);
    }

    public function testSettledPauseDoesNotPoll(): void
    {
        $html = UpdaterController::pauseWaitingHtml(true);

        $this->assertStringContainsString('Pausa ativa. Nenhum processamento em andamento.', $html);
        $this->assertStringNotContainsString('updaterSettleStatus', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
