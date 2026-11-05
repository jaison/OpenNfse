<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\ActionFormRenderer;
use OpenNfse\Module;
use OpenNfse\Repositories\AlertRepository;
use OpenNfse\Services\AlertService;
use OpenNfse\Services\TokenService;
use OpenNfse\Views\ReportView;
use OpenNfse\Views\Ui;

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

        Module::ui()->renderHeader('Alertas de NFS-e - OpenNfse');
        $this->renderTabs('dashboard', 'Alertas de NFS-e', 'Notas que pedem revisão manual');

        echo '<div class="nfse-ui nfse-page">';
        echo Ui::sectionHeader(
            'Alertas registrados',
            'Faturas canceladas, estornadas, reabertas, alteradas ou pagas só com crédito depois da emissão da NFS-e.',
            Ui::button('Voltar ao dashboard', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=dashboard']),
            'nfse-alertas-titulo'
        );
        echo Ui::callout('info', 'O alerta não cancela a nota', 'Avalie cada caso e, se for o caso, cancele a NFS-e manualmente dentro do prazo do município. Depois marque o alerta como resolvido.');

        $resolvedMsg = (string) ($_GET['resolvido'] ?? '');
        if ($resolvedMsg === '1') {
            echo Ui::callout('ok', 'Alerta marcado como resolvido.');
        } elseif ($resolvedMsg === '0') {
            echo Ui::callout('danger', 'O alerta não foi encontrado ou já estava resolvido.');
        }
        if ($loadError !== '') {
            echo Ui::callout('danger', $loadError);
        }

        $tipoOptions = ['' => 'Todos'] + AlertService::LABELS;
        echo Ui::filterBar(
            ['hidden' => ['module' => 'OpenNfse', 'action' => 'alertas']],
            Ui::field('Situação', Ui::select('situacao', self::SITUACOES, $situacao, [], 'nfse-input--w-md'))
            . Ui::field('Tipo', Ui::select('tipo', $tipoOptions, $tipo, [], 'nfse-input--w-md'))
            . Ui::field('Fatura', Ui::input('invoiceid', $invoiceFilter > 0 ? (string) $invoiceFilter : '', 'number', ['min' => '1'], 'nfse-input--w-sm')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=alertas']),
            'Filtros dos alertas'
        );

        $token = (new TokenService())->token();
        $returnQuery = http_build_query(array_filter([
            'situacao' => $situacao,
            'tipo' => $tipo,
            'invoiceid' => $invoiceFilter > 0 ? (string) $invoiceFilter : '',
        ], static fn ($v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);

        $rowsHtml = '';
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

            $nfse = '<div class="nfse-cell-stack">';
            $nfse .= $numeroNf !== '' ? '<b>Nº ' . Ui::h($numeroNf) . '</b>' : '';
            $nfse .= $chave !== '' ? '<span class="nfse-mono" title="' . Ui::h($chave) . '">' . Ui::h($chaveShort) . '</span>' : '<span class="nfse-muted">-</span>';
            if (!$sameNota) {
                $nfse .= '<span class="nfse-cell-meta nfse-text-danger">A nota atual da fatura é outra.</span>';
            } elseif ($notaStatus !== '' && $notaStatus !== 'EMITIDA') {
                $nfse .= '<span class="nfse-cell-meta">Nota atual: ' . Ui::h($notaStatus) . '</span>';
            }
            $nfse .= '</div>';

            $details = Ui::h((string) ($row['detalhes'] ?? ''));
            if ($resolvidoEm !== '') {
                $por = trim((string) ($row['resolvido_por_username'] ?? ''));
                if ($por === '' && (int) ($row['resolvido_por'] ?? 0) > 0) {
                    $por = 'admin #' . (int) $row['resolvido_por'];
                }
                $details .= '<div class="nfse-cell-meta">' . Ui::badge('Resolvido', 'ok') . ' em ' . Ui::h($this->formatDate($resolvidoEm, 'd/m/Y H:i')) . ($por !== '' ? ' por ' . Ui::h($por) : '') . '</div>';
            }

            $actions = '<div class="nfse-actions">';
            if ($sameNota && $notaStatus === 'EMITIDA' && $notaChave !== '') {
                $actions .= Ui::button('Cancelar NFS-e', ['sm', 'danger'], ['href' => 'addonmodules.php?module=OpenNfse&action=cancelForm&invoiceid=' . $invoiceId]);
            }
            if ($resolvidoEm === '' && $alertId > 0) {
                $actions .= '<form class="nfse-inline-form" method="post" action="addonmodules.php?module=OpenNfse&amp;action=alertaResolver">'
                    . ActionFormRenderer::tokenInput($token)
                    . Ui::hidden(['alerta_id' => (string) $alertId, 'return' => $returnQuery])
                    . '<button type="submit" class="nfse-btn nfse-btn--sm" onclick="return confirm(\'Marcar este alerta como resolvido?\');">Marcar como resolvido</button>'
                    . '</form>';
            }
            $actions .= '</div>';

            $rowsHtml .= '<tr>'
                . '<td class="nfse-nowrap">' . Ui::h($this->formatDate((string) ($row['criado_em'] ?? ''), 'd/m/Y H:i')) . '</td>'
                . '<td><div class="nfse-cell-stack">' . ReportView::invoiceLink($invoiceId) . ($invoiceStatus !== '' ? '<span class="nfse-cell-meta">' . Ui::h($invoiceStatus) . '</span>' : '') . '</div></td>'
                . '<td class="nfse-break">' . Ui::h($this->resolveClientName($row)) . '</td>'
                . '<td class="nfse-break">' . $nfse . '</td>'
                . '<td>' . Ui::h(AlertService::label((string) ($row['tipo'] ?? ''))) . '</td>'
                . '<td class="nfse-break">' . $details . '</td>'
                . '<td>' . $actions . '</td>'
                . '</tr>';
        }

        $shown = count($rows);
        echo ReportView::tableCard(
            ['Data', 'Fatura', 'Cliente', 'NFS-e', 'Tipo', 'Detalhes', 'Ações'],
            $rowsHtml,
            'Nenhum alerta encontrado para os filtros selecionados.',
            '',
            '',
            Ui::tableFooter($shown === 0
                ? 'Nenhum resultado'
                : ($truncated
                    ? 'Exibindo os <b>' . self::LIST_LIMIT . '</b> alertas mais recentes. Use os filtros para ver os demais.'
                    : 'Exibindo <b>' . $shown . '</b> alertas')),
            'nfse-alertas-titulo'
        );
        echo '</div>';

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
