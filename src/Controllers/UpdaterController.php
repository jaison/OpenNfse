<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Services\CronLockService;
use OpenNfse\Services\TokenService;
use OpenNfse\Services\UpdateCheckService;
use OpenNfse\Updater\HookGate;
use OpenNfse\Updater\MaintenanceMode;
use OpenNfse\Updater\PreflightCheck;
use OpenNfse\Views\Ui;

/**
 * Tela da atualização embutida: checagem, pausa, backup e troca dos arquivos.
 * Enquanto a pausa estiver ativa, é a única tela do módulo que abre no admin.
 */
final class UpdaterController
{
    use AdminHelpersTrait;

    /** Ações do admin que continuam abrindo durante a pausa. */
    public const ACTIONS = ['updater', 'updaterPause', 'updaterResume'];

    private const URL = 'addonmodules.php?module=OpenNfse&action=updater';

    public function showUpdater(): void
    {
        $status = $this->updateStatus();
        $paused = MaintenanceMode::isPaused();
        $state = MaintenanceMode::store()->state();
        $preflight = $paused ? null : (new PreflightCheck())->run($status, (string) $status['current_version']);
        $settled = $paused && MaintenanceMode::isSettled([$this, 'cronIdle']);

        Module::ui()->renderHeader('Atualização - OpenNfse');
        $this->renderTabs('config', 'Atualização do módulo', $this->subtitle($status, $paused, $state));
        echo '<div class="nfse-ui nfse-page nfse-cfg"><div class="nfse-cfg-pane">';

        $this->renderMessage();
        if ($paused) {
            echo Ui::callout(
                'warn',
                'OpenNfse em manutenção',
                'A emissão, a fila, o cron e a área do cliente estão parados. As faturas pagas neste período ficam guardadas e são processadas quando a operação for retomada.'
            );
        }

        $this->renderPreflightStep($preflight, $paused);
        $this->renderPauseStep($preflight, $paused, $settled, $state);
        $this->renderPendingStep('3. Backup das tabelas', 'Gera e baixa o backup das tabelas do OpenNfse. O download é obrigatório.');
        $this->renderPendingStep('4. Atualização', 'Baixa o pacote, confere o SHA-256, troca os arquivos e faz a checagem final.');

        echo '</div></div>';
        if ($paused && !$settled) {
            // Atualiza sozinha até o processamento em andamento terminar.
            echo '<meta http-equiv="refresh" content="15">';
        }
        Module::ui()->renderFooter();
    }

    public function pause(): void
    {
        if (MaintenanceMode::isPaused()) {
            $this->redirect('');
        }
        $status = $this->updateStatus();
        $preflight = (new PreflightCheck())->run($status, (string) $status['current_version']);
        if (!$preflight['can_update']) {
            $this->redirect('preflight_failed');
        }

        MaintenanceMode::pause($this->adminId(), (string) $status['current_version'], (string) $status['latest_version']);
        $this->log('UPDATER_PAUSE', [
            'from' => $status['current_version'],
            'to' => $status['latest_version'],
            'admin' => $this->adminId(),
        ]);
        $this->redirect('paused');
    }

    public function resume(): void
    {
        if (!MaintenanceMode::isPaused()) {
            $this->redirect('');
        }
        MaintenanceMode::resume();
        (new Migrator())->up();
        $replay = $this->replayDeferredEvents();
        $this->log('UPDATER_RESUME', ['admin' => $this->adminId(), 'replay' => $replay]);
        $this->redirect('resumed');
    }

