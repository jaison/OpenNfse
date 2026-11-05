<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Views\Dashboard\DashboardHealth;
use PHPUnit\Framework\TestCase;

final class DashboardHealthTest extends TestCase
{
    private const URLS = [
        'fila' => 'fila-url',
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
        $this->assertSame('Ver na fila', $items[0]['action']);
        $this->assertSame('fila-url', $items[0]['href']);
    }

    public function testErrorsAndRejectedAreCritical(): void
    {
        $items = DashboardHealth::items(['erros' => 1, 'rejeitadas' => 2] + self::ZERO, self::CERT_OK, false, 0, self::URLS);

        $this->assertSame('danger', $items[0]['tone']);
        $this->assertSame('1 erro · 2 rejeitadas', $items[0]['title']);
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

    public function testCertificateMissingOrUnreadableAddsNothing(): void
    {
        $this->assertNull(DashboardHealth::certificateItem(['status' => 'missing', 'days_left' => ''], 'x'));
        $this->assertNull(DashboardHealth::certificateItem(['status' => 'error'], 'x'));
        $this->assertNull(DashboardHealth::certificateItem([], 'x'));
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
