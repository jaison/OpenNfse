<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\WebRoot;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Module;
use OpenNfse\Repositories\ConfigRepository;
use OpenNfse\Repositories\LogRepository;
use OpenNfse\Services\CronLockService;
use OpenNfse\Services\TokenService;
use OpenNfse\Services\UpdateCheckService;
use OpenNfse\Updater\HookGate;
use OpenNfse\Updater\MaintenanceMode;
use OpenNfse\Updater\PdoBackupDatabase;
use OpenNfse\Updater\PreflightCheck;
use OpenNfse\Updater\TableBackup;
use OpenNfse\Updater\WhmcsUpdaterEnvironment;
use OpenNfse\Views\Ui;

/**
 * Tela da atualização embutida: checagem, pausa, backup e troca dos arquivos.
 * Enquanto a pausa estiver ativa, é a única tela do módulo que abre no admin.
 */
final class UpdaterController
{
    use AdminHelpersTrait;

    /** Ações do admin que continuam abrindo durante a pausa. */
    public const ACTIONS = ['updater', 'updaterPause', 'updaterResume', 'updaterBackup', 'updaterBackupDownload'];

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
        $this->renderBackupStep($paused, $settled);
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
        // Retomar antes da troca dos arquivos cancela a atualização: a cópia do backup sai do servidor.
        $this->deleteBackupFile();
        MaintenanceMode::store()->saveState(['data' => null]);
        MaintenanceMode::resume();
        (new Migrator())->up();
        $replay = $this->replayDeferredEvents();
        $this->log('UPDATER_RESUME', ['admin' => $this->adminId(), 'replay' => $replay]);
        $this->redirect('resumed');
    }

    /**
     * Etapa 3: gera o backup das tabelas. Fica no servidor só se a pasta temporária estiver fora do webroot.
     */
    public function createBackup(): void
    {
        if (!MaintenanceMode::isPaused() || !MaintenanceMode::isSettled([$this, 'cronIdle'])) {
            $this->redirect('not_settled');
        }
        $data = $this->data();
        if (!empty($data['backup'])) {
            $this->redirect('');
        }

        $env = new WhmcsUpdaterEnvironment();
        $tempPath = $env->tempPath();
        $onServer = $tempPath !== '' && is_dir($tempPath) && is_writable($tempPath)
            && !WebRoot::contains($tempPath, $env->webRoots());
        $dir = $onServer ? $tempPath : sys_get_temp_dir();
        $state = MaintenanceMode::store()->state();
        $file = sprintf(
            '%s/opennfse-backup-%s-%s-%s.sql.gz',
            rtrim($dir, '/'),
            preg_replace('/[^0-9A-Za-z.]/', '', (string) ($state['from_version'] ?? '')),
            date('Ymd-His'),
            bin2hex(random_bytes(4))
        );

        @set_time_limit(0);
        try {
            $info = (new TableBackup(new PdoBackupDatabase()))->dump($file, (string) ($state['from_version'] ?? ''));
        } catch (\Throwable $e) {
            $this->log('UPDATER_BACKUP_ERROR', [], $e->getMessage());
            $this->redirect('backup_failed');
        }

        $data['backup'] = [
            'file' => $file,
            'sha256' => $info['sha256'],
            'bytes' => $info['bytes'],
            'rows' => $info['rows'],
            'tables' => count($info['tables']),
            'created_at' => date('Y-m-d H:i:s'),
            'on_server' => $onServer,
            'downloaded_at' => null,
        ];
        $this->saveData($data, 'backup_ready');
        $this->log('UPDATER_BACKUP', ['sha256' => $info['sha256'], 'bytes' => $info['bytes'], 'rows' => $info['rows'], 'on_server' => $onServer]);
        $this->redirect('backup_ready');
    }

    /**
     * Baixa o backup. Sem cópia no servidor, o arquivo é apagado depois do download.
     */
    public function downloadBackup(): void
    {
        $data = $this->data();
        $backup = $data['backup'] ?? null;
        $file = is_array($backup) ? (string) ($backup['file'] ?? '') : '';
        if (!MaintenanceMode::isPaused() || $file === '' || !is_file($file)) {
            $this->redirect('backup_missing');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $state = MaintenanceMode::store()->state();
        $name = sprintf(
            'opennfse-backup-%s-%s.sql.gz',
            preg_replace('/[^0-9A-Za-z.]/', '', (string) ($state['from_version'] ?? '')),
            date('Ymd-His', strtotime((string) ($backup['created_at'] ?? '')) ?: time())
        );
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        ignore_user_abort(true);
        readfile($file);
        flush();

        if (connection_status() === CONNECTION_NORMAL) {
            $data['backup']['downloaded_at'] = date('Y-m-d H:i:s');
            if (empty($backup['on_server'])) {
                @unlink($file);
                $data['backup']['file'] = '';
            }
            $this->saveData($data);
            $this->log('UPDATER_BACKUP_DOWNLOAD', ['admin' => $this->adminId(), 'on_server' => !empty($backup['on_server'])]);
        }
        exit;
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

    private function renderBackupStep(bool $paused, bool $settled): void
    {
        $title = '3. Backup das tabelas';
        $description = 'Gera o backup das tabelas do OpenNfse (configuração, notas, fila e logs). O download é obrigatório.';
        if (!$paused || !$settled) {
            $this->renderPendingStep($title, $description, $paused ? 'Disponível quando a pausa estiver ativa.' : 'Disponível depois da pausa.');

            return;
        }

        $backup = $this->data()['backup'] ?? null;
        $token = (new TokenService())->token();
        $hidden = $token !== '' ? Ui::hidden(['token' => $token]) : '';
        if (!is_array($backup)) {
            $form = '<form method="post" action="' . Ui::h(self::URL . 'Backup') . '">' . $hidden
                . Ui::button('Gerar backup', ['primary', 'sm']) . '</form>';
            $this->renderConfigSectionStart($title, $description, $form);
            $this->renderConfigBlock('<p class="nfse-cfg-text">O arquivo traz a configuração do módulo, inclusive dados sensíveis. Guarde-o em local seguro.</p>');
            $this->renderConfigSectionEnd();

            return;
        }

        $downloaded = !empty($backup['downloaded_at']);
        $fileAvailable = (string) ($backup['file'] ?? '') !== '' && is_file((string) $backup['file']);
        $actions = '';
        if ($fileAvailable) {
            $actions = '<form method="post" action="' . Ui::h(self::URL . 'BackupDownload') . '">' . $hidden
                . Ui::button($downloaded ? 'Baixar de novo' : 'Baixar backup', $downloaded ? ['sm'] : ['primary', 'sm']) . '</form>';
        }
        $this->renderConfigSectionStart($title, $description, $actions);
        $facts = [
            'Gerado em' => Ui::h(date('d/m/Y H:i', strtotime((string) ($backup['created_at'] ?? '')) ?: time())),
            'Tamanho' => Ui::h(number_format(((int) ($backup['bytes'] ?? 0)) / 1048576, 1, ',', '.') . ' MB'),
            'Tabelas / linhas' => Ui::h((int) ($backup['tables'] ?? 0) . ' / ' . number_format((int) ($backup['rows'] ?? 0), 0, ',', '.')),
            'SHA-256' => '<span class="nfse-mono">' . Ui::h((string) ($backup['sha256'] ?? '')) . '</span>',
            'Baixado em' => Ui::h($downloaded ? date('d/m/Y H:i', strtotime((string) $backup['downloaded_at']) ?: time()) : 'Ainda não'),
            'Cópia no servidor' => Ui::h(!empty($backup['on_server']) ? 'Sim, até você confirmar a atualização' : 'Não (pasta temporária dentro do webroot)'),
        ];
        $html = '<dl class="nfse-cfg-facts">';
        foreach ($facts as $label => $value) {
            $html .= '<dt>' . Ui::h($label) . '</dt><dd>' . $value . '</dd>';
        }
        $html .= '</dl>';
        if (!$downloaded) {
            $html = '<p class="nfse-cfg-text">Baixe o backup para continuar.</p>' . $html;
        }
        $this->renderConfigBlock($html);
        $this->renderConfigSectionEnd();
    }

    private function renderPendingStep(string $title, string $description, string $text = 'Disponível depois da pausa.'): void
    {
        $this->renderConfigSectionStart($title, $description);
        $this->renderConfigBlock('<p class="nfse-cfg-text">' . Ui::h($text) . '</p>');
        $this->renderConfigSectionEnd();
    }

    private function renderMessage(): void
    {
        $messages = [
            'paused' => ['ok', 'Operação pausada.'],
            'resumed' => ['ok', 'Operação retomada.'],
            'preflight_failed' => ['danger', 'A checagem encontrou itens que bloqueiam a atualização.'],
            'not_settled' => ['warn', 'Aguarde a pausa ficar ativa antes de gerar o backup.'],
            'backup_ready' => ['ok', 'Backup gerado. Baixe o arquivo para continuar.'],
            'backup_failed' => ['danger', 'Não foi possível gerar o backup. Veja o log UPDATER_BACKUP_ERROR.'],
            'backup_missing' => ['danger', 'O arquivo de backup não está mais disponível.'],
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

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $data = json_decode((string) (MaintenanceMode::store()->state()['data'] ?? ''), true);

        return is_array($data) ? $data : [];
    }

    private function saveData(array $data, ?string $step = null): void
    {
        $fields = ['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($step !== null) {
            $fields['step'] = $step;
        }
        MaintenanceMode::store()->saveState($fields);
    }

    private function deleteBackupFile(): void
    {
        $file = (string) ($this->data()['backup']['file'] ?? '');
        if ($file !== '' && is_file($file) && str_contains(basename($file), 'opennfse-backup-')) {
            @unlink($file);
        }
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
