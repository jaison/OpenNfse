<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\AutoEmailTrigger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AutoEmailTriggerTest extends TestCase
{
    private const INVOICE_ID = 10;
    private const CORRELATION_ID = 'corr-123';

    /** @var list<array{int, string}> */
    private array $fired = [];

    /** Nota "gravada no banco", relida por $readNota. */
    private ?array $stored = null;

    private AutoEmailTrigger $trigger;

    protected function setUp(): void
    {
        $this->fired = [];
        $this->stored = null;
        $this->trigger = new AutoEmailTrigger(function (int $invoiceId, string $correlationId): void {
            $this->fired[] = [$invoiceId, $correlationId];
        });
    }

    private function processando(): array
    {
        return ['id' => 1, 'invoiceid' => self::INVOICE_ID, 'status' => 'PROCESSANDO', 'chave_acesso' => null];
    }

    private function emitida(string $chave = 'CHAVE-NOVA'): array
    {
        return ['id' => 1, 'invoiceid' => self::INVOICE_ID, 'status' => 'EMITIDA', 'chave_acesso' => $chave];
    }

    private function readNota(): callable
    {
        return function (): ?array {
            return $this->stored;
        };
    }

    /**
     * Operação de emissão que marca o início com $before, grava $after e, se pedido, lança $throw.
     */
    private function emission(?array $before, ?array $after, ?\Throwable $throw = null): callable
    {
        return function (callable $markStarted) use ($before, $after, $throw): void {
            $markStarted($before);
            $this->stored = $after;
            if ($throw !== null) {
                throw $throw;
            }
        };
    }

    private function runEmission(callable $operation, ?callable $readNota = null): void
    {
        $this->trigger->aroundEmission(
            self::INVOICE_ID,
            self::CORRELATION_ID,
            $operation,
            $readNota ?? $this->readNota()
        );
    }

    private function runConsulta(array $before, callable $operation, ?callable $readNota = null): void
    {
        $this->trigger->aroundConsulta(
            self::INVOICE_ID,
            self::CORRELATION_ID,
            $before,
            $operation,
            $readNota ?? $this->readNota()
        );
    }

    private function assertFiredOnce(): void
    {
        $this->assertSame([[self::INVOICE_ID, self::CORRELATION_ID]], $this->fired);
    }

    private function assertNotFired(): void
    {
        $this->assertSame([], $this->fired);
    }

    /**
     * Executa $run e devolve a exceção lançada, ou null.
     */
    private function catchThrowable(callable $run): ?\Throwable
    {
        try {
            $run();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    // aroundEmission

    public function testEmissionThatEmitsFiresOnce(): void
    {
        $this->stored = $this->processando();

        $this->runEmission($this->emission($this->processando(), $this->emitida()));

        $this->assertFiredOnce();
    }

    public function testEmissionThatEmitsAndThenThrowsFiresOnceAndRethrows(): void
    {
        // Ex.: lock wait timeout numa escrita depois do upsert da nota EMITIDA.
        $error = new RuntimeException('Lock wait timeout exceeded');

        $caught = $this->catchThrowable(function () use ($error): void {
            $this->runEmission($this->emission($this->processando(), $this->emitida(), $error));
        });

        $this->assertSame($error, $caught);
        $this->assertFiredOnce();
    }

    public function testEmissionFailingBeforeMarkStartedDoesNotFire(): void
    {
        // Lock ocupado ou status bloqueado: a emissão nem começou, mesmo que a nota esteja EMITIDA.
        $this->stored = $this->emitida();
        $error = new RuntimeException('Fatura em processamento');

        $caught = $this->catchThrowable(function () use ($error): void {
            $this->runEmission(function (callable $markStarted) use ($error): void {
                throw $error;
            });
        });

        $this->assertSame($error, $caught);
        $this->assertNotFired();
    }

    public function testEmissionEndingProcessandoDoesNotFire(): void
    {
        $this->runEmission($this->emission($this->processando(), $this->processando()));

        $this->assertNotFired();
    }

    public function testEmissionEndingRejeitadaDoesNotFire(): void
    {
        $rejeitada = ['status' => 'REJEITADA', 'chave_acesso' => null] + $this->processando();

        $this->runEmission($this->emission($this->processando(), $rejeitada));

        $this->assertNotFired();
    }

    public function testEmissionEndingEmitidaWithoutKeyDoesNotFire(): void
    {
        $this->runEmission($this->emission($this->processando(), $this->emitida('')));

        $this->assertNotFired();
    }

    public function testEmissionOfNotaAlreadyEmitidaWithSameKeyDoesNotFire(): void
    {
        $this->runEmission($this->emission($this->emitida(), $this->emitida()));

        $this->assertNotFired();
    }

    public function testEmissionWithNewKeyAfterPreviousKeyFires(): void
    {
        $this->runEmission($this->emission($this->emitida('CHAVE-ANTIGA'), $this->emitida('CHAVE-NOVA')));

        $this->assertFiredOnce();
    }

    public function testEmissionWithoutPreviousNotaFires(): void
    {
        $this->runEmission($this->emission(null, $this->emitida()));

        $this->assertFiredOnce();
    }

    public function testReadNotaFailureAfterSuccessfulEmissionDoesNotFireNorThrow(): void
    {
        $readNota = static function (): ?array {
            throw new RuntimeException('Falha ao reler a nota');
        };

        $caught = $this->catchThrowable(function () use ($readNota): void {
            $this->runEmission($this->emission($this->processando(), $this->emitida()), $readNota);
        });

        $this->assertNull($caught);
        $this->assertNotFired();
    }

    public function testReadNotaFailureKeepsTheOperationException(): void
    {
        $error = new RuntimeException('Falha na emissão');
        $readNota = static function (): ?array {
            throw new RuntimeException('Falha ao reler a nota');
        };

        $caught = $this->catchThrowable(function () use ($error, $readNota): void {
            $this->runEmission($this->emission($this->processando(), $this->emitida(), $error), $readNota);
        });

        $this->assertSame($error, $caught);
        $this->assertNotFired();
    }

    public function testFireFailureIsSwallowed(): void
    {
        $calls = 0;
        $trigger = new AutoEmailTrigger(static function (int $invoiceId, string $correlationId) use (&$calls): void {
            $calls++;
            throw new RuntimeException('Falha no envio do e-mail');
        });

        $caught = $this->catchThrowable(function () use ($trigger): void {
            $trigger->aroundEmission(
                self::INVOICE_ID,
                self::CORRELATION_ID,
                $this->emission($this->processando(), $this->emitida()),
                $this->readNota()
            );
        });

        $this->assertNull($caught);
        $this->assertSame(1, $calls);
    }

    public function testFireFailureDoesNotReplaceTheOperationException(): void
    {
        $error = new RuntimeException('Falha depois do upsert');
        $trigger = new AutoEmailTrigger(static function (int $invoiceId, string $correlationId): void {
            throw new RuntimeException('Falha no envio do e-mail');
        });

        $caught = $this->catchThrowable(function () use ($trigger, $error): void {
            $trigger->aroundEmission(
                self::INVOICE_ID,
                self::CORRELATION_ID,
                $this->emission($this->processando(), $this->emitida(), $error),
                $this->readNota()
            );
        });

        $this->assertSame($error, $caught);
    }

    // aroundConsulta

    public function testConsultaFromProcessandoToEmitidaFiresOnce(): void
    {
        $this->stored = $this->processando();

        $this->runConsulta($this->processando(), function (): void {
            $this->stored = $this->emitida();
        });

        $this->assertFiredOnce();
    }

    public function testConsultaThatEmitsAndThenThrowsFiresOnceAndRethrows(): void
    {
        $error = new RuntimeException('Falha depois de gravar EMITIDA');

        $caught = $this->catchThrowable(function () use ($error): void {
            $this->runConsulta($this->processando(), function () use ($error): void {
                $this->stored = $this->emitida();
                throw $error;
            });
        });

        $this->assertSame($error, $caught);
        $this->assertFiredOnce();
    }

    public function testConsultaOfNotaAlreadyEmitidaWithSameKeyDoesNotFire(): void
    {
        $this->stored = $this->emitida();

        $this->runConsulta($this->emitida(), function (): void {
            $this->stored = $this->emitida();
        });

        $this->assertNotFired();
    }

    public function testConsultaStillProcessandoDoesNotFire(): void
    {
        $this->stored = $this->processando();

        $this->runConsulta($this->processando(), static function (): void {
        });

        $this->assertNotFired();
    }

    public function testConsultaRecoveringKeyOfEmitidaWithoutKeyFiresOnce(): void
    {
        $this->stored = $this->emitida('');

        $this->runConsulta($this->emitida(''), function (): void {
            $this->stored = $this->emitida();
        });

        $this->assertFiredOnce();
    }

    public function testConsultaDelegatingToEmissionFiresOnlyOnce(): void
    {
        // consultarStatus -> E2404/503 -> emitir(): quem dispara é a emissão.
        $this->stored = $this->processando();

        $this->runConsulta($this->processando(), function (): void {
            $this->runEmission($this->emission($this->processando(), $this->emitida()));
        });

        $this->assertFiredOnce();
    }

    public function testConsultaDelegatingToEmissionThatEmitsAndThrowsFiresOnlyOnceAndRethrows(): void
    {
        $this->stored = $this->processando();
        $error = new RuntimeException('Lock wait timeout exceeded');

        $caught = $this->catchThrowable(function () use ($error): void {
            $this->runConsulta($this->processando(), function () use ($error): void {
                $this->runEmission($this->emission($this->processando(), $this->emitida(), $error));
            });
        });

        $this->assertSame($error, $caught);
        $this->assertFiredOnce();
    }

    public function testConsultaDelegatingToEmissionBlockedBeforeStartDoesNotFire(): void
    {
        // A emissão interna encontrou o lock ocupado: nem ela nem a consulta disparam.
        $this->stored = $this->processando();
        $error = new RuntimeException('Fatura em processamento');

        $caught = $this->catchThrowable(function () use ($error): void {
            $this->runConsulta($this->processando(), function () use ($error): void {
                $this->runEmission(function (callable $markStarted) use ($error): void {
                    $this->stored = $this->emitida();
                    throw $error;
                });
            });
        });

        $this->assertSame($error, $caught);
        $this->assertNotFired();
    }

    public function testIndependentConsultaAfterDelegatedOneStillFires(): void
    {
        $this->stored = $this->processando();
        $this->runConsulta($this->processando(), function (): void {
            $this->runEmission($this->emission($this->processando(), $this->emitida()));
        });
        $this->assertFiredOnce();

        // Outra nota (reemissão com chave nova) consultada pela mesma instância: o flag de delegação é zerado.
        $this->fired = [];
        $this->stored = $this->processando();
        $this->runConsulta($this->processando(), function (): void {
            $this->stored = $this->emitida('CHAVE-OUTRA');
        });

        $this->assertFiredOnce();
    }

    public function testConsultaAfterStandaloneEmissionStillFires(): void
    {
        // Uma emissão fora de consulta não deve deixar a consulta seguinte sem disparo.
        $this->runEmission($this->emission($this->processando(), $this->processando()));
        $this->assertNotFired();

        $this->runConsulta($this->processando(), function (): void {
            $this->stored = $this->emitida();
        });

        $this->assertFiredOnce();
    }
}
