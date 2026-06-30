<?php

namespace Nfse\Enums;

/**
 * Tipo de Imunidade do ISSQN
 */
enum TipoImunidade : int
{
    /**
     * Imunidade (tipo não informado na nota de origem)
     */
    case NaoInformado = 0;
    /**
     * Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)
     */
    case PatrimonioRendaServicos = 1;
    /**
     * Templos de qualquer culto (CF88, Art 150, VI, b)
     */
    case Templos = 2;
    /**
     * Partidos políticos, sindicatos, instituições de educação e assistência social (CF88, Art 150, VI, c)
     */
    case PartidosSindicatosInstituicoes = 3;
    /**
     * Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)
     */
    case LivrosJornaisPeriodicos = 4;
    /**
     * Fonogramas e videofonogramas musicais (CF88, Art 150, VI, e)
     */
    case FonogramasVideofonogramas = 5;
    public function getDescription(): string
    {
        return match ($this) {
            self::NaoInformado => 'Imunidade (tipo não informado na nota de origem)',
            self::PatrimonioRendaServicos => 'Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)',
            self::Templos => 'Templos de qualquer culto (CF88, Art 150, VI, b)',
            self::PartidosSindicatosInstituicoes => 'Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos, atendidos os requisitos da lei (CF88, Art 150, VI, c)',
            self::LivrosJornaisPeriodicos => 'Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)',
            self::FonogramasVideofonogramas => 'Fonogramas e videofonogramas musicais produzidos no Brasil (CF88, Art 150, VI, e)',
        };
    }
    public function label(): string
    {
        return $this->getDescription();
    }
}
