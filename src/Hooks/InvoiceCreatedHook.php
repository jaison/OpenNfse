<?php

declare(strict_types=1);

namespace OpenNfse\Hooks;

use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Services\EmissionPolicyContextLoader;
use OpenNfse\Services\EmissionPolicyService;
use OpenNfse\Services\GenerationCheckService;
use OpenNfse\Services\QueueService;

/**
 * Emissão na geração da fatura. A fatura publicada chega ao addon por caminhos diferentes
 * (observado no WHMCS 9, ambiente de testes; o desenho cobre qualquer versão):
 * - InvoiceCreated (geração automática do cron, Mass Pay da área do cliente e API);
 * - InvoiceCreationPreEmail ("Publish and Send Email" no admin), só para fatura com conferência
 *   registrada pelo InvoiceCreation (o reenvio do e-mail de fatura antiga não reavalia nada);
 * - nenhum hook útil no "Publish" sem e-mail: o InvoiceCreation (com a fatura ainda Draft) registra
 *   uma conferência, e o cron trata a fatura depois (GenerationCheckService).
 * Os caminhos imediatos e o cron usam o mesmo tratamento (processGenerated) e, juntos, geram no
 * máximo um item na fila e uma decisão logada por fatura.
 */
final class InvoiceCreatedHook
{
    /** Tipo de log de falha (mod_opennfse_logs.tipo é VARCHAR(30)). */
    public const LOG_ERROR = 'QUEUE_AUTO_CREATED_ERROR';

    /** @var array<int, true> faturas já tratadas neste processo (ex.: InvoiceCreated e PreEmail na mesma requisição) */
    private static array $handledInProcess = [];

    /** @var callable(int): bool enfileira a emissão da geração */
    private $enqueue;

    private ?GenerationCheckService $checks;

    /** Config lida uma vez por processo (lote de geração do cron: uma leitura). */
    private static ?array $configCache = null;

    public function __construct(?callable $enqueue = null, ?GenerationCheckService $checks = null)
    {
        $this->enqueue = $enqueue ?? static function (int $invoiceId): bool {
            return (new QueueService())->enqueueEmit(
                $invoiceId,
                'QUEUE_ENQUEUE_AUTO_CREATED',
                true,
                EmissionPolicyService::QUEUE_ORIGEM_GERADA
            );
        };
        $this->checks = $checks;
    }

    /**
     * Caminho imediato: InvoiceCreated e InvoiceCreationPreEmail.
     */
    public function handle(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        // O status recebido só serve de atalho para o rascunho. A decisão relê o status no banco:
        // com crédito automático, o InvoicePaid pode chegar antes e a fatura já estar Paid.
        if (self::hookStatusIsDraft($vars)) {
            return;
        }

        // Sem Migrator aqui: o hook roda para cada fatura da geração em lote. Com o gatilho global
        // padrão, a decisão sai só da config; o enqueueEmit já aplica as migrations antes de gravar.
        // Tudo fica dentro do try: uma falha do addon não pode interromper a geração da fatura.
        try {
            $config = self::config();
            if (empty($config) || !self::onCreateMayApply($config)) {
                return;
            }

            if ($this->processGenerated($invoiceId, $config)) {
                $this->checks()->forget($invoiceId);
            }
        } catch (\Throwable $e) {
            self::logError($invoiceId, $e);
        }
    }

    /**
     * Hook InvoiceCreationPreEmail: só age na fatura com conferência registrada pelo InvoiceCreation
     * (fatura recém-criada). O mesmo hook roda no reenvio do e-mail "Invoice Created" de uma fatura
     * antiga, que não deve ser reavaliada.
     */
    public function handlePreEmail(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        try {
            if (!$this->checks()->isPending($invoiceId)) {
                return;
            }
        } catch (\Throwable $e) {
            self::logError($invoiceId, $e);
            return;
        }

        $this->handle($vars);
    }

