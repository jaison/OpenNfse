<?php

declare(strict_types=1);

namespace OpenNfse\Controllers;

use OpenNfse\Controllers\Support\AdminHelpersTrait;
use OpenNfse\Helpers\AddonVersion;
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
use OpenNfse\Updater\PackageDownloader;
use OpenNfse\Updater\PackageInstaller;
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
    public const ACTIONS = [
        'updater', 'updaterPause', 'updaterResume', 'updaterBackup', 'updaterBackupDownload',
        'updaterInstall', 'updaterVerify', 'updaterRollback', 'updaterConfirm',
    ];

    /** Etapas depois da troca das pastas: só sai delas confirmando ou voltando. */
    private const AFTER_SWAP = ['swapped', 'installed', 'verify_failed'];

    /** Classes que a checagem final carrega pelo autoload da versão nova. */
    private const VERIFY_CLASSES = [
        \OpenNfse\Module::class,
        \OpenNfse\Controllers\AdminController::class,
        \OpenNfse\Controllers\ClientController::class,
        \OpenNfse\Services\NfseService::class,
        \OpenNfse\Services\CronService::class,
        \OpenNfse\Services\QueueService::class,
    ];

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
        $this->renderInstallStep($paused, $state);

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
        if (!$this->canResume(MaintenanceMode::store()->state())) {
            $this->redirect('resume_blocked');
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
            preg_replace('/[^0-9A-Za-z.-]/', '', (string) ($state['from_version'] ?? '')),
            date('Ymd-His'),
            bin2hex(random_bytes(4))
        );

        @set_time_limit(0);
        try {
            $info = (new TableBackup(new PdoBackupDatabase()))->dump($file, (string) ($state['from_version'] ?? ''));
        } catch (\Throwable $e) {
            $this->log('UPDATER_BACKUP_ERROR', [], $e->getMessage());
            // Durante a pausa os relatórios não abrem: o erro fica visível na etapa 3.
            $data['backup_error'] = $e->getMessage();
            $this->saveData($data);
            $this->redirect('backup_failed');
        }

        unset($data['backup_error']);
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
            preg_replace('/[^0-9A-Za-z.-]/', '', (string) ($state['from_version'] ?? '')),
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
     * Etapa 4, ainda com o código atual: baixa, confere, extrai e troca as pastas.
     * A checagem final roda na próxima requisição (updaterVerify), já com o código novo.
     */
    public function install(): void
    {
        $state = MaintenanceMode::store()->state();
        $data = $this->data();
        if (!MaintenanceMode::isPaused() || !MaintenanceMode::isSettled([$this, 'cronIdle'])) {
            $this->redirect('not_settled');
        }
        if (empty($data['backup']['downloaded_at'])) {
            $this->redirect('backup_required');
        }
        if (in_array((string) ($state['step'] ?? ''), self::AFTER_SWAP, true)) {
            $this->redirect('');
        }
        if (empty($_POST['ack_manual_rollback'])) {
            $this->redirect('ack_required');
        }

        $status = $this->updateStatus();
        $toVersion = (string) ($state['to_version'] ?? '');
        $env = new WhmcsUpdaterEnvironment();
        $moduleDir = $env->moduleDir();
        $tempPath = $env->tempPath();
        $zip = rtrim($tempPath, '/') . '/opennfse-pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $extracted = '';
        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            if ($toVersion === '' || (string) $status['latest_version'] !== $toVersion || (string) $status['package_sha256'] === '') {
                throw new \RuntimeException('O pacote anunciado mudou desde a pausa. Retome a operação e comece de novo.');
            }
            $preflight = (new PreflightCheck($env))->run($status, (string) $status['current_version']);
            if (!$preflight['can_update']) {
                throw new \RuntimeException('A checagem do ambiente não passou mais. Retome a operação e confira a etapa 1.');
            }
            $this->saveData($data, 'installing');
            (new PackageDownloader())->download((string) $status['package_url'], $zip);
            $installer = new PackageInstaller();
            $extracted = $installer->prepare($zip, (string) $status['package_sha256'], $toVersion, $tempPath);
            $installer->stage($extracted, $moduleDir);
            // Depois da troca, o autoload aponta para os arquivos novos: carrega antes tudo o que
            // esta requisição ainda usa, para não misturar classes das duas versões.
            class_exists(LogRepository::class);
            class_exists(\OpenNfse\Updater\CapsuleUpdaterStore::class);
            $installer->swap($moduleDir);
        } catch (\Throwable $e) {
            @unlink($zip);
            if ($extracted !== '') {
                PackageInstaller::removeTree(dirname($extracted));
            }
            PackageInstaller::removeTree($moduleDir . PackageInstaller::NEW_SUFFIX);
            $data['install_error'] = $e->getMessage();
            $this->saveData($data, 'backup_ready');
            $this->log('UPDATER_INSTALL_ERROR', ['to' => $toVersion], $e->getMessage());
            $this->redirect('install_failed');
        }

        @unlink($zip);
        PackageInstaller::removeTree(dirname($extracted));
        unset($data['install_error']);
        $data['swapped_at'] = date('Y-m-d H:i:s');
        $this->saveData($data, 'swapped');
        $this->log('UPDATER_SWAP', ['from' => $state['from_version'] ?? '', 'to' => $toVersion, 'admin' => $this->adminId()]);
        header('Location: ' . self::URL . 'Verify');
        exit;
    }

    /**
     * Checagem final, já com o código da versão nova: migrations, versão e autoload.
     */
    public function verify(): void
    {
        $state = MaintenanceMode::store()->state();
        if (!MaintenanceMode::isPaused() || (string) ($state['step'] ?? '') !== 'swapped') {
            $this->redirect('');
        }
        $data = $this->data();
        $toVersion = (string) ($state['to_version'] ?? '');
        $moduleDir = (new WhmcsUpdaterEnvironment())->moduleDir();
        $items = [];

        $declared = PackageInstaller::declaredVersion($moduleDir);
        $items[] = ['label' => 'Versão instalada', 'ok' => $declared === $toVersion, 'detail' => (string) $declared];
        $missing = [];
        foreach (['OpenNfse.php', 'hooks.php', 'bootstrap.php', 'cron.php', 'vendor-scoped/autoload.php'] as $file) {
            if (!is_file($moduleDir . '/' . $file)) {
                $missing[] = $file;
            }
        }
        $items[] = ['label' => 'Arquivos principais', 'ok' => $missing === [], 'detail' => $missing === [] ? 'Todos no lugar.' : 'Faltam: ' . implode(', ', $missing)];
        $classErrors = [];
        foreach (self::VERIFY_CLASSES as $class) {
            try {
                if (!class_exists($class)) {
                    $classErrors[] = $class;
                }
            } catch (\Throwable $e) {
                $classErrors[] = $class . ' (' . $e->getMessage() . ')';
            }
        }
        $items[] = ['label' => 'Carregamento do código', 'ok' => $classErrors === [], 'detail' => $classErrors === [] ? 'As classes principais carregam.' : implode('; ', $classErrors)];
        try {
            (new Migrator())->up(true);
            $items[] = ['label' => 'Migrations', 'ok' => true, 'detail' => 'Aplicadas sem erro.'];
        } catch (\Throwable $e) {
            $items[] = ['label' => 'Migrations', 'ok' => false, 'detail' => $e->getMessage()];
        }

        $ok = !in_array(false, array_column($items, 'ok'), true);
        $data['verify'] = ['ok' => $ok, 'items' => $items, 'at' => date('Y-m-d H:i:s')];
        $this->saveData($data, $ok ? 'installed' : 'verify_failed');
        $this->log($ok ? 'UPDATER_VERIFY_OK' : 'UPDATER_VERIFY_FAIL', ['to' => $toVersion, 'items' => $items]);
        $this->redirect($ok ? 'verified' : 'verify_failed');
    }

    /**
     * Volta ao estado anterior: tabelas pelo backup e a pasta OpenNfse.old de volta ao lugar.
     * A operação continua pausada até "Retomar".
     */
    public function rollback(): void
    {
        $state = MaintenanceMode::store()->state();
        if (!MaintenanceMode::isPaused() || !in_array((string) ($state['step'] ?? ''), self::AFTER_SWAP, true)) {
            $this->redirect('');
        }
        $data = $this->data();
        $backup = is_array($data['backup'] ?? null) ? $data['backup'] : [];
        $sha = (string) ($backup['sha256'] ?? '');
        $moduleDir = (new WhmcsUpdaterEnvironment())->moduleDir();
        if (!is_dir($moduleDir . PackageInstaller::OLD_SUFFIX)) {
            $this->redirect('rollback_no_old');
        }

        $file = (string) ($backup['file'] ?? '');
        $uploaded = '';
        if ($file === '' || !is_file($file)) {
            $upload = $_FILES['backup_file'] ?? null;
            if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
                $this->redirect('rollback_upload_required');
            }
            $uploaded = rtrim(sys_get_temp_dir(), '/') . '/opennfse-restore-' . bin2hex(random_bytes(4)) . '.sql.gz';
            if (!move_uploaded_file((string) $upload['tmp_name'], $uploaded)) {
                $this->redirect('rollback_upload_required');
            }
            @chmod($uploaded, 0600);
            $file = $uploaded;
        }

        @set_time_limit(0);
        ignore_user_abort(true);
        try {
            (new TableBackup(new PdoBackupDatabase()))->restore($file, $sha);
            (new PackageInstaller())->rollbackFiles($moduleDir);
        } catch (\Throwable $e) {
            if ($uploaded !== '') {
                @unlink($uploaded);
            }
            $data['rollback_error'] = $e->getMessage();
            $this->saveData($data);
            $this->log('UPDATER_ROLLBACK_ERROR', [], $e->getMessage());
            $this->redirect('rollback_failed');
        }
        if ($uploaded !== '') {
            @unlink($uploaded);
        }
        unset($data['rollback_error']);
        $data['rolled_back_at'] = date('Y-m-d H:i:s');
        $this->saveData($data, 'rolled_back');
        $this->log('UPDATER_ROLLBACK', ['to' => $state['from_version'] ?? '', 'admin' => $this->adminId()]);
        $this->redirect('rolled_back');
    }

    /**
     * Confirma a versão nova: apaga a pasta anterior e a cópia do backup e retoma a operação.
     */
    public function confirm(): void
    {
        $state = MaintenanceMode::store()->state();
        if (!MaintenanceMode::isPaused() || (string) ($state['step'] ?? '') !== 'installed') {
            $this->redirect('');
        }
        (new PackageInstaller())->discardOld((new WhmcsUpdaterEnvironment())->moduleDir());
        $this->deleteBackupFile();
        MaintenanceMode::store()->saveState(['data' => null]);
        MaintenanceMode::resume();
        $replay = $this->replayDeferredEvents();
        $this->log('UPDATER_CONFIRM', [
            'from' => $state['from_version'] ?? '',
            'to' => $state['to_version'] ?? '',
            'admin' => $this->adminId(),
            'replay' => $replay,
        ]);
        $this->redirect('confirmed');
    }

    /**
     * Depois da troca, só retoma se os arquivos já voltaram para a versão anterior (pela tela ou à mão).
     */
    private function canResume(array $state): bool
    {
        if (!in_array((string) ($state['step'] ?? ''), self::AFTER_SWAP, true)) {
            return true;
        }

        return AddonVersion::current() === trim((string) ($state['from_version'] ?? ''));
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

        $resume = $this->canResume($state)
            ? '<form method="post" action="' . Ui::h(self::URL . 'Resume') . '">' . $hidden . Ui::button('Retomar operação', ['sm']) . '</form>'
            : '';
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
            $error = (string) ($this->data()['backup_error'] ?? '');
            $this->renderConfigBlock(
                ($error !== '' ? Ui::callout('danger', 'O backup não foi gerado', $error) : '')
                . '<p class="nfse-cfg-text">O arquivo traz a configuração do módulo, inclusive dados sensíveis. Guarde-o em local seguro.</p>'
            );
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

    private function renderInstallStep(bool $paused, array $state): void
    {
        $title = '4. Atualização';
        $description = 'Baixa o pacote, confere o SHA-256, troca os arquivos e faz a checagem final.';
        $data = $this->data();
        $step = (string) ($state['step'] ?? '');
        $to = (string) ($state['to_version'] ?? '');
        $from = (string) ($state['from_version'] ?? '');
        $token = (new TokenService())->token();
        $hidden = $token !== '' ? Ui::hidden(['token' => $token]) : '';

        if ($paused && $step === 'rolled_back') {
            $this->renderConfigSectionStart($title, $description);
            $this->renderConfigBlock('<p class="nfse-cfg-text">' . Ui::h(sprintf(
                'O módulo voltou para a versão %s e as tabelas foram restauradas. Retome a operação na etapa 2.',
                $from
            )) . '</p>');
            $this->renderConfigSectionEnd();

            return;
        }

        if (!$paused || empty($data['backup']['downloaded_at'])) {
            $this->renderPendingStep($title, $description, 'Disponível depois do download do backup.');

            return;
        }

        if (in_array($step, self::AFTER_SWAP, true)) {
            $this->renderAfterSwap($step, $data, $hidden, $from, $to);

            return;
        }

        $form = '<form method="post" action="' . Ui::h(self::URL . 'Install') . '">' . $hidden
            . '<label class="nfse-upd-ack"><input type="checkbox" name="ack_manual_rollback" value="1" required> '
            . Ui::h('Li como voltar à mão, caso o WHMCS não abra depois da troca.') . '</label>'
            . Ui::button('Instalar a versão ' . $to, ['primary', 'sm']) . '</form>';
        $this->renderConfigSectionStart($title, $description);
        $html = '';
        if (!empty($data['install_error'])) {
            $html .= Ui::callout('danger', 'A atualização não foi feita', (string) $data['install_error'] . ' Os arquivos atuais não foram alterados.');
        }
        $html .= self::manualRollbackHtml();
        $html .= $form;
        $this->renderConfigBlock($html);
        $this->renderConfigSectionEnd();
    }

    private function renderAfterSwap(string $step, array $data, string $hidden, string $from, string $to): void
    {
        $title = '4. Atualização';
        if ($step === 'swapped') {
            $this->renderConfigSectionStart($title, 'Arquivos trocados. Falta a checagem final.', Ui::button('Fazer a checagem final', ['primary', 'sm'], ['href' => self::URL . 'Verify']));
            $this->renderConfigBlock('<p class="nfse-cfg-text">A checagem roda as migrations e confere a versão nova.</p>');
            $this->renderConfigSectionEnd();

            return;
        }

        $verify = is_array($data['verify'] ?? null) ? $data['verify'] : ['items' => []];
        $actions = '';
        if ($step === 'installed') {
            $actions .= '<form method="post" action="' . Ui::h(self::URL . 'Confirm') . '">' . $hidden
                . Ui::button('Confirmar e retomar operação', ['primary', 'sm']) . '</form>';
        }
        $this->renderConfigSectionStart(
            $title,
            $step === 'installed'
                ? sprintf('Versão %s instalada. Confira o módulo e confirme, ou volte para a %s.', $to, $from)
                : sprintf('A checagem final falhou. Volte para a versão %s.', $from),
            $actions
        );
        $html = '<ul class="nfse-upd-checks">';
        foreach ((array) ($verify['items'] ?? []) as $item) {
            $html .= '<li class="nfse-upd-check"><span class="nfse-upd-check__label">' . Ui::h((string) ($item['label'] ?? '')) . '</span>'
                . (!empty($item['ok']) ? Ui::badge('OK', 'ok') : Ui::badge('Falhou', 'danger'))
                . '<span class="nfse-upd-check__detail">' . Ui::h((string) ($item['detail'] ?? '')) . '</span></li>';
        }
        $html .= '</ul>';
        if (!empty($data['rollback_error'])) {
            $html .= Ui::callout('danger', 'A volta não terminou', (string) $data['rollback_error'] . ' Siga a volta manual abaixo.');
        }

        $backup = is_array($data['backup'] ?? null) ? $data['backup'] : [];
        $needsUpload = (string) ($backup['file'] ?? '') === '' || !is_file((string) $backup['file']);
        $html .= '<form method="post" enctype="multipart/form-data" action="' . Ui::h(self::URL . 'Rollback') . '" class="nfse-upd-rollback">' . $hidden;
        if ($needsUpload) {
            $html .= '<label class="nfse-upd-ack">' . Ui::h('Arquivo de backup baixado na etapa 3:')
                . ' <input type="file" name="backup_file" accept=".gz,application/gzip" required></label>';
        }
        $html .= Ui::button('Voltar ao estado anterior (' . $from . ')', $step === 'installed' ? ['danger', 'sm'] : ['primary', 'sm']) . '</form>';
        $html .= self::manualRollbackHtml();
        $this->renderConfigBlock($html);
        $this->renderConfigSectionEnd();
    }

    private static function manualRollbackHtml(): string
    {
        return '<details class="nfse-form__more"><summary>Como voltar à mão</summary><div class="nfse-form__more-body">'
            . Ui::h('Se o WHMCS não abrir depois da troca: pelo FTP ou gerenciador de arquivos, em modules/addons/, renomeie a pasta OpenNfse para OpenNfse.falhou e a pasta OpenNfse.old para OpenNfse. Depois importe no banco o arquivo de backup baixado na etapa 3 (por exemplo, pelo phpMyAdmin). Com a versão anterior de volta, a tela de atualização libera o botão Retomar operação.')
            . '</div></details>';
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
            'backup_failed' => ['danger', 'Não foi possível gerar o backup. O erro aparece na etapa 3.'],
            'backup_missing' => ['danger', 'O arquivo de backup não está mais disponível.'],
            'backup_required' => ['warn', 'Baixe o backup antes de instalar.'],
            'ack_required' => ['warn', 'Marque que leu como voltar à mão.'],
            'install_failed' => ['danger', 'A atualização não foi feita. Os arquivos atuais não foram alterados.'],
            'verified' => ['ok', 'Versão nova instalada e conferida.'],
            'verify_failed' => ['danger', 'A checagem final falhou.'],
            'rolled_back' => ['ok', 'O módulo voltou para a versão anterior.'],
            'rollback_failed' => ['danger', 'A volta não terminou. Veja o erro na etapa 4.'],
            'rollback_no_old' => ['danger', 'A pasta da versão anterior não existe mais. Siga a volta manual.'],
            'rollback_upload_required' => ['warn', 'Envie o arquivo de backup baixado na etapa 3.'],
            'resume_blocked' => ['warn', 'Confirme a versão nova ou volte ao estado anterior antes de retomar.'],
            'confirmed' => ['ok', 'Atualização confirmada e operação retomada.'],
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
