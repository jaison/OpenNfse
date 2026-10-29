<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Hooks;

use OpenNfse\Hooks\InvoiceCreatedHook;
use OpenNfse\Services\GenerationCheckService;
use OpenNfse\Tests\Services\InMemoryGenerationCheckRepository;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * Caminhos da fatura gerada (InvoiceCreated, InvoiceCreationPreEmail, InvoiceCreation + cron),
 * com o banco simulado pelo stub do Capsule e o enfileiramento trocado por um fake.
 */
final class InvoiceCreatedHookTest extends TestCase
{
    private InMemoryGenerationCheckRepository $repo;

    /** @var array<int, int> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::reset();
        InvoiceCreatedHook::resetForTests();
        $this->repo = new InMemoryGenerationCheckRepository();
        $this->enqueued = [];
        $this->database();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        InvoiceCreatedHook::resetForTests();
        parent::tearDown();
    }

    private function database(array $config = [], string $status = 'Unpaid', int $emitOnCreate = 1): void
    {
        InvoiceCreatedHook::resetForTests();
        Capsule::$rows['mod_opennfse_config'] = [(object) ($config + [
            'id' => 1,
            'queue_enabled' => '1',
            'auto_emit_trigger' => 'gerada',
            'auto_emit_on_payment' => '1',
            'auto_emit_scope' => 'todos',
            'auto_emit_customfield_id' => 0,
        ])];
        Capsule::$rows['tblinvoices'] = [(object) [
            'id' => 500, 'userid' => 7, 'status' => $status, 'total' => '100.00', 'credit' => '0.00', 'paymentmethod' => 'boleto',
        ]];
        Capsule::$rows['tblclients'] = [(object) ['id' => 7, 'groupid' => 0]];
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'boleto', 'enabled' => 1, 'emit_on_create' => $emitOnCreate],
        ];
        Capsule::$rows['tblinvoiceitems'] = [];
        Capsule::$rows['mod_opennfse_queue'] = [];
        Capsule::$rows['mod_opennfse_notas'] = [];
    }

    private function hook(): InvoiceCreatedHook
    {
        $checks = new GenerationCheckService($this->repo, null, null, function (int $id, array $config): bool {
            return $this->hook()->processGenerated($id, $config);
        });

        return new InvoiceCreatedHook(function (int $invoiceId): bool {
            $this->enqueued[] = $invoiceId;
            // Como o enqueue real: o item passa a existir na fila.
            Capsule::$rows['mod_opennfse_queue'][] = (object) ['id' => count($this->enqueued), 'invoiceid' => $invoiceId, 'status' => 'PENDING'];
            return true;
        }, $checks);
    }

    /** Primeiro ciclo do cron depois do vencimento das conferências registradas agora. */
    private function later(): int
    {
        return time() + GenerationCheckService::INTERVAL_SECONDS + 1;
    }

    private function cron(): GenerationCheckService
    {
        return new GenerationCheckService(
            $this->repo,
            static fn (int $id): ?string => (string) (Capsule::$rows['tblinvoices'][0]->status ?? ''),
            null,
            fn (int $id, array $config): bool => $this->hook()->processGenerated($id, $config)
        );
    }

    /** @return array<int, string> */
    private function logTypes(): array
    {
        return array_column(Capsule::$inserts['mod_opennfse_logs'] ?? [], 'tipo');
    }

    public function testCreationRegistersOnlyWhenAutomationApplies(): void
    {
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        $this->assertArrayHasKey(500, $this->repo->rows);

        $this->repo->rows = [];
        $this->database(['auto_emit_trigger' => 'paga']);
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        $this->assertSame([], $this->repo->rows, 'Gatilho "paga" sem custom field: nada a conferir.');

        $this->database(['queue_enabled' => '0']);
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        $this->assertSame([], $this->repo->rows, 'Fila desligada: nada.');
    }

    public function testPreEmailWithUnpaidHandlesAndForgetsCheck(): void
    {
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        // "Publish and Send Email": InvoiceCreationPreEmail com a fatura já Unpaid.
        $this->hook()->handlePreEmail(['invoiceid' => 500, 'status' => 'Unpaid', 'source' => 'adminarea']);

        $this->assertSame([500], $this->enqueued);
        $this->assertSame([], $this->repo->rows);
    }

    public function testPreEmailWithoutCheckDoesNothing(): void
    {
        // Reenvio do e-mail "Invoice Created" de uma fatura antiga: não há conferência registrada.
        $this->hook()->handlePreEmail(['invoiceid' => 500, 'status' => 'Unpaid', 'source' => 'adminarea']);

        $this->assertSame([], $this->enqueued);
        $this->assertSame([], $this->logTypes());
    }

