<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views\Dashboard;

use OpenNfse\Views\Dashboard\DashboardFeed;
use PHPUnit\Framework\TestCase;

final class DashboardFeedTest extends TestCase
{
    private const EMITIDAS = [
        ['invoiceid' => 151836, 'numero_nf' => '1867', 'data' => '2026-10-31 23:10:00', 'companyname' => '[Nome do cliente]'],
        ['invoiceid' => 151945, 'numero_nf' => '1866', 'data' => '2026-10-31 21:41:00', 'companyname' => '', 'firstname' => '[Nome', 'lastname' => 'do cliente]'],
    ];

    private const CANCELADAS = [
        ['invoiceid' => 151964, 'numero_nf' => '1846', 'data' => '2026-10-30 16:34:00', 'companyname' => '[Nome do cliente]'],
    ];

    private const ISSUES = [
        [
            'invoiceid' => 151593,
            'status' => 'RESOLVIDO',
            'erro' => 'Client error: `POST https://sefin.example/x` resulted in a `400 Bad Request` response',
            'data' => '2026-10-31 22:00:00',
            'companyname' => '[Nome do cliente]',
        ],
    ];

    public function testMergeSortsByDateDesc(): void
    {
        $items = DashboardFeed::merge(self::EMITIDAS, self::CANCELADAS, self::ISSUES);

        $this->assertSame(['emitida', 'erro', 'emitida', 'cancelada'], array_column($items, 'type'));
        $this->assertSame('NFS-e 1867 emitida', $items[0]['title']);
        $this->assertSame('Erro na requisição: 400 Bad Request — resolvido', $items[1]['title']);
        $this->assertSame('NFS-e 1846 cancelada', $items[3]['title']);
        $this->assertSame(151964, $items[3]['invoiceid']);
        $this->assertSame('[Nome do cliente]', $items[2]['client']);
    }

    public function testFilterAndLimit(): void
    {
        $this->assertSame(['cancelada'], array_column(DashboardFeed::merge(self::EMITIDAS, self::CANCELADAS, self::ISSUES, 'canceladas'), 'type'));
        $this->assertSame(['erro'], array_column(DashboardFeed::merge(self::EMITIDAS, self::CANCELADAS, self::ISSUES, 'erros'), 'type'));
        $this->assertSame(['emitida', 'emitida'], array_column(DashboardFeed::merge(self::EMITIDAS, self::CANCELADAS, self::ISSUES, 'emitidas'), 'type'));
        $this->assertCount(2, DashboardFeed::merge(self::EMITIDAS, self::CANCELADAS, self::ISSUES, 'tudo', 2));

        $many = array_fill(0, 15, self::EMITIDAS[0]);
        $this->assertCount(DashboardFeed::LIMIT, DashboardFeed::merge($many, [], []));
    }

    public function testFilterValidation(): void
    {
        $this->assertSame('tudo', DashboardFeed::filter(''));
        $this->assertSame('tudo', DashboardFeed::filter('<x>'));
        $this->assertSame('erros', DashboardFeed::filter('erros'));
        $this->assertSame(['emitidas' => false, 'canceladas' => true, 'erros' => false], DashboardFeed::sources('canceladas'));
    }

    public function testIssueTitles(): void
    {
        $this->assertSame('Rejeitada: E0312 CNAE inválido', DashboardFeed::issueTitle(['status' => 'REJEITADA', 'erro' => "E0312   CNAE inválido"]));
        $this->assertSame('Erro sem mensagem', DashboardFeed::issueTitle(['status' => 'ERROR', 'erro' => '']));
        $this->assertSame('Falha - sefin.example', DashboardFeed::normalizeIssueMessage('Falha for url https://sefin.example/api/x'));
        $this->assertSame(110, mb_strlen(DashboardFeed::normalizeIssueMessage(str_repeat('a', 200))));
    }

    public function testNotaWithoutNumber(): void
    {
        $items = DashboardFeed::merge([['invoiceid' => 1, 'numero_nf' => '', 'data' => '2026-10-01 10:00:00']], [], []);
        $this->assertSame('NFS-e emitida', $items[0]['title']);
        $this->assertSame('-', $items[0]['client']);
    }
}