    /**
     * Sem cron do OpenNfse rodando agora (o lock está livre).
     */
    public function cronIdle(): bool
    {
        try {
            $lock = new CronLockService();
            $name = $lock->cronLockName();
            if (!$lock->acquire($name)) {
                return false;
            }
            $lock->release($name);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Roda já os eventos guardados, se o cron não estiver rodando; senão o próximo cron roda.
     *
     * @return array{replayed: int, failed: int, remaining: int}|null
     */
    private function replayDeferredEvents(): ?array
    {
        try {
            $lock = new CronLockService();
            $name = $lock->cronLockName();
            if (!$lock->acquire($name)) {
                return null;
            }
            try {
                return HookGate::replayPending();
            } finally {
                $lock->release($name);
            }
        } catch (\Throwable $e) {
            $this->log('UPDATER_REPLAY_ERROR', [], $e->getMessage());

            return null;
        }
    }

    private function renderPreflightStep(?array $preflight, bool $paused): void
    {
        if ($preflight === null) {
            $this->renderConfigSectionStart('1. Checagem', 'Concluída antes da pausa.');
            $this->renderConfigBlock('<p class="nfse-cfg-text">O ambiente passou na checagem.</p>');
            $this->renderConfigSectionEnd();

            return;
        }

        $actions = Ui::button('Verificar de novo', ['sm'], ['href' => self::URL]);
        $this->renderConfigSectionStart(
            '1. Checagem',
            'Confere se o servidor permite atualizar. Nada é alterado nesta etapa.',
            $actions
        );
        $html = '<ul class="nfse-upd-checks">';
        foreach ($preflight['items'] as $item) {
            [$text, $tone] = match ($item['status']) {
                PreflightCheck::OK => ['OK', 'ok'],
                PreflightCheck::WARN => ['Atenção', 'warn'],
                default => ['Bloqueia', 'danger'],
            };
            $html .= '<li class="nfse-upd-check">'
                . '<span class="nfse-upd-check__label">' . Ui::h($item['label']) . '</span>'
                . Ui::badge($text, $tone)
                . '<span class="nfse-upd-check__detail">' . Ui::h($item['detail']) . '</span>'
                . '</li>';
        }
        $html .= '</ul>';
        $this->renderConfigBlock($html);
        $this->renderConfigSectionEnd();
    }

    private function renderPauseStep(?array $preflight, bool $paused, bool $settled, array $state): void
    {
        $token = (new TokenService())->token();
        $hidden = $token !== '' ? Ui::hidden(['token' => $token]) : '';

        if (!$paused) {
            $canUpdate = (bool) ($preflight['can_update'] ?? false);
            $button = Ui::button('Pausar e continuar', ['primary', 'sm'], $canUpdate ? [] : ['disabled' => true]);
            $this->renderConfigSectionStart(
                '2. Pausa da operação',
                'Para a emissão, a fila, o cron e a área do cliente até o fim da atualização.',
                '<form method="post" action="' . Ui::h(self::URL . 'Pause') . '">' . $hidden . $button . '</form>'
            );
            $this->renderConfigBlock('<p class="nfse-cfg-text">'
                . Ui::h($canUpdate
                    ? 'Faturas pagas durante a pausa ficam guardadas e são processadas na volta. Você pode retomar a operação a qualquer momento antes da troca dos arquivos.'
                    : 'Resolva os itens que bloqueiam a checagem para continuar.')
                . '</p>');
            $this->renderConfigSectionEnd();

            return;
        }

        $resume = '<form method="post" action="' . Ui::h(self::URL . 'Resume') . '">' . $hidden
            . Ui::button('Retomar operação', ['sm']) . '</form>';
        $this->renderConfigSectionStart('2. Pausa da operação', 'A operação fica parada até você retomar.', $resume);
        $pausedAt = trim((string) ($state['paused_at'] ?? ''));
        $facts = [
            'Desde' => Ui::h($pausedAt !== '' ? date('d/m/Y H:i', strtotime($pausedAt) ?: time()) : '—'),
            'De → para' => Ui::h(trim((string) ($state['from_version'] ?? '')) . ' → ' . trim((string) ($state['to_version'] ?? ''))),
            'Eventos guardados' => Ui::h((string) MaintenanceMode::store()->countPendingEvents()),
        ];
        $html = '<p class="nfse-cfg-text">' . Ui::h($settled
            ? 'Pausa ativa. Nenhum processamento em andamento.'
            : 'Aguardando o processamento em andamento terminar. A página atualiza sozinha.') . '</p>';
        $html .= '<dl class="nfse-cfg-facts">';
        foreach ($facts as $label => $value) {
            $html .= '<dt>' . Ui::h($label) . '</dt><dd>' . $value . '</dd>';
        }
        $html .= '</dl>';
        $this->renderConfigBlock($html);
        $this->renderConfigSectionEnd();
    }

    private function renderPendingStep(string $title, string $description): void
    {
        $this->renderConfigSectionStart($title, $description);
        $this->renderConfigBlock('<p class="nfse-cfg-text">Disponível depois da pausa.</p>');
        $this->renderConfigSectionEnd();
    }

    private function renderMessage(): void
    {
        $messages = [
            'paused' => ['ok', 'Operação pausada.'],
            'resumed' => ['ok', 'Operação retomada.'],
            'preflight_failed' => ['danger', 'A checagem encontrou itens que bloqueiam a atualização.'],
        ];
        $key = (string) ($_GET['msg'] ?? '');
        if (isset($messages[$key])) {
            echo Ui::callout($messages[$key][0], $messages[$key][1]);
        }
    }

    private function subtitle(array $status, bool $paused, array $state): string
    {
        if ($paused) {
            return sprintf(
                'Atualizando de %s para %s.',
                trim((string) ($state['from_version'] ?? '')),
                trim((string) ($state['to_version'] ?? ''))
            );
        }
        if (!empty($status['update_available'])) {
            return sprintf('Versão instalada %s; disponível %s.', $status['current_version'], $status['latest_version']);
        }

        return sprintf('Versão instalada %s.', $status['current_version']);
    }

    private function updateStatus(): array
    {
        return (new UpdateCheckService())->getStatus((new ConfigRepository())->get());
    }

    private function adminId(): int
    {
        return (int) ($_SESSION['adminid'] ?? 0);
    }

    private function log(string $type, array $data, ?string $error = null): void
    {
        try {
            (new LogRepository())->insert(null, $type, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $error);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return never
     */
    private function redirect(string $msg): void
    {
        header('Location: ' . self::URL . ($msg !== '' ? '&msg=' . rawurlencode($msg) : ''));
        exit;
    }
}
