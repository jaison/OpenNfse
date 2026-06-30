<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\GroupServiceCodeRepository;
use OpenNfse\Repositories\NotaRepository;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Repositories\QueueRepository;
use OpenNfse\Repositories\ReportRepository;
use OpenNfse\Repositories\ServiceNbsCatalogRepository;
use OpenNfse\Repositories\WhmcsInvoiceRepository;
use OpenNfse\Repositories\WhmcsPaymentGatewayRepository;
use OpenNfse\Services\CryptoService;
use OpenNfse\Services\InvoiceEmailService;
use OpenNfse\Services\NfseService;
use OpenNfse\Services\QueueErrorClassifierService;
use OpenNfse\Services\QueueService;
use OpenNfse\Services\StorageService;
use OpenNfse\Services\TokenService;
use WHMCS\Database\Capsule;
use OpenNfse\Controllers\Support\AdminHelpersTrait;

final class NotasController
{
    use AdminHelpersTrait;

    public function showNotas(): void
    {
        $invoiceFilter = (int) ($_REQUEST['invoiceid'] ?? 0);
        $statusFilter = trim((string) ($_REQUEST['status'] ?? ''));
        $updatedFrom = trim((string) ($_REQUEST['updated_from'] ?? ''));
        $updatedTo = trim((string) ($_REQUEST['updated_to'] ?? ''));

        Module::ui()->renderHeader('OpenNFS-e');
        $this->renderTabs('notas');

        $msg = (string) ($_REQUEST['msg'] ?? '');
        if ($msg === 'status_done') {
            echo '<div class="successbox">Consulta de status executada.</div>';
        } elseif ($msg === 'status_error') {
            echo '<div class="errorbox">Erro ao consultar status. Verifique os logs do módulo.</div>';
        } elseif ($msg === 'reemitir_enqueued') {
            echo '<div class="successbox">Reemissão enfileirada. O cron processará em breve.</div>';
        } elseif ($msg === 'reemitir_requested') {
            echo '<div class="successbox">Reemissão solicitada. Verifique o status e o XML na fatura.</div>';
        } elseif ($msg === 'reemitir_error') {
            echo '<div class="errorbox">Erro ao solicitar reemissão. Verifique os logs do módulo.</div>';
        } elseif ($msg === 'cancel_done') {
            echo '<div class="successbox">Cancelamento solicitado com sucesso.</div>';
        } elseif ($msg === 'cancel_error') {
            echo '<div class="errorbox">Erro ao cancelar NFS-e. Verifique os logs do módulo.</div>';
        }

        echo '<form method="get" action="addonmodules.php">';
        echo '<input type="hidden" name="module" value="OpenNfse" />';
        echo '<input type="hidden" name="action" value="notas" />';
        echo '<div style="margin-bottom:14px;border:1px solid #ddd;padding:12px;background:#fafafa;">';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">';
        echo '<div style="min-width:120px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Invoice ID</div>';
        echo '<input type="text" name="invoiceid" placeholder="Invoice ID" value="' . htmlspecialchars($invoiceFilter > 0 ? (string) $invoiceFilter : '', ENT_QUOTES, 'UTF-8') . '" style="width:120px;" />';
        echo '</div>';
        echo '<div style="min-width:180px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Status</div>';
        echo '<select name="status" style="width:180px;">';
        $statusOptions = [
            '' => 'Todos os status',
            'PROCESSANDO' => 'PROCESSANDO',
            'EMITIDA' => 'EMITIDA',
            'CANCELADA' => 'CANCELADA',
            'REJEITADA' => 'REJEITADA',
            'ERRO' => 'ERRO',
        ];
        foreach ($statusOptions as $val => $label) {
            $sel = $val === $statusFilter ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Atualizado de</div>';
        echo '<input type="date" name="updated_from" value="' . htmlspecialchars($updatedFrom, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '<div style="min-width:150px;">';
        echo '<div style="font-size:11px;color:#666;margin-bottom:4px;">Atualizado ate</div>';
        echo '<input type="date" name="updated_to" value="' . htmlspecialchars($updatedTo, ENT_QUOTES, 'UTF-8') . '" style="width:150px;" />';
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:200px;"></div>';
        echo '<div style="display:flex;gap:6px;align-items:flex-end;">';
        echo '<button type="submit" class="btn btn-xs btn-default">Filtrar</button>';
        echo '<a class="btn btn-xs btn-default" href="addonmodules.php?module=OpenNfse&action=notas">Limpar</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        $q = Capsule::table('mod_opennfse_notas')
            ->orderByRaw('competencia IS NULL, competencia DESC, emitida_em IS NULL, emitida_em DESC, updated_at DESC');
        if ($invoiceFilter > 0) {
            $q->where('invoiceid', $invoiceFilter);
        }
        if ($statusFilter !== '') {
            $q->where('status', $statusFilter);
        }
        if ($updatedFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedFrom)) {
            $q->where('updated_at', '>=', $updatedFrom . ' 00:00:00');
        }
        if ($updatedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updatedTo)) {
            $q->where('updated_at', '<=', $updatedTo . ' 23:59:59');
        }
        $rows = $q->limit(50)->get();

        echo '<table class="datatable" width="100%" cellspacing="0" cellpadding="3" style="font-size:12px;table-layout:fixed;width:100%;">';
        echo '<tr>';
        echo '<th style="width:7%;">Invoice</th>';
        echo '<th style="width:9%;">Status</th>';
        echo '<th style="width:9%;">NFS-e</th>';
        echo '<th style="width:8%;">Compet.</th>';
        echo '<th style="width:10%;">DPS</th>';
        echo '<th style="width:14%;">Chave</th>';
        echo '<th style="width:10%;">Emitida</th>';
        echo '<th style="width:10%;">Atualizado</th>';
        echo '<th style="width:10%;">Erro</th>';
        echo '<th style="width:13%;">Ações</th>';
        echo '</tr>';

        $token = (new TokenService())->token();
        $returnInvoice = $invoiceFilter > 0 ? (string) $invoiceFilter : '';
        $returnStatus = $statusFilter;
        $returnFrom = $updatedFrom;
        $returnTo = $updatedTo;
        $truncate = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '-';
            }
            $max = 10;
            if (mb_strlen($value) <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max) . '...';
        };
        $truncateError = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '-';
            }
            $max = 15;
            if (mb_strlen($value) <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max) . '...';
        };
        foreach ($rows as $r) {
            $invoiceId = (int) ($r->invoiceid ?? 0);
            $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;
            $logsUrl = 'addonmodules.php?module=OpenNfse&action=logs&invoiceid=' . $invoiceId;
            $status = (string) ($r->status ?? '');
            $numeroNf = (string) ($r->numero_nf ?? '');
            $competenciaRaw = (string) ($r->competencia ?? '');
            $competencia = '';
            if ($competenciaRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $competenciaRaw)) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $competenciaRaw);
                $competencia = $dt ? $dt->format('m/Y') : $competenciaRaw;
            } else {
                $competencia = $competenciaRaw !== '' ? $competenciaRaw : '-';
            }
            $idDps = (string) ($r->id_dps ?? '');
            $chave = (string) ($r->chave_acesso ?? '');
            $emitidaEmRaw = (string) ($r->emitida_em ?? '');
            $emitidaEm = '-';
            if ($emitidaEmRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $emitidaEmRaw)) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $emitidaEmRaw);
                $emitidaEm = $dt ? $dt->format('d/m/Y H:i') : $emitidaEmRaw;
            } elseif ($emitidaEmRaw !== '') {
                $emitidaEm = $emitidaEmRaw;
            }
            $updated = (string) ($r->updated_at ?? '');
            $xmlPath = (string) ($r->xml_path ?? '');
            $erroApi = (string) ($r->erro_api ?? '');

            echo '<tr>';
            echo '<td style="vertical-align:top;"><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">' . (int) $invoiceId . '</a></td>';
            echo '<td style="vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td style="vertical-align:top;">' . htmlspecialchars($numeroNf, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($competencia, ENT_QUOTES, 'UTF-8') . '</td>';
            if (trim($idDps) === '') {
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">-</td>';
            } else {
                $short = $truncate($idDps);
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;"><span title="' . htmlspecialchars($idDps, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span> <button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($idDps, ENT_QUOTES, 'UTF-8') . '">Copiar</button></td>';
            }
            if (trim($chave) === '') {
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">-</td>';
            } else {
                $short = $truncate($chave);
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;"><span title="' . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span> <button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($chave, ENT_QUOTES, 'UTF-8') . '">Copiar</button></td>';
            }
            echo '<td>' . htmlspecialchars($emitidaEm, ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($updated, ENT_QUOTES, 'UTF-8') . '</td>';
            if (trim($erroApi) === '') {
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;">-</td>';
            } else {
                $short = $truncateError($erroApi);
                echo '<td style="word-break:break-word;overflow-wrap:anywhere;"><span title="' . htmlspecialchars($erroApi, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span> <button type="button" class="btn btn-xs btn-default nfse-copy" data-copy="' . htmlspecialchars($erroApi, ENT_QUOTES, 'UTF-8') . '">Copiar</button></td>';
            }
            echo '<td>';
            $menuId = 'nfse-actions-' . $invoiceId;
            echo '<div class="nfse-actions" style="position:relative;display:inline-block;">';
            echo '<div style="display:inline-flex;align-items:stretch;">';
            if ($xmlPath !== '') {
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=downloadPdf" style="display:inline-block;margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
                echo '<button type="submit" class="btn btn-xs btn-default" style="border-top-right-radius:0;border-bottom-right-radius:0;white-space:normal;">PDF</button>';
                echo '</form>';
            } else {
                echo '<button type="button" class="btn btn-xs btn-default" disabled="disabled" style="border-top-right-radius:0;border-bottom-right-radius:0;white-space:normal;">PDF</button>';
            }
            echo '<button type="button" class="btn btn-xs btn-default nfse-actions-toggle" data-menu="' . htmlspecialchars($menuId, ENT_QUOTES, 'UTF-8') . '" style="border-top-left-radius:0;border-bottom-left-radius:0;border-left:0;min-width:28px;">▾</button>';
            echo '</div>';
            echo '<div id="' . htmlspecialchars($menuId, ENT_QUOTES, 'UTF-8') . '" class="nfse-actions-menu" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;min-width:180px;background:#fff;border:1px solid #ddd;box-shadow:0 2px 6px rgba(0,0,0,0.08);z-index:1000;">';

            echo '<a href="' . htmlspecialchars($logsUrl, ENT_QUOTES, 'UTF-8') . '" style="display:block;padding:6px 10px;text-decoration:none;">Logs</a>';

            if ($xmlPath !== '') {
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=downloadXml" style="margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
                echo '<button type="submit" class="nfse-actions-item" style="display:block;width:100%;text-align:left;padding:6px 10px;border:0;background:transparent;">Baixar XML</button>';
                echo '</form>';
            }

            echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=status" style="margin:0;">';
            if ($token !== '') {
                echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
            }
            echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
            echo '<input type="hidden" name="return" value="notas" />';
            if ($returnInvoice !== '') {
                echo '<input type="hidden" name="return_invoiceid" value="' . htmlspecialchars($returnInvoice, ENT_QUOTES, 'UTF-8') . '" />';
            }
            if ($returnStatus !== '') {
                echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($returnStatus, ENT_QUOTES, 'UTF-8') . '" />';
            }
            if ($returnFrom !== '') {
                echo '<input type="hidden" name="return_updated_from" value="' . htmlspecialchars($returnFrom, ENT_QUOTES, 'UTF-8') . '" />';
            }
            if ($returnTo !== '') {
                echo '<input type="hidden" name="return_updated_to" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '" />';
            }
            echo '<button type="submit" class="nfse-actions-item" style="display:block;width:100%;text-align:left;padding:6px 10px;border:0;background:transparent;">Consultar</button>';
            echo '</form>';

            if ($status === 'EMITIDA' && $chave !== '') {
                $cancelUrl = 'addonmodules.php?module=OpenNfse&action=cancelForm&invoiceid=' . $invoiceId;
                if ($returnInvoice !== '') {
                    $cancelUrl .= '&return_invoiceid=' . rawurlencode($returnInvoice);
                }
                if ($returnStatus !== '') {
                    $cancelUrl .= '&return_status=' . rawurlencode($returnStatus);
                }
                if ($returnFrom !== '') {
                    $cancelUrl .= '&return_updated_from=' . rawurlencode($returnFrom);
                }
                if ($returnTo !== '') {
                    $cancelUrl .= '&return_updated_to=' . rawurlencode($returnTo);
                }
                $cancelUrl .= '&return=notas';
                echo '<a href="' . htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') . '" style="display:block;padding:6px 10px;text-decoration:none;">Cancelar</a>';
            }

            if (in_array($status, ['REJEITADA', 'ERRO'], true)) {
                echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=reemitir" style="margin:0;">';
                if ($token !== '') {
                    echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
                echo '<input type="hidden" name="confirm" value="1" />';
                echo '<input type="hidden" name="return" value="notas" />';
                if ($returnInvoice !== '') {
                    echo '<input type="hidden" name="return_invoiceid" value="' . htmlspecialchars($returnInvoice, ENT_QUOTES, 'UTF-8') . '" />';
                }
                if ($returnStatus !== '') {
                    echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($returnStatus, ENT_QUOTES, 'UTF-8') . '" />';
                }
                if ($returnFrom !== '') {
                    echo '<input type="hidden" name="return_updated_from" value="' . htmlspecialchars($returnFrom, ENT_QUOTES, 'UTF-8') . '" />';
                }
                if ($returnTo !== '') {
                    echo '<input type="hidden" name="return_updated_to" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '" />';
                }
                echo '<button type="submit" class="nfse-actions-item" style="display:block;width:100%;text-align:left;padding:6px 10px;border:0;background:transparent;" onclick="return confirm(\'Reemitir gera uma nova DPS e pode gerar nova NFS-e. Continuar?\');">Reemitir</button>';
                echo '</form>';
            }

            echo '</div>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<script>(function(){function c(t){if(!t){return;}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);return;}var a=document.createElement(\'textarea\');a.value=t;a.style.position=\'fixed\';a.style.left=\'-9999px\';document.body.appendChild(a);a.focus();a.select();try{document.execCommand(\'copy\');}catch(e){}document.body.removeChild(a);}var b=document.querySelectorAll(\'.nfse-copy[data-copy]\');for(var i=0;i<b.length;i++){b[i].addEventListener(\'click\',function(){c(this.getAttribute(\'data-copy\')||\'\');});}function closeAll(){var ms=document.querySelectorAll(\'.nfse-actions-menu\');for(var j=0;j<ms.length;j++){ms[j].style.display=\'none\';}}document.addEventListener(\'click\',function(e){var t=e.target;var btn=t && t.closest ? t.closest(\'.nfse-actions-toggle\') : null;if(btn){e.preventDefault();var id=btn.getAttribute(\'data-menu\');if(!id){return;}var menu=document.getElementById(id);if(!menu){return;}var isOpen=menu.style.display===\'block\';closeAll();menu.style.display=isOpen?\'none\':\'block\';return;}var inside=t && t.closest ? t.closest(\'.nfse-actions\') : null;if(!inside){closeAll();}});})();</script>';

        Module::ui()->renderFooter();
    }


    public function showCancelForm(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            Module::ui()->renderError('Nota não encontrada para esta fatura.');
            return;
        }

        $status = (string) ($nota['status'] ?? '');
        $chave = (string) ($nota['chave_acesso'] ?? '');
        if ($status !== 'EMITIDA' || $chave === '') {
            Module::ui()->renderError('Cancelamento disponível apenas quando a nota está EMITIDA com chave de acesso.');
            return;
        }

        Module::ui()->renderHeader('Cancelar NFS-e');
        $this->renderTabs('notas');

        $token = (new TokenService())->token();
        $invoiceUrl = 'invoices.php?action=edit&id=' . $invoiceId;

        echo '<div style="margin-bottom:10px;"><a href="' . htmlspecialchars($invoiceUrl, ENT_QUOTES, 'UTF-8') . '">Voltar para a fatura</a></div>';

        echo '<form method="post" action="addonmodules.php?module=OpenNfse&action=cancel">';
        if ($token !== '') {
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
        }
        echo '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '" />';
        $return = (string) ($_REQUEST['return'] ?? '');
        if ($return !== '') {
            echo '<input type="hidden" name="return" value="' . htmlspecialchars($return, ENT_QUOTES, 'UTF-8') . '" />';
        }
        foreach (['return_invoiceid', 'return_status', 'return_updated_from', 'return_updated_to'] as $k) {
            $v = (string) ($_REQUEST[$k] ?? '');
            if ($v !== '') {
                echo '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" />';
            }
        }

        echo '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
        echo '<tr><td class="fieldlabel" width="25%">Código do motivo</td><td class="fieldarea"><select id="codigo_motivo" name="codigo_motivo" class="form-control">';
        echo '<option value="1">1 - Erro na emissão da NFS-e (será emitida outra nota correta)</option>';
        echo '<option value="2">2 - Serviço não prestado / cancelamento da prestação</option>';
        echo '<option value="9">9 - NFS-e emitida indevidamente</option>';
        echo '</select></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Motivo</td><td class="fieldarea"><input id="motivo" type="text" name="motivo" class="form-control" value="Erro na emissão" /></td></tr>';
        echo '<tr><td class="fieldlabel" width="25%">Descrição</td><td class="fieldarea"><input id="descricao" type="text" name="descricao" class="form-control" value="NFS-e cancelada em razão de erro identificado na emissão. Será emitida nova nota fiscal com os dados corretos." /></td></tr>';
        echo '</table>';

        echo '<script>';
        echo '(function(){';
        echo 'var map={';
        echo '"1":{m:"Erro na emissão",d:"NFS-e cancelada em razão de erro identificado na emissão. Será emitida nova nota fiscal com os dados corretos."},';
        echo '"2":{m:"Serviço não prestado",d:"NFS-e cancelada porque o serviço não foi prestado ao tomador, não gerando efeitos fiscais para a operação."},';
        echo '"9":{m:"Emissão indevida",d:"NFS-e cancelada por ter sido emitida indevidamente, não correspondendo à operação efetivamente realizada."}';
        echo '};';
        echo 'var sel=document.getElementById("codigo_motivo");';
        echo 'var motivo=document.getElementById("motivo");';
        echo 'var desc=document.getElementById("descricao");';
        echo 'if(!sel||!motivo||!desc){return;}';
        echo 'function apply(){var v=sel.value; if(!map[v]){return;} motivo.value=map[v].m; desc.value=map[v].d;}';
        echo 'sel.addEventListener("change", apply);';
        echo '})();';
        echo '</script>';

        echo '<p><button type="submit" class="btn btn-danger" onclick="return confirm(\'Cancelar NFS-e é uma operação fiscal. Continuar?\');">Cancelar NFS-e</button></p>';
        echo '</form>';

        Module::ui()->renderFooter();
    }


    public function cancelNfse(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }
        $codigo = trim((string) ($_REQUEST['codigo_motivo'] ?? ''));
        $motivo = trim((string) ($_REQUEST['motivo'] ?? ''));
        $descricao = trim((string) ($_REQUEST['descricao'] ?? ''));
        if ($codigo === '' || $motivo === '' || $descricao === '') {
            Module::ui()->renderError('Preencha código do motivo, motivo e descrição.');
            return;
        }

        $return = (string) ($_REQUEST['return'] ?? '');
        try {
            (new NfseService())->cancelarNfse($invoiceId, $codigo, $motivo, $descricao);
            if ($return === 'notas') {
                $this->redirectNotas(['msg' => 'cancel_done'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_cancel' => 'done']);
        } catch (\Throwable $e) {
            if ($return === 'notas') {
                $this->redirectNotas(['msg' => 'cancel_error'], true);
            }
            $this->redirectInvoice($invoiceId, ['nfse_cancel' => 'error']);
        }
    }


    public function downloadXml(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Invoice inválida.');
        }

        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if (!$nota) {
            throw new NfseModuleException('Nota não encontrada para esta fatura.');
        }

        $xmlPath = (string) ($nota['xml_path'] ?? '');
        if ($xmlPath === '') {
            throw new NfseModuleException('XML ainda não disponível.');
        }

        $storage = new StorageService();
        $absPath = $storage->resolveAbsolutePath($xmlPath);
        $filename = basename($absPath);

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($absPath));
        readfile($absPath);
        exit;
    }


    public function downloadPdf(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Fatura inválida.');
        }

        $pdfService = new \OpenNfse\Services\DanfsePdfService();
        $pdfBytes = $pdfService->generatePdfBytes($invoiceId);
        $size = strlen($pdfBytes);
        if ($size <= 0) {
            throw new NfseModuleException('PDF não disponível.');
        }
        $filename = $pdfService->getDownloadFilename($invoiceId);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) $size);
        echo $pdfBytes;
        exit;
    }


    public function sendEmail(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new NfseModuleException('Invoice inválida.');
        }

        try {
            (new InvoiceEmailService())->sendToClient($invoiceId);
            $this->redirectInvoice($invoiceId, ['nfse_email' => 'done']);
        } catch (\Throwable $e) {
            $this->redirectInvoice($invoiceId, ['nfse_email' => 'error']);
        }
    }


    public function reemitir(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ((string) ($_REQUEST['confirm'] ?? '') !== '1') {
            Module::ui()->renderError('Confirmação ausente para reemitir.');
            return;
        }
        $nota = (new NotaRepository())->findByInvoiceId($invoiceId);
        if ($nota) {
            $status = (string) ($nota['status'] ?? '');
            if (in_array($status, ['EMITIDA', 'PROCESSANDO'], true)) {
                Module::ui()->renderError('Não é permitido reemitir quando o status está EMITIDA ou PROCESSANDO.');
                return;
            }
        }
        $config = (new ConfigRepository())->get();
        $return = (string) ($_REQUEST['return'] ?? '');
        $returnInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? ''));
        $returnStatus = trim((string) ($_REQUEST['return_status'] ?? ''));
        $returnFrom = trim((string) ($_REQUEST['return_updated_from'] ?? ''));
        $returnTo = trim((string) ($_REQUEST['return_updated_to'] ?? ''));

        $redirectNotas = static function (string $msg) use ($returnInvoice, $returnStatus, $returnFrom, $returnTo): void {
            $url = 'addonmodules.php?module=OpenNfse&action=notas&msg=' . rawurlencode($msg);
            if ($returnInvoice !== '') {
                $url .= '&invoiceid=' . rawurlencode($returnInvoice);
            }
            if ($returnStatus !== '') {
                $url .= '&status=' . rawurlencode($returnStatus);
            }
            if ($returnFrom !== '') {
                $url .= '&updated_from=' . rawurlencode($returnFrom);
            }
            if ($returnTo !== '') {
                $url .= '&updated_to=' . rawurlencode($returnTo);
            }
            header('Location: ' . $url);
            exit;
        };

        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
            if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
                if ($return === 'notas') {
                    $redirectNotas('reemitir_gateway_disabled');
                }
                Module::ui()->renderError('Reemissão desativada para o gateway de pagamento desta fatura.');
                return;
            }
            if ((string) ($config['queue_enabled'] ?? '0') === '1') {
                (new QueueService())->enqueueEmit($invoiceId, 'QUEUE_REEMITIR_ADMIN');
                if ($return === 'notas') {
                    $redirectNotas('reemitir_enqueued');
                }
                Module::ui()->renderSuccess('Reemissão enfileirada. O cron processará em breve.');
                return;
            }

            (new NfseService())->emitir($invoiceId);
            if ($return === 'notas') {
                $redirectNotas('reemitir_requested');
            }
            Module::ui()->renderSuccess('Reemissão solicitada. Verifique o status e o XML na fatura.');
            return;
        } catch (\Throwable $e) {
            if ($return === 'notas') {
                $redirectNotas('reemitir_error');
            }
            Module::ui()->renderError('Erro ao solicitar reemissão: ' . $e->getMessage());
            return;
        }
    }

    public function emit(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }
        $config = (new ConfigRepository())->get();
        try {
            $invoice = (new WhmcsInvoiceRepository())->getInvoice($invoiceId);
            $paymentMethod = strtolower(trim((string) ($invoice['paymentmethod'] ?? '')));
            $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
            if ($invoiceStatus !== 'paid') {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'not_paid']);
            }
            $creditValue = (float) str_replace(',', '.', (string) ($invoice['credit'] ?? '0'));
            if ($paymentMethod === 'credit' || $creditValue > 0.00001) {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'credit_payment']);
            }
            if ($paymentMethod !== '' && !(new PaymentGatewaySettingsRepository())->isEnabled($paymentMethod)) {
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'gateway_disabled']);
            }
            if ((string) ($config['queue_enabled'] ?? '0') === '1') {
                $queueRepo = new QueueRepository();
                if ($queueRepo->hasActive($invoiceId)) {
                    $this->redirectInvoice($invoiceId, ['nfse_emit' => 'already_queued']);
                }
                (new QueueService())->enqueueEmit($invoiceId, 'QUEUE_ENQUEUE_MANUAL');
                $this->redirectInvoice($invoiceId, ['nfse_emit' => 'enqueued']);
            }

            (new NfseService())->emitir($invoiceId);
            $this->redirectInvoice($invoiceId, ['nfse_emit' => 'requested']);
        } catch (\Throwable $e) {
            $this->redirectInvoice($invoiceId, ['nfse_emit' => 'error', 'nfse_emit_detail' => $e->getMessage()]);
        }
    }

    public function status(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            Module::ui()->renderError('Invoice inválida.');
            return;
        }

        $return = (string) ($_REQUEST['return'] ?? '');
        try {
            (new NfseService())->consultarStatus($invoiceId);

            if ($return === 'notas') {
                $returnInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? ''));
                $returnStatus = trim((string) ($_REQUEST['return_status'] ?? ''));
                $returnFrom = trim((string) ($_REQUEST['return_updated_from'] ?? ''));
                $returnTo = trim((string) ($_REQUEST['return_updated_to'] ?? ''));
                $url = 'addonmodules.php?module=OpenNfse&action=notas';
                if ($returnInvoice !== '') {
                    $url .= '&invoiceid=' . rawurlencode($returnInvoice);
                }
                if ($returnStatus !== '') {
                    $url .= '&status=' . rawurlencode($returnStatus);
                }
                if ($returnFrom !== '') {
                    $url .= '&updated_from=' . rawurlencode($returnFrom);
                }
                if ($returnTo !== '') {
                    $url .= '&updated_to=' . rawurlencode($returnTo);
                }
                $url .= '&msg=status_done';
                header('Location: ' . $url);
                exit;
            }

            $this->redirectInvoice($invoiceId, ['nfse_status' => 'done']);
        } catch (\Throwable $e) {
            if ($return === 'notas') {
                $returnInvoice = trim((string) ($_REQUEST['return_invoiceid'] ?? ''));
                $returnStatus = trim((string) ($_REQUEST['return_status'] ?? ''));
                $returnFrom = trim((string) ($_REQUEST['return_updated_from'] ?? ''));
                $returnTo = trim((string) ($_REQUEST['return_updated_to'] ?? ''));
                $url = 'addonmodules.php?module=OpenNfse&action=notas&msg=status_error';
                if ($returnInvoice !== '') {
                    $url .= '&invoiceid=' . rawurlencode($returnInvoice);
                }
                if ($returnStatus !== '') {
                    $url .= '&status=' . rawurlencode($returnStatus);
                }
                if ($returnFrom !== '') {
                    $url .= '&updated_from=' . rawurlencode($returnFrom);
                }
                if ($returnTo !== '') {
                    $url .= '&updated_to=' . rawurlencode($returnTo);
                }
                header('Location: ' . $url);
                exit;
            }
            $this->redirectInvoice($invoiceId, ['nfse_status' => 'error']);
        }
    }
}
