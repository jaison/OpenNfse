<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Views\Dashboard\DashboardHealth;
use PHPUnit\Framework\TestCase;

final class DashboardHealthTest extends TestCase
{
    private const URLS = [
        'erros' => 'falhas-url',
        'rejeitadas' => 'rejeitadas-url',
        'aguardando_status' => 'wait-url',
        'pendentes' => 'fila-url',
        'auditoria' => 'auditoria-url',
        'certificado' => 'cert-url',
        'customfield' => 'cf-url',
        'alertas' => 'alertas-url',
    ];

    private const ZERO = ['erros' => 0, 'pendentes' => 0, 'rejeitadas' => 0, 'aguardando_status' => 0, 'nao_emitidas' => 0];

    private const CERT_OK = ['status' => 'ok', 'days_left' => 200, 'valid_to' => '20/05/2027 10:00'];

    public function testAllZeroShowsSingleOkItem(): void
    {
        $items = DashboardHealth::items(self::ZERO, self::CERT_OK, false, 0, self::URLS);

        $this->assertCount(1, $items);
        $this->assertSame('ok', $items[0]['tone']);
        $this->assertSame('Operação em dia', $items[0]['title']);
        $this->assertSame('0 erros · 0 pendentes · 0 rejeitadas · 0 aguardando status · 0 faturas pagas sem nota', $items[0]['text']);
        $this->assertSame('', $items[0]['action']);
    }

    public function testPendingOnlyIsWarnAndListsOnlyNonZero(): void
    {
        $items = DashboardHealth::items(['pendentes' => 3, 'aguardando_status' => 1] + self::ZERO, self::CERT_OK, false, 0, self::URLS);

        $this->assertCount(1, $items);
        $this->assertSame('warn', $items[0]['tone']);
        $this->assertSame('3 pendentes · 1 aguardando status', $items[0]['title']);
        $this->assertSame(
            [['label' => '3 pendentes', 'href' => 'fila-url'], ['label' => '1 aguardando status', 'href' => 'wait-url']],
            $items[0]['parts']
        );
    }

    public function testErrorsAndRejectedAreCritical(): void
    {
        $items = DashboardHealth::items(['erros' => 1, 'rejeitadas' => 2] + self::ZERO, self::CERT_OK, false, 0, self::URLS);

        $this->assertSame('danger', $items[0]['tone']);
        $this->assertSame('1 erro · 2 rejeitadas', $items[0]['title']);
        $this->assertSame(['falhas-url', 'rejeitadas-url'], array_column($items[0]['parts'], 'href'));
    }

    public function testCountersDoNotRepeatTheSameNote(): void
    {
        // 1 rejeitada (também conta em com_erro_periodo) e 1 aguardando status (também conta em pendentes).
        $counters = DashboardHealth::counters(
            ['com_erro_periodo' => 1, 'rejeitadas' => 1, 'pendentes' => 1, 'aguardando_status' => 1],
            0
        );
        $this->assertSame(
            ['erros' => 0, 'pendentes' => 0, 'rejeitadas' => 1, 'aguardando_status' => 1, 'nao_emitidas' => 0],
            $counters
        );

        $items = DashboardHealth::items($counters, self::CERT_OK, false, 0, self::URLS);
        $this->assertSame('1 rejeitada · 1 aguardando status', $items[0]['title']);

        $this->assertSame(
            ['erros' => 2, 'pendentes' => 3, 'rejeitadas' => 1, 'aguardando_status' => 2, 'nao_emitidas' => 5],
            DashboardHealth::counters(['com_erro_periodo' => 3, 'rejeitadas' => 1, 'pendentes' => 5, 'aguardando_status' => 2], 5)
        );
        $this->assertSame(0, DashboardHealth::counters(['com_erro_periodo' => 0, 'rejeitadas' => 2], 0)['erros'], 'nunca negativo');
    }

    public function testNotIssuedHasOwnItemAndRemovesOk(): void
    {
        $items = DashboardHealth::items(['nao_emitidas' => 4] + self::ZERO, self::CERT_OK, false, 0, self::URLS);

        $this->assertCount(1, $items);
        $this->assertSame('warn', $items[0]['tone']);
        $this->assertSame('4 faturas pagas sem nota', $items[0]['title']);
        $this->assertSame('auditoria-url', $items[0]['href']);
    }