    public function testPreEmailDoesNotQueryChecksWhenGenerationIsOff(): void
    {
        $this->database(['auto_emit_trigger' => 'paga']);
        $repo = new class () extends \stdClass implements \OpenNfse\Repositories\GenerationCheckRepositoryInterface {
            public int $queries = 0;

            public function register(int $invoiceId, string $at, string $dueAt): void
            {
            }

            public function exists(int $invoiceId): bool
            {
                $this->queries++;
                return true;
            }

            public function listDue(string $now, int $limit): array
            {
                return [];
            }

            public function reschedule(int $invoiceId, array $fields): void
            {
            }

            public function delete(int $invoiceId): void
            {
            }
        };
        $hook = new InvoiceCreatedHook(fn (int $id): bool => true, new GenerationCheckService($repo));

        $hook->handlePreEmail(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->assertSame(0, $repo->queries, 'Sem a emissão na geração, nada de SELECT na tabela de conferências.');
    }

    public function testConfigIsReadOncePerProcess(): void
    {
        $this->hook()->registerCreation(['invoiceid' => 500]);
        // A config muda no banco, mas o processo continua com a que leu (lote de geração: 1 leitura).
        Capsule::$rows['mod_opennfse_config'][0]->queue_enabled = '0';
        $this->repo->rows = [];
        $this->hook()->registerCreation(['invoiceid' => 500]);
        $this->assertArrayHasKey(500, $this->repo->rows);
    }

    public function testDraftInDatabaseKeepsCheckPending(): void
    {
        $this->database([], 'Draft');
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        $this->hook()->handle(['invoiceid' => 500]);

        $this->assertSame([], $this->enqueued);
        $this->assertArrayHasKey(500, $this->repo->rows);
        $this->assertNotContains('QUEUE_SKIP_POLICY', $this->logTypes());
    }

    public function testPublishWithoutEmailIsHandledByCron(): void
    {
        // "Publish" sem e-mail: só InvoiceCreation (Draft); depois a fatura fica Unpaid.
        $this->database([], 'Draft');
        $this->hook()->registerCreation(['invoiceid' => 500, 'status' => 'Draft']);
        $this->database();

        $this->assertSame(1, $this->cron()->processPending((array) Capsule::$rows['mod_opennfse_config'][0], $this->later()));
        $this->assertSame([500], $this->enqueued);
        $this->assertSame([], $this->repo->rows);
    }

    public function testImmediateAndCronEnqueueOnlyOnce(): void
    {
        $config = (array) Capsule::$rows['mod_opennfse_config'][0];

        // Cron primeiro, depois o caminho imediato em outra requisição.
        $this->hook()->registerCreation(['invoiceid' => 500]);
        $this->cron()->processPending($config, $this->later());
        InvoiceCreatedHook::resetForTests();
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->assertSame([500], $this->enqueued);

        // Imediato primeiro, depois o cron: a conferência já foi apagada.
        $this->setUp();
        $this->hook()->registerCreation(['invoiceid' => 500]);
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->cron()->processPending($config, $this->later());
        $this->assertSame([500], $this->enqueued);
    }

    public function testSkipDecisionIsLoggedOnceAndCheckIsForgotten(): void
    {
        // Gateway não marcado em "Emitir ao gerar a fatura": decisão de não emitir, logada uma vez.
        $this->database([], 'Unpaid', 0);
        $this->hook()->registerCreation(['invoiceid' => 500]);
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->cron()->processPending((array) Capsule::$rows['mod_opennfse_config'][0], $this->later());

        $this->assertSame([], $this->enqueued);
        $this->assertSame(['QUEUE_SKIP_POLICY'], $this->logTypes());
        $this->assertSame([], $this->repo->rows);
    }

    public function testAlreadyQueuedOrIssuedIsSilent(): void
    {
        Capsule::$rows['mod_opennfse_queue'] = [(object) ['id' => 9, 'invoiceid' => 500, 'status' => 'DONE']];
        $this->hook()->registerCreation(['invoiceid' => 500]);
        $this->cron()->processPending((array) Capsule::$rows['mod_opennfse_config'][0], $this->later());
        $this->assertSame([], $this->enqueued);
        $this->assertSame([], $this->logTypes());
        $this->assertSame([], $this->repo->rows);

        $this->setUp();
        Capsule::$rows['mod_opennfse_notas'] = [(object) ['invoiceid' => 500, 'status' => 'PROCESSANDO']];
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->assertSame([], $this->enqueued);
        $this->assertSame([], $this->logTypes());
    }

    public function testQueueDisabledDoesNothing(): void
    {
        $this->database(['queue_enabled' => '0']);
        $this->repo->register(500, '2026-10-28 10:00:00', '2026-10-28 10:05:00');
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Unpaid']);
        $this->cron()->processPending((array) Capsule::$rows['mod_opennfse_config'][0], $this->later());

        $this->assertSame([], $this->enqueued);
        $this->assertArrayHasKey(500, $this->repo->rows);
    }

    public function testDraftHookStatusIsAShortcut(): void
    {
        $this->hook()->handle(['invoiceid' => 500, 'status' => 'Draft']);
        $this->assertSame([], $this->enqueued);
    }
}
