<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\ActionFormRenderer;
use OpenNfse\Module;
use OpenNfse\Repositories\AlertRepository;
use OpenNfse\Services\AlertService;
use OpenNfse\Services\TokenService;

/**
 * Lista de alertas de NFS-e e ação "Marcar como resolvido".
 */
final class AlertasController
{
    use AdminHelpersTrait;

    private const LIST_LIMIT = 500;

    private const SITUACOES = [
        'abertos' => 'Abertos',
        'resolvidos' => 'Resolvidos',
        'todos' => 'Todos',
    ];

    public function countOpen(): int
    {
        try {
            return (new AlertRepository())->countOpen();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function showAlertas(): void
    {
        $situacao = trim((string) ($_GET['situacao'] ?? 'abertos'));
        if (!isset(self::SITUACOES[$situacao])) {
            $situacao = 'abertos';
        }
        $tipo = strtoupper(trim((string) ($_GET['tipo'] ?? '')));
        if ($tipo !== '' && !isset(AlertService::LABELS[$tipo])) {
            $tipo = '';
        }
        $invoiceFilter = (int) ($_GET['invoiceid'] ?? 0);

        $rows = [];
        $truncated = false;
        $loadError = '';
        try {
            // Busca um a mais para saber se a lista foi cortada.
            $rows = (new AlertRepository())->listWithContext([
                'situacao' => $situacao,
                'tipo' => $tipo,
                'invoiceid' => $invoiceFilter,
            ], self::LIST_LIMIT + 1);
            $truncated = count($rows) > self::LIST_LIMIT;
            $rows = array_slice($rows, 0, self::LIST_LIMIT);
        } catch (\Throwable $e) {
            $loadError = 'Não foi possível carregar os alertas: ' . $e->getMessage();
        }

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        Module::ui()->renderHeader('Alertas de NFS-e - OpenNfse');
        $this->renderTabs('dashboard');

        echo '<div style="margin-bottom:10px;"><a href="addonmodules.php?module=OpenNfse&action=dashboard">&larr; Voltar ao dashboard</a></div>';
        echo '<h3 style="margin-top:0;">Alertas de NFS-e</h3>';
        echo '<p style="color:#4b5563;max-width:900px;">Faturas canceladas, estornadas, reabertas, alteradas ou pagas só com crédito depois da emissão da NFS-e. O alerta não cancela a nota: avalie cada caso e, se for o caso, cancele a NFS-e manualmente dentro do prazo do município. Depois marque o alerta como resolvido.</p>';

        $resolvedMsg = (string) ($_GET['resolvido'] ?? '');
        if ($resolvedMsg === '1') {
            echo '<div class="successbox">Alerta marcado como resolvido.</div>';
        } elseif ($resolvedMsg === '0') {
            echo '<div class="errorbox">O alerta não foi encontrado ou já estava resolvido.</div>';
        }
        if ($loadError !== '') {
            echo '<div class="errorbox">' . $h($loadError) . '</div>';
        }

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="alertas" />';
        echo '<div style="margin-bottom:14px;padding:12px 14px;border:1px solid #dbe3ea;border-radius:8px;background:linear-gradient(180deg,#fafbfc 0%,#f4f6f8 100%);display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div><div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Situação</div>';
        echo '<select name="situacao" style="height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        foreach (self::SITUACOES as $key => $label) {
            echo '<option value="' . $h($key) . '"' . ($key === $situacao ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div><div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Tipo</div>';
        echo '<select name="tipo" style="height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;">';
        echo '<option value="">Todos</option>';
        foreach (AlertService::LABELS as $key => $label) {
            echo '<option value="' . $h($key) . '"' . ($key === $tipo ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div><div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Fatura</div>';
        echo '<input type="number" min="1" name="invoiceid" value="' . ($invoiceFilter > 0 ? $h((string) $invoiceFilter) : '') . '" style="width:130px;height:34px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;" /></div>';
        echo '<div style="display:flex;gap:8px;">';
        echo '<button type="submit" class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" style="height:34px;padding:0 12px;line-height:32px;" href="addonmodules.php?module=OpenNfse&action=alertas">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        if ($truncated) {
            echo '<div class="alert alert-info" style="margin-bottom:10px;">Exibindo os ' . self::LIST_LIMIT . ' alertas mais recentes. Use os filtros para ver os demais.</div>';
        }

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:10%;">Data</th>';
        echo '<th style="width:7%;">Fatura</th>';
        echo '<th style="width:14%;">Cliente</th>';
        echo '<th style="width:15%;">NFS-e</th>';
        echo '<th style="width:12%;">Tipo</th>';
        echo '<th style="width:26%;">Detalhes</th>';
        echo '<th style="width:16%;">Ações</th>';
        echo '</tr>';

        if (empty($rows)) {
            echo '<tr><td colspan="7" style="text-align:center;color:#666;">Nenhum alerta encontrado para os filtros selecionados.</td></tr>';
        }

        $token = (new TokenService())->token();
        $returnQuery = http_build_query(array_filter([
            'situacao' => $situacao,
            'tipo' => $tipo,
            'invoiceid' => $invoiceFilter > 0 ? (string) $invoiceFilter : '',
        ], static fn ($v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);

        foreach ($rows as $row) {
            $alertId = (int) ($row['id'] ?? 0);
            $invoiceId = (int) ($row['invoiceid'] ?? 0);
            $alertChave = trim((string) ($row['chave_acesso'] ?? ''));
            $notaChave = trim((string) ($row['nota_chave_acesso'] ?? ''));
            $notaStatus = strtoupper(trim((string) ($row['nota_status'] ?? '')));
            $sameNota = $alertChave === '' || $alertChave === $notaChave;
            $numeroNf = $sameNota ? trim((string) ($row['nota_numero_nf'] ?? '')) : '';
            $chave = $alertChave !== '' ? $alertChave : $notaChave;
            $chaveShort = mb_strlen($chave, 'UTF-8') > 20 ? mb_substr($chave, 0, 20, 'UTF-8') . '...' : $chave;
            $resolvidoEm = trim((string) ($row['resolvido_em'] ?? ''));
            $invoiceStatus = trim((string) ($row['invoice_status'] ?? ''));

            echo '<tr>';
            echo '<td>' . $h($this->formatDate((string) ($row['criado_em'] ?? ''), 'd/m/Y H:i')) . '</td>';
            echo '<td><a href="' . $h('invoices.php?action=edit&id=' . $invoiceId) . '">' . $h((string) $invoiceId) . '</a>'
                . ($invoiceStatus !== '' ? '<div style="color:#6b7280;">' . $h($invoiceStatus) . '</div>' : '') . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . $h($this->resolveClientName($row)) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">';
            echo $numeroNf !== '' ? '<div><strong>Nº ' . $h($numeroNf) . '</strong></div>' : '';
            echo $chave !== '' ? '<div title="' . $h($chave) . '">' . $h($chaveShort) . '</div>' : '<div>-</div>';
            if (!$sameNota) {
                echo '<div style="color:#8a6d3b;">A nota atual da fatura é outra.</div>';
            } elseif ($notaStatus !== '' && $notaStatus !== 'EMITIDA') {
                echo '<div style="color:#6b7280;">Nota atual: ' . $h($notaStatus) . '</div>';
            }
            echo '</td>';
            echo '<td>' . $h(AlertService::label((string) ($row['tipo'] ?? ''))) . '</td>';
            echo '<td style="word-break:break-word;overflow-wrap:anywhere;">' . $h((string) ($row['detalhes'] ?? ''));
            if ($resolvidoEm !== '') {
                $por = trim((string) ($row['resolvido_por_username'] ?? ''));
                if ($por === '' && (int) ($row['resolvido_por'] ?? 0) > 0) {
                    $por = 'admin #' . (int) $row['resolvido_por'];
                }
                echo '<div style="margin-top:4px;color:#176b46;">Resolvido em ' . $h($this->formatDate($resolvidoEm, 'd/m/Y H:i')) . ($por !== '' ? ' por ' . $h($por) : '') . '</div>';
            }
            echo '</td>';
            echo '<td>';
            if ($sameNota && $notaStatus === 'EMITIDA' && $notaChave !== '') {
                echo '<a class="btn btn-xs btn-danger" style="margin:0 4px 4px 0;" href="' . $h('addonmodules.php?module=OpenNfse&action=cancelForm&invoiceid=' . $invoiceId) . '">Cancelar NFS-e</a>';
            }
            if ($resolvidoEm === '' && $alertId > 0) {
                echo ActionFormRenderer::render(
                    'addonmodules.php?module=OpenNfse&action=alertaResolver',
                    'display:inline-block;margin:0;',
                    [
                        ActionFormRenderer::tokenInput($token),
                        '<input type="hidden" name="alerta_id" value="' . $h((string) $alertId) . '" />',
                        '<input type="hidden" name="return" value="' . $h($returnQuery) . '" />',
                    ],
                    '<button type="submit" class="btn btn-xs btn-default" onclick="return confirm(\'Marcar este alerta como resolvido?\');">Marcar como resolvido</button>'
                );
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        Module::ui()->renderFooter();
    }

    public function resolveAlerta(): void
    {
        $alertId = (int) ($_POST['alerta_id'] ?? 0);
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        $ok = $alertId > 0 && (new AlertService())->resolve($alertId, $adminId);

        $params = [];
        parse_str((string) ($_POST['return'] ?? ''), $params);
        $safe = [];
        foreach (['situacao', 'tipo', 'invoiceid'] as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $params[$key]) === 1) {
                $safe[$key] = $params[$key];
            }
        }
        $safe['resolvido'] = $ok ? '1' : '0';

        header('Location: addonmodules.php?module=OpenNfse&action=alertas&' . http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        exit;
    }
}
