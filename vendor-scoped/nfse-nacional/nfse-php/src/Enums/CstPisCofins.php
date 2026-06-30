<?php

namespace Nfse\Enums;

/**
 * Código da Situação Tributária do PIS/COFINS
 *
 * Baseado no schema: TSCST
 */
enum CstPisCofins : string
{
    /**
     * Nenhum
     */
    case Nenhum = '00';
    /**
     * Operação Tributável com Alíquota Básica
     */
    case OperacaoTributavelAliquotaBasica = '01';
    /**
     * Operação Tributável com Alíquota Diferenciada
     */
    case OperacaoTributavelAliquotaDiferenciada = '02';
    /**
     * Operação Tributável com Alíquota por Unidade de Medida de Produto
     */
    case OperacaoTributavelAliquotaPorUnidade = '03';
    /**
     * Operação Tributável Monofásica - Revenda a Alíquota Zero
     */
    case OperacaoTributavelMonofasicaAliquotaZero = '04';
    /**
     * Operação Tributável por Substituição Tributária
     */
    case OperacaoTributavelSubstituicaoTributaria = '05';
    /**
     * Operação Tributável a Alíquota Zero
     */
    case OperacaoTributavelAliquotaZero = '06';
    /**
     * Operação Isenta da Contribuição
     */
    case OperacaoIsenta = '07';
    /**
     * Operação sem Incidência da Contribuição
     */
    case OperacaoSemIncidencia = '08';
    /**
     * Operação com Suspensão da Contribuição
     */
    case OperacaoComSuspensao = '09';
    /**
     * Outras Operações de Saída
     */
    case OutrasOperacoesSaida = '49';
    /**
     * Operação com Direito a Crédito - Vinculada Exclusivamente a Receita Tributada no Mercado Interno
     */
    case CreditoReceitaTributadaMercadoInterno = '50';
    /**
     * Operação com Direito a Crédito - Vinculada Exclusivamente a Receita Não Tributada no Mercado Interno
     */
    case CreditoReceitaNaoTributadaMercadoInterno = '51';
    /**
     * Operação com Direito a Crédito - Vinculada Exclusivamente a Receita de Exportação
     */
    case CreditoReceitaExportacao = '52';
    /**
     * Operação com Direito a Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno
     */
    case CreditoReceitasTributadasENaoTributadasMercadoInterno = '53';
    /**
     * Operação com Direito a Crédito - Vinculada a Receitas Tributadas no Mercado Interno e de Exportação
     */
    case CreditoReceitasTributadasMercadoInternoEExportacao = '54';
    /**
     * Operação com Direito a Crédito - Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação
     */
    case CreditoReceitasNaoTributadasMercadoInternoEExportacao = '55';
    /**
     * Operação com Direito a Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação
     */
    case CreditoReceitasTributadasNaoTributadasEExportacao = '56';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita Tributada no Mercado Interno
     */
    case CreditoPresumidoReceitaTributadaMercadoInterno = '60';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita Não Tributada no Mercado Interno
     */
    case CreditoPresumidoReceitaNaoTributadaMercadoInterno = '61';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita de Exportação
     */
    case CreditoPresumidoReceitaExportacao = '62';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno
     */
    case CreditoPresumidoReceitasTributadasENaoTributadasMercadoInterno = '63';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas no Mercado Interno e de Exportação
     */
    case CreditoPresumidoReceitasTributadasMercadoInternoEExportacao = '64';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação
     */
    case CreditoPresumidoReceitasNaoTributadasMercadoInternoEExportacao = '65';
    /**
     * Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação
     */
    case CreditoPresumidoReceitasTributadasNaoTributadasEExportacao = '66';
    /**
     * Crédito Presumido - Outras Operações
     */
    case CreditoPresumidoOutrasOperacoes = '67';
    /**
     * Operação de Aquisição sem Direito a Crédito
     */
    case AquisicaoSemDireitoCredito = '70';
    /**
     * Operação de Aquisição com Isenção
     */
    case AquisicaoComIsencao = '71';
    /**
     * Operação de Aquisição com Alíquota Zero
     */
    case AquisicaoComAliquotaZero = '72';
    /**
     * Operação de Aquisição com Suspensão
     */
    case AquisicaoComSuspensao = '73';
    /**
     * Operação de Aquisição sem Incidência
     */
    case AquisicaoSemIncidencia = '74';
    /**
     * Operação de Aquisição por Substituição Tributária
     */
    case AquisicaoSubstituicaoTributaria = '75';
    /**
     * Outras Operações de Entrada
     */
    case OutrasOperacoesEntrada = '98';
    /**
     * Outras Operações
     */
    case OutrasOperacoes = '99';
    /**
     * Get description for the enum case
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Nenhum => 'Nenhum',
            self::OperacaoTributavelAliquotaBasica => 'Operação Tributável com Alíquota Básica',
            self::OperacaoTributavelAliquotaDiferenciada => 'Operação Tributável com Alíquota Diferenciada',
            self::OperacaoTributavelAliquotaPorUnidade => 'Operação Tributável com Alíquota por Unidade de Medida de Produto',
            self::OperacaoTributavelMonofasicaAliquotaZero => 'Operação Tributável Monofásica - Revenda a Alíquota Zero',
            self::OperacaoTributavelSubstituicaoTributaria => 'Operação Tributável por Substituição Tributária',
            self::OperacaoTributavelAliquotaZero => 'Operação Tributável a Alíquota Zero',
            self::OperacaoIsenta => 'Operação Isenta da Contribuição',
            self::OperacaoSemIncidencia => 'Operação sem Incidência da Contribuição',
            self::OperacaoComSuspensao => 'Operação com Suspensão da Contribuição',
            self::OutrasOperacoesSaida => 'Outras Operações de Saída',
            self::CreditoReceitaTributadaMercadoInterno => 'Operação com Direito a Crédito - Vinculada Exclusivamente a Receita Tributada no Mercado Interno',
            self::CreditoReceitaNaoTributadaMercadoInterno => 'Operação com Direito a Crédito - Vinculada Exclusivamente a Receita Não Tributada no Mercado Interno',
            self::CreditoReceitaExportacao => 'Operação com Direito a Crédito - Vinculada Exclusivamente a Receita de Exportação',
            self::CreditoReceitasTributadasENaoTributadasMercadoInterno => 'Operação com Direito a Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno',
            self::CreditoReceitasTributadasMercadoInternoEExportacao => 'Operação com Direito a Crédito - Vinculada a Receitas Tributadas no Mercado Interno e de Exportação',
            self::CreditoReceitasNaoTributadasMercadoInternoEExportacao => 'Operação com Direito a Crédito - Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação',
            self::CreditoReceitasTributadasNaoTributadasEExportacao => 'Operação com Direito a Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação',
            self::CreditoPresumidoReceitaTributadaMercadoInterno => 'Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita Tributada no Mercado Interno',
            self::CreditoPresumidoReceitaNaoTributadaMercadoInterno => 'Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita Não Tributada no Mercado Interno',
            self::CreditoPresumidoReceitaExportacao => 'Crédito Presumido - Operação de Aquisição Vinculada Exclusivamente a Receita de Exportação',
            self::CreditoPresumidoReceitasTributadasENaoTributadasMercadoInterno => 'Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno',
            self::CreditoPresumidoReceitasTributadasMercadoInternoEExportacao => 'Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas no Mercado Interno e de Exportação',
            self::CreditoPresumidoReceitasNaoTributadasMercadoInternoEExportacao => 'Crédito Presumido - Operação de Aquisição Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação',
            self::CreditoPresumidoReceitasTributadasNaoTributadasEExportacao => 'Crédito Presumido - Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação',
            self::CreditoPresumidoOutrasOperacoes => 'Crédito Presumido - Outras Operações',
            self::AquisicaoSemDireitoCredito => 'Operação de Aquisição sem Direito a Crédito',
            self::AquisicaoComIsencao => 'Operação de Aquisição com Isenção',
            self::AquisicaoComAliquotaZero => 'Operação de Aquisição com Alíquota Zero',
            self::AquisicaoComSuspensao => 'Operação de Aquisição com Suspensão',
            self::AquisicaoSemIncidencia => 'Operação de Aquisição sem Incidência',
            self::AquisicaoSubstituicaoTributaria => 'Operação de Aquisição por Substituição Tributária',
            self::OutrasOperacoesEntrada => 'Outras Operações de Entrada',
            self::OutrasOperacoes => 'Outras Operações',
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
