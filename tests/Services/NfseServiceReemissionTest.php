<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\NfseService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class NfseServiceReemissionTest extends TestCase
{
    private const CANCEL_FIELDS = [
        'cancelado_em',
        'cancel_xml_path',
        'cancel_codigo_motivo',
        'cancel_motivo',
        'cancel_descricao',
        'cancel_erro',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    private function invoke(string $method, ...$args)
    {
        $service = new NfseService();
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }

    private function notaCancelada(): array
    {
        return [
            'id' => 7,
            'invoiceid' => 151900,
            'userid' => 3,
            'status' => 'CANCELADA',
            'numero_nf' => '41',
            'id_dps' => 'DPS_ANTIGA',
            'protocolo' => 'DPS_ANTIGA',
            'chave_acesso' => 'CHAVE_ANTIGA',
            'xml_path' => 'nfse/2026/10/nfse_41_151900_101010.xml',
            'cancel_xml_path' => 'nfse/2026/10/cancelamento_nfse_41_151900_20261010_111111.xml',
            'competencia' => '2026-10-10',
            'emitida_em' => '2026-10-10 10:10:10',
            'cancelado_em' => '2026-10-10 11:11:11',
            'cancel_codigo_motivo' => '1',
            'cancel_motivo' => 'Erro na emissão - valor incorreto',
            'cancel_descricao' => 'Cancelamento de NFS-e',
            'cancel_erro' => null,
        ];
    }

    public function testProcessingUpsertClearsCancellationOfPreviousNota(): void
    {
        $data = $this->invoke('buildProcessingUpsertData', 151900, 3, 'DPS_NOVA', false);

        $this->assertSame('PROCESSANDO', $data['status']);
        $this->assertSame('DPS_NOVA', $data['id_dps']);
        $this->assertNull($data['chave_acesso']);
        $this->assertNull($data['xml_path']);
        $this->assertSame(0, $data['e2404_reemit_attempts']);
        foreach (self::CANCEL_FIELDS as $field) {
            $this->assertArrayHasKey($field, $data, $field);
            $this->assertNull($data[$field], $field);
        }
    }

    public function testProcessingUpsertKeepsE2404CounterWhenResendingSameDps(): void
    {
        $data = $this->invoke('buildProcessingUpsertData', 151900, 3, 'DPS_NOVA', true);

        $this->assertArrayNotHasKey('e2404_reemit_attempts', $data);
        foreach (self::CANCEL_FIELDS as $field) {
            $this->assertNull($data[$field], $field);
        }
    }

    public function testReemissionRecordsCancelledNotaInHistoryWhenMissing(): void
    {
        $this->invoke('preserveCancellationBeforeReemission', $this->notaCancelada());

        $inserts = Capsule::$inserts['mod_opennfse_notas_history'] ?? [];
        $this->assertCount(1, $inserts);
        $record = $inserts[0];
        $this->assertSame('CANCELAMENTO', $record['tipo_registro']);
        $this->assertSame('CANCELADA', $record['status_fiscal']);
        $this->assertSame('CHAVE_ANTIGA', $record['chave_acesso']);
        $this->assertSame('nfse/2026/10/nfse_41_151900_101010.xml', $record['xml_path']);
        $this->assertSame('nfse/2026/10/cancelamento_nfse_41_151900_20261010_111111.xml', $record['cancel_xml_path']);
        $this->assertSame('2026-10-10 11:11:11', $record['cancelado_em']);
        $this->assertNotSame('', (string) $record['fingerprint']);
    }

    public function testReemissionDoesNotDuplicateCancellationAlreadyInHistory(): void
    {
        Capsule::$rows['mod_opennfse_notas_history'] = [
            (object) ['id' => 1, 'tipo_registro' => 'CANCELAMENTO', 'chave_acesso' => 'CHAVE_ANTIGA'],
        ];

        $this->invoke('preserveCancellationBeforeReemission', $this->notaCancelada());

        $this->assertSame([], Capsule::$inserts['mod_opennfse_notas_history'] ?? []);
    }

    public function testCancellationOfAnotherKeyDoesNotPreventRecording(): void
    {
        Capsule::$rows['mod_opennfse_notas_history'] = [
            (object) ['id' => 1, 'tipo_registro' => 'CANCELAMENTO', 'chave_acesso' => 'OUTRA_CHAVE', 'fingerprint' => 'outro'],
            (object) ['id' => 2, 'tipo_registro' => 'EMISSAO', 'chave_acesso' => 'CHAVE_ANTIGA', 'fingerprint' => 'emissao'],
        ];

        $this->invoke('preserveCancellationBeforeReemission', $this->notaCancelada());

        $inserts = Capsule::$inserts['mod_opennfse_notas_history'] ?? [];
        $this->assertCount(1, $inserts);
        $this->assertSame('CHAVE_ANTIGA', $inserts[0]['chave_acesso']);
    }

    /**
     * Falha de cancelamento deixa motivo e erro com status EMITIDA: não é cancelamento.
     *
     * @dataProvider notCancelledProvider
     */
    public function testReemissionIgnoresRowsThatAreNotCancelled(?array $nota): void
    {
        $this->invoke('preserveCancellationBeforeReemission', $nota);

        $this->assertSame([], Capsule::$inserts['mod_opennfse_notas_history'] ?? []);
    }

    public static function notCancelledProvider(): array
    {
        return [
            'sem nota' => [null],
            'falha de cancelamento' => [[
                'invoiceid' => 151900,
                'status' => 'EMITIDA',
                'chave_acesso' => 'CHAVE',
                'cancelado_em' => null,
                'cancel_codigo_motivo' => '1',
                'cancel_erro' => 'E0840 evento rejeitado',
            ]],
            'rejeitada com campos herdados' => [[
                'invoiceid' => 151900,
                'status' => 'REJEITADA',
                'chave_acesso' => null,
                'cancelado_em' => '2026-10-10 11:11:11',
                'cancel_xml_path' => 'nfse/cancelamento.xml',
            ]],
        ];
    }
}
