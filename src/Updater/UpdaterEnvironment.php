<?php

declare(strict_types=1);

namespace OpenNfse\Updater;

/**
 * O que o atualizador precisa saber do servidor e do WHMCS.
 * Separado para os testes rodarem sem WHMCS.
 */
interface UpdaterEnvironment
{
    /** Pasta do módulo (modules/addons/OpenNfse). */
    public function moduleDir(): string;

    /** Pasta temporária do atualizador do WHMCS (setting UpdateTempPath); '' se não configurada. */
    public function tempPath(): string;

    /** Versão do WHMCS instalada; '' se não der para ler. */
    public function whmcsVersion(): string;

    /** Raízes web usadas para decidir se um caminho é público. @return list<string> */
    public function webRoots(): array;
}
