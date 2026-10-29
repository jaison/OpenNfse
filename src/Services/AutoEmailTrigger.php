<?php

declare(strict_types=1);

namespace OpenNfse\Services;

/**
 * Encadeia o disparo do e-mail automático em volta da emissão e da consulta do NfseService.
 *
 * - O disparo é avaliado num finally, depois que a operação termina, com ou sem exceção: se a nota
 *   já foi gravada como EMITIDA e uma escrita seguinte falhar, o e-mail não se perde.
 * - Na emissão, o disparo acontece depois de a operação liberar o lock da fatura.
 * - Quando a consulta reenvia a DPS por emitir() (503 ou E2404), quem dispara é a emissão;
 *   a consulta não dispara de novo.
 *
 * Nenhum método engole a exceção da operação: ela sobe normalmente depois do disparo.
 */
final class AutoEmailTrigger
{
    /** @var callable(int, string): void */
    private $fire;

    private bool $emissionInvoked = false;

    /**
     * @param (callable(int, string): void)|null $fire recebe invoiceId e correlationId
     */
    public function __construct(?callable $fire = null)
    {
        $this->fire = $fire ?? static function (int $invoiceId, string $correlationId): void {
            (new AutoEmailService())->onNotaEmitida($invoiceId, $correlationId);
        };
    }

    /**
     * @param callable(callable(?array): void): void $operation recebe $markStarted e o chama com a nota
     *        lida sob o lock, quando a emissão de fato começa (depois das verificações de bloqueio)
     * @param callable(): ?array $readNota relê a nota depois da operação
     */
    public function aroundEmission(int $invoiceId, string $correlationId, callable $operation, callable $readNota): void
    {
        $this->emissionInvoked = true;
        $started = false;
        $before = null;
        $markStarted = static function (?array $nota) use (&$started, &$before): void {
            $started = true;
            $before = $nota;
        };

        try {
            $operation($markStarted);
        } finally {
            if ($started) {
                $this->fireIfEmitted($invoiceId, $correlationId, $before, $readNota);
            }
        }
    }

    /**
     * @param array $before nota lida antes da consulta
     * @param callable(): void $operation
     * @param callable(): ?array $readNota relê a nota depois da operação
     */
    public function aroundConsulta(
        int $invoiceId,
        string $correlationId,
        array $before,
        callable $operation,
        callable $readNota
    ): void {
        $this->emissionInvoked = false;

        try {
            $operation();
        } finally {
            if (!$this->emissionInvoked) {
                $this->fireIfEmitted($invoiceId, $correlationId, $before, $readNota);
            }
        }
    }

    private function fireIfEmitted(int $invoiceId, string $correlationId, ?array $before, callable $readNota): void
    {
        try {
            if (AutomaticEmailPolicy::isEmissionTransition($before, $readNota())) {
                ($this->fire)($invoiceId, $correlationId);
            }
        } catch (\Throwable $e) {
            // Nunca substitui a exceção da operação nem interrompe a emissão.
        }
    }
}
