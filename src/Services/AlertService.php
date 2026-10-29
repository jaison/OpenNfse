<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Migrations\Migrator;
use OpenNfse\Repositories\AlertRepository;
use OpenNfse\Repositories\AlertRepositoryInterface;
use OpenNfse\Repositories\LogRepository;

/**
 * Alertas de NFS-e que pedem revisão manual (mod_opennfse_alertas).
 *
 * Um alerta nunca cancela nem bloqueia nada: ele aparece no dashboard, vai para o log
 * e para o histórico da fatura. raise() é idempotente por (invoiceid, tipo, chave_acesso)
 * enquanto o alerta estiver aberto.
 */
final class AlertService
{
    public const FATURA_CANCELADA_COM_NFSE = 'FATURA_CANCELADA_COM_NFSE';
    public const FATURA_ESTORNADA_COM_NFSE = 'FATURA_ESTORNADA_COM_NFSE';
    public const FATURA_REABERTA_COM_NFSE = 'FATURA_REABERTA_COM_NFSE';
    public const FATURA_ALTERADA_COM_NFSE = 'FATURA_ALTERADA_COM_NFSE';
    public const CREDITO_APOS_EMISSAO = 'CREDITO_APOS_EMISSAO';

    public const LABELS = [
        self::FATURA_CANCELADA_COM_NFSE => 'Fatura cancelada com NFS-e',
        self::FATURA_ESTORNADA_COM_NFSE => 'Estorno ou reversão com NFS-e',
        self::FATURA_REABERTA_COM_NFSE => 'Fatura reaberta com NFS-e',
        self::FATURA_ALTERADA_COM_NFSE => 'Fatura alterada com NFS-e',
        self::CREDITO_APOS_EMISSAO => 'Crédito após emissão',
    ];

    private AlertRepositoryInterface $repository;

    /** @var callable(): void */
    private $ensureSchema;

    private ?LogRepository $logRepository;

    private ?InvoiceHistoryService $history;

    public function __construct(
        ?AlertRepositoryInterface $repository = null,
        ?callable $ensureSchema = null,
        ?LogRepository $logRepository = null,
        ?InvoiceHistoryService $history = null
    ) {
        $this->repository = $repository ?? new AlertRepository();
        $this->ensureSchema = $ensureSchema ?? static function (): void {
            (new Migrator())->up();
        };
        $this->logRepository = $logRepository ?? new LogRepository();
        $this->history = $history ?? new InvoiceHistoryService();
    }

    public static function label(string $tipo): string
    {
        return self::LABELS[$tipo] ?? $tipo;
    }

    public static function openKey(int $invoiceId, string $tipo, string $chaveAcesso): string
    {
        return sha1($invoiceId . '|' . strtoupper(trim($tipo)) . '|' . trim($chaveAcesso));
    }

    /**
     * Abre um alerta. Devolve o id do alerta novo, ou null quando já existe um alerta aberto igual.
     */
    public function raise(int $invoiceId, string $tipo, ?array $nota, string $detalhes): ?int
    {
        $tipo = strtoupper(trim($tipo));
        if ($invoiceId <= 0 || !isset(self::LABELS[$tipo])) {
            return null;
        }

        $chave = trim((string) ($nota['chave_acesso'] ?? ''));
        $notaId = (int) ($nota['id'] ?? 0);
        $key = self::openKey($invoiceId, $tipo, $chave);
        $row = [
            'invoiceid' => $invoiceId,
            'nota_id' => $notaId > 0 ? $notaId : null,
            'chave_acesso' => $chave !== '' ? $chave : null,
            'tipo' => $tipo,
            'detalhes' => $detalhes,
            'chave_aberto' => $key,
            'criado_em' => date('Y-m-d H:i:s'),
            'resolvido_em' => null,
            'resolvido_por' => null,
        ];

        $id = $this->insertOnce($key, $row, true);
        if ($id === null) {
            return null;
        }

        $this->log($notaId > 0 ? $notaId : null, 'NFSE_ALERTA_CRIADO', [
            'alerta_id' => $id,
            'invoiceid' => $invoiceId,
            'tipo' => $tipo,
            'chave_acesso' => $chave !== '' ? $chave : null,
            'detalhes' => $detalhes,
        ]);
        $this->appendHistory($invoiceId, 'Alerta: ' . self::label($tipo) . '. ' . $detalhes);

        return $id;
    }

    /**
     * Marca o alerta como resolvido pelo admin. Devolve false se ele não existir ou já estiver resolvido.
     */
    public function resolve(int $alertId, int $adminId): bool
    {
        $alert = $this->repository->findById($alertId);
        if ($alert === null) {
            return false;
        }

        if (!$this->repository->markResolved($alertId, $adminId, date('Y-m-d H:i:s'))) {
            return false;
        }

        $invoiceId = (int) ($alert['invoiceid'] ?? 0);
        $tipo = (string) ($alert['tipo'] ?? '');
        $notaId = (int) ($alert['nota_id'] ?? 0);
        $this->log($notaId > 0 ? $notaId : null, 'NFSE_ALERTA_RESOLVIDO', [
            'alerta_id' => $alertId,
            'invoiceid' => $invoiceId,
            'tipo' => $tipo,
            'admin_id' => $adminId > 0 ? $adminId : null,
        ]);
        $this->appendHistory(
            $invoiceId,
            'Alerta "' . self::label($tipo) . '" marcado como resolvido' . ($adminId > 0 ? ' pelo admin #' . $adminId : '') . '.'
        );

        return true;
    }

    private function insertOnce(string $key, array $row, bool $allowSchemaRetry): ?int
    {
        try {
            if ($this->repository->findOpenByKey($key) !== null) {
                return null;
            }

            return $this->repository->insert($row);
        } catch (\Throwable $e) {
            if (self::isDuplicateKeyError($e)) {
                // Outro pedido abriu o mesmo alerta entre a leitura e a gravação.
                return null;
            }

            if ($allowSchemaRetry && self::isMissingTableError($e)) {
                ($this->ensureSchema)();

                return $this->insertOnce($key, $row, false);
            }

            throw $e;
        }
    }

    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Duplicate entry') !== false || stripos($message, 'Integrity constraint violation: 1062') !== false;
    }

    private static function isMissingTableError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'Base table or view not found') !== false || stripos($message, "doesn't exist") !== false;
    }

    private function log(?int $notaId, string $tipo, array $payload): void
    {
        if ($this->logRepository === null) {
            return;
        }

        try {
            $this->logRepository->insert($notaId, $tipo, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null);
        } catch (\Throwable $e) {
            return;
        }
    }

    private function appendHistory(int $invoiceId, string $message): void
    {
        if ($this->history === null) {
            return;
        }

        try {
            $this->history->append($invoiceId, $message);
        } catch (\Throwable $e) {
            return;
        }
    }
}
