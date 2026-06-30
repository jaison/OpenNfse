<?php

declare(strict_types=1);

use OpenNfse\Exceptions\NfseModuleException;
use OpenNfse\Module;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

function opennfse_config(): array
{
    return [
        'name' => 'OpenNFS-e',
        'description' => 'Emissão automática de NFS-e Nacional integrada ao WHMCS.',
        'author' => '<a href="https://github.com/jaison/OpenNfse/" target="_blank" rel="noopener noreferrer">Jaison Perazza</a>',
        'version' => '0.1.0',
        'language' => 'portuguese-br',
    ];
}

function opennfse_activate(): array
{
    try {
        Module::migrator()->up();
        return ['status' => 'success', 'description' => 'Módulo ativado e tabelas criadas/atualizadas.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => $e->getMessage()];
    }
}

function opennfse_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Módulo desativado.'];
}

function opennfse_output(array $vars): void
{
    try {
        $action = (string) ($_REQUEST['action'] ?? 'dashboard');
        Module::controller()->handle($action);
    } catch (NfseModuleException $e) {
        Module::ui()->renderError($e->getMessage());
    } catch (Throwable $e) {
        Module::ui()->renderError('Erro interno: ' . $e->getMessage());
    }
}

function opennfse_clientarea(array $vars): array
{
    try {
        $action = (string) ($_REQUEST['action'] ?? 'list');
        return Module::clientController()->handle($action, $vars);
    } catch (NfseModuleException $e) {
        return [
            'pagetitle' => 'Notas Fiscais',
            'breadcrumb' => ['index.php?m=OpenNfse' => 'Notas Fiscais'],
            'requirelogin' => true,
            'templatefile' => 'clienthome',
            'vars' => [
                'output' => '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>',
            ],
        ];
    } catch (Throwable $e) {
        return [
            'pagetitle' => 'Notas Fiscais',
            'breadcrumb' => ['index.php?m=OpenNfse' => 'Notas Fiscais'],
            'requirelogin' => true,
            'templatefile' => 'clienthome',
            'vars' => [
                'output' => '<div class="alert alert-danger">Erro interno.</div>',
            ],
        ];
    }
}
