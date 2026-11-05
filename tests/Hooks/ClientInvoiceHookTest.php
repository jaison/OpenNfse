<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Hooks;

use OpenNfse\Hooks\ClientInvoiceHook;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * Bloco da NFS-e na fatura da área do cliente: aparece só com a nota EMITIDA,
 * com o banco simulado pelo stub do Capsule.
 */
final class ClientInvoiceHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
        unset($_REQUEST['nfse_email'], $_REQUEST['id']);
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        unset($_REQUEST['nfse_email']);
        parent::tearDown();
    }

    private function database(string $status, array $extra = []): void
    {
        Capsule::$rows['tblinvoices'] = [(object) ['id' => 500, 'userid' => 7, 'status' => 'Paid']];
        Capsule::$rows['mod_opennfse_notas'] = [(object) ($extra + [
            'id' => 1,
            'invoiceid' => 500,
            'status' => $status,
            'numero_nf' => '123',
            'emitida_em' => '2026-11-01 10:30:00',
            'cancelado_em' => '2026-11-02 09:00:00',
            'chave_acesso' => '35123456789012345678901234567890123456789012345678',
            'xml_path' => '/fora/do/webroot/nota.xml',
            'erro_api' => 'E0001 - falha técnica',
        ])];
    }

    private function render(): array
    {
        return (new ClientInvoiceHook())->capturePageContext([
            'filename' => 'viewinvoice',
            'invoiceid' => 500,
            'userid' => 7,
            'payto' => 'Empresa',
        ]);
    }

    public function testNotaEmitidaMostraOBloco(): void
    {
        $this->database('EMITIDA');

        $payto = $this->render()['payto'] ?? '';

        self::assertStringStartsWith('Empresa<div id="nfse-client-invoice-hook"', $payto);
        self::assertStringContainsString('alert-success', $payto);
        self::assertStringContainsString('<span class="nfse-status-ok">EMITIDA</span>', $payto);
        self::assertStringContainsString('<strong>Número:</strong> 123', $payto);
        self::assertStringContainsString('<strong>Emitida em:</strong> 01/11/2026 10:30', $payto);
        self::assertStringContainsString('nfse-copy-btn', $payto);
        self::assertStringContainsString('Baixar XML', $payto);
        self::assertStringContainsString('Baixar PDF', $payto);
        self::assertStringContainsString('Enviar por e-mail', $payto);
        self::assertStringNotContainsString('Detalhe', $payto);
        self::assertStringNotContainsString('E0001', $payto);
        self::assertStringNotContainsString('Cancelada em', $payto);
    }

    public function testNotaEmitidaComEspacosNoStatusMostraOBloco(): void
    {
        $this->database(' EMITIDA ');

        self::assertArrayHasKey('payto', $this->render());
    }

    public function testNotaEmitidaSemXmlNaoMostraOsBotoes(): void
    {
        $this->database('EMITIDA', ['xml_path' => '']);

        $payto = $this->render()['payto'] ?? '';

        self::assertStringContainsString('<strong>Número:</strong> 123', $payto);
        self::assertStringNotContainsString('Baixar XML', $payto);
    }

    public function testAvisoDoEmailContinuaAparecendo(): void
    {
        $this->database('EMITIDA');
        $_REQUEST['nfse_email'] = 'done';

        self::assertStringContainsString('E-mail enviado com XML e PDF anexados.', $this->render()['payto'] ?? '');
    }

    /** @return array<string, array{0: string}> */
    public static function statusNaoEmitidos(): array
    {
        return [
            'erro' => ['ERRO'],
            'rejeitada' => ['REJEITADA'],
            'processando' => ['PROCESSANDO'],
            'cancelada' => ['CANCELADA'],
            'vazio' => [''],
            'minúsculo' => ['emitida'],
            'desconhecido' => ['OUTRO'],
        ];
    }

    /** @dataProvider statusNaoEmitidos */
    public function testNotaNaoEmitidaNaoMostraNada(string $status): void
    {
        $this->database($status);

        self::assertSame([], $this->render());
    }

    public function testFaturaSemNotaNaoMostraNada(): void
    {
        Capsule::$rows['tblinvoices'] = [(object) ['id' => 500, 'userid' => 7]];

        self::assertSame([], $this->render());
    }
}
