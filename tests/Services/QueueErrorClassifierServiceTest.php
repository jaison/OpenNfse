<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Exceptions\NfseValidationException;
use OpenNfse\Services\QueueErrorClassifierService;
use PHPUnit\Framework\TestCase;

final class QueueErrorClassifierServiceTest extends TestCase
{
    private QueueErrorClassifierService $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new QueueErrorClassifierService();
    }

    public function testValidationExceptionIsNeverRetryable(): void
    {
        $e = new NfseValidationException('qualquer mensagem aqui');
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public function testEmptyMessageIsRetryable(): void
    {
        $this->assertTrue($this->classifier->isRetryable(new NfseModuleException('')));
    }

    public function testGenericTechnicalErrorIsRetryable(): void
    {
        $e = new NfseModuleException('Timeout ao conectar com o serviço da prefeitura.');
        $this->assertTrue($this->classifier->isRetryable($e));
    }

    /**
     * @dataProvider nonRetryableMessagesProvider
     */
    public function testNonRetryablePatternsAreNotRetryable(string $message): void
    {
        $e = new NfseModuleException($message);
        $this->assertFalse($this->classifier->isRetryable($e));
    }

    public static function nonRetryableMessagesProvider(): array
    {
        return [
            'configuração não encontrada' => ['Configuração do módulo não encontrada.'],
            'cpf/cnpj não informado' => ['CPF/CNPJ do tomador não informado.'],
            'cnpj inválido' => ['CNPJ do emissor inválido para cancelamento.'],
            'gateway desativado' => ['Emissão desativada para o gateway de pagamento desta fatura.'],
            'nota não encontrada' => ['Nota não encontrada para esta fatura.'],
            'motivo mínimo' => ['Motivo do cancelamento inválido (mínimo 15 caracteres).'],
            'motivo máximo' => ['Motivo do cancelamento inválido (máximo 255 caracteres).'],
            'só pode quando paga' => ['A emissão só pode ser solicitada quando a fatura estiver como Paid.'],
            'rejeitada' => ['NFS-e rejeitada pela prefeitura.'],
            'sem chave' => ['Chave de acesso não encontrada para cancelamento.'],
            'sem id dps' => ['Nota sem chave e sem ID DPS para consulta.'],
        ];
    }

    public function testStringErrorIsClassified(): void
    {
        $this->assertFalse($this->classifier->isRetryable('Campo obrigatório ausente.'));
        $this->assertTrue($this->classifier->isRetryable('Erro inesperado de rede.'));
    }

    public function testCaseInsensitiveMatching(): void
    {
        $e = new NfseModuleException('CONFIGURAÇÃO NÃO ENCONTRADA');
        $this->assertFalse($this->classifier->isRetryable($e));
    }
}