    public function testCertificateWithin30Days(): void
    {
        $items = DashboardHealth::items(self::ZERO, ['status' => 'expiring', 'days_left' => 28, 'valid_to' => '03/12/2026 10:00'], false, 0, self::URLS);

        $this->assertCount(2, $items);
        $this->assertSame('danger', $items[1]['tone']);
        $this->assertSame('Certificado vence em 28 dias', $items[1]['title']);
        $this->assertSame('03/12/2026 · as emissões param no vencimento', $items[1]['text']);
        $this->assertSame('cert-url', $items[1]['href']);

        $this->assertNotNull(DashboardHealth::certificateItem(['status' => 'expiring', 'days_left' => 30], 'x'));
        $this->assertNull(DashboardHealth::certificateItem(['status' => 'ok', 'days_left' => 31], 'x'));
        $this->assertSame('Certificado vence em 1 dia', DashboardHealth::certificateItem(['status' => 'critical', 'days_left' => 1], 'x')['title']);
        $this->assertSame('Certificado vence hoje', DashboardHealth::certificateItem(['status' => 'critical', 'days_left' => 0], 'x')['title']);
    }

    public function testCertificateExpired(): void
    {
        $item = DashboardHealth::certificateItem(['status' => 'expired', 'days_left' => -3, 'valid_to' => '01/11/2026 09:00'], 'cert-url');

        $this->assertSame('danger', $item['tone']);
        $this->assertSame('Certificado vencido', $item['title']);
        $this->assertSame('Venceu em 01/11/2026 · as emissões estão paradas', $item['text']);
    }

    public function testCertificateMissingOrUnreadableIsWarn(): void
    {
        $missing = DashboardHealth::certificateItem(['status' => 'missing', 'days_left' => ''], 'cert-url');
        $this->assertSame('warn', $missing['tone']);
        $this->assertSame('Certificado não configurado', $missing['title']);
        $this->assertSame('cert-url', $missing['href']);

        $error = DashboardHealth::certificateItem(['status' => 'error'], 'cert-url');
        $this->assertSame('warn', $error['tone']);
        $this->assertSame('Não foi possível ler o certificado', $error['title']);

        $items = DashboardHealth::items(self::ZERO, ['status' => 'error'], false, 0, self::URLS);
        $this->assertSame(['Operação em dia', 'Não foi possível ler o certificado'], array_column($items, 'title'));

        $this->assertNull(DashboardHealth::certificateItem([], 'x'), 'sem dados do certificado, nada a mostrar');
    }

    public function testCertificateValidLine(): void
    {
        $this->assertSame('Certificado válido até 20/05/2027', DashboardHealth::certificateValidLine(self::CERT_OK));
        $this->assertSame('', DashboardHealth::certificateValidLine(['status' => 'expiring', 'days_left' => 30, 'valid_to' => '05/12/2026 10:00']));
        $this->assertSame('', DashboardHealth::certificateValidLine(['status' => 'expired', 'days_left' => -1, 'valid_to' => '01/11/2026 10:00']));
        $this->assertSame('', DashboardHealth::certificateValidLine(['status' => 'missing', 'days_left' => '']));
        $this->assertSame('', DashboardHealth::certificateValidLine(['status' => 'ok', 'days_left' => 200, 'valid_to' => '']));
        $this->assertSame('', DashboardHealth::certificateValidLine([]));
    }

    public function testCustomFieldAndAlerts(): void
    {
        $items = DashboardHealth::items(self::ZERO, self::CERT_OK, true, 2, self::URLS);

        $this->assertCount(3, $items);
        $this->assertSame('warn', $items[1]['tone']);
        $this->assertSame(EmissionPolicyService::MSG_CUSTOMFIELD_MISSING, $items[1]['text']);
        $this->assertSame('cf-url', $items[1]['href']);
        $this->assertSame('2 alertas de NFS-e abertos', $items[2]['title']);
    }
}