    /**
     * Hook InvoiceCreation: roda em toda criação, antes de itens e total serem finalizados. Só
     * registra a conferência, e só quando a emissão na geração pode valer.
     */
    public function registerCreation(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        try {
            $config = self::config();
            if (empty($config) || !self::onCreateMayApply($config)) {
                return;
            }

            $this->checks()->register($invoiceId);
        } catch (\Throwable $e) {
            self::logError($invoiceId, $e);
        }
    }

    /**
     * Tratamento da geração, comum ao caminho imediato e ao cron. Devolve false se a fatura ainda
     * está em rascunho (a conferência continua pendente) e true quando ela foi tratada.
     *
     * Fatura já tratada (item na fila em qualquer status, ou nota EMITIDA/PROCESSANDO) volta true
     * sem decisão nem log, para o imediato e o cron não repetirem a decisão.
     */
    public function processGenerated(int $invoiceId, array $config): bool
    {
        if (isset(self::$handledInProcess[$invoiceId]) || self::alreadyHandled($invoiceId)) {
            return true;
        }

        $loader = new EmissionPolicyContextLoader();
        $ctx = $loader->load($invoiceId, $config);
        if (strtolower(trim((string) ($ctx['invoice']['status'] ?? ''))) === 'draft') {
            return false;
        }
        self::$handledInProcess[$invoiceId] = true;

        $decision = (new EmissionPolicyService())->decide(
            EmissionPolicyService::EVENT_GERADA,
            $ctx['invoice'],
            $ctx['client'],
            $config,
            $ctx['gateway'],
            $ctx['nota']
        );
        $loader->logWarnings($invoiceId, EmissionPolicyService::EVENT_GERADA, $decision);

        if (!$decision->emit) {
            if (!$decision->silent) {
                (new LogRepository())->insert(
                    null,
                    'QUEUE_SKIP_POLICY',
                    json_encode(
                        ['invoiceid' => $invoiceId, 'evento' => EmissionPolicyService::EVENT_GERADA]
                            + $decision->toLogContext(),
                        JSON_UNESCAPED_UNICODE
                    ),
                    $decision->message
                );
            }
            return true;
        }

        ($this->enqueue)($invoiceId);

        return true;
    }

    private function checks(): GenerationCheckService
    {
        return $this->checks ??= new GenerationCheckService();
    }

    /**
     * Fatura que já passou pela emissão: tem item na fila (qualquer status) ou nota EMITIDA/PROCESSANDO.
     */
    public static function alreadyHandled(int $invoiceId): bool
    {
        if ((new QueueRepository())->hasAnyItem($invoiceId)) {
            return true;
        }
        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);

        return $nota !== null && in_array(trim((string) ($nota['status'] ?? '')), ['EMITIDA', 'PROCESSANDO'], true);
    }

    /**
     * @internal Para testes.
     */
    public static function resetForTests(): void
    {
        self::$handledInProcess = [];
        self::$configCache = null;
    }

    private static function config(): array
    {
        return self::$configCache ??= (new ConfigRepository())->get();
    }

    public static function hookStatusIsDraft(array $vars): bool
    {
        return strtolower(trim((string) ($vars['status'] ?? ''))) === 'draft';
    }

    /**
     * Só vale a pena ler fatura e cliente se a fila estiver ligada e alguma regra puder emitir
     * na geração: gatilho global "gerada" ou custom field por cliente configurado.
     */
    public static function onCreateMayApply(array $config): bool
    {
        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return false;
        }

        if (EmissionPolicyService::resolveGlobalTrigger($config) === EmissionPolicyService::TRIGGER_GERADA) {
            return true;
        }

        // Custom field apagado no WHMCS não conta: a regra por cliente está sendo ignorada.
        return (int) ($config['auto_emit_customfield_id'] ?? 0) > 0
            && !EmissionPolicyContextLoader::configuredCustomFieldMissing($config);
    }

    private static function logError(int $invoiceId, \Throwable $e): void
    {
        try {
            (new LogRepository())->insert(
                null,
                self::LOG_ERROR,
                json_encode(['invoiceid' => $invoiceId], JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            );
        } catch (\Throwable $loggingError) {
            return;
        }
    }
}
