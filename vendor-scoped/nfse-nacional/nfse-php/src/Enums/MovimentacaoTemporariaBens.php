<?php

namespace Nfse\Enums;

/**
 * Vínculo da Operação à Movimentação Temporária de Bens
 *
 * Baseado no schema: TSMovTempBens
 */
enum MovimentacaoTemporariaBens : string
{
    /**
     * Nenhum
     */
    case Nenhum = '0';
    /**
     * Não
     */
    case Nao = '1';
    /**
     * Sim (Importação)
     */
    case SimImportacao = '2';
    /**
     * Sim (Exportação)
     */
    case SimExportacao = '3';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Nenhum => 'Nenhum',
            self::Nao => 'Não',
            self::SimImportacao => 'Sim (Importação)',
            self::SimExportacao => 'Sim (Exportação)',
        };
    }
    /**
     * Get label (alias for getDescription)
     */
    public function label(): string
    {
        return $this->getDescription();
    }
}
