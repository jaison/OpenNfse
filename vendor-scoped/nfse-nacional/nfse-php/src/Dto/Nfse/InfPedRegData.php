<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class InfPedRegData extends Dto
{
    #[MapFrom('@attributes.Id')]
    public ?string $id = null;
    #[MapFrom('tpAmb')]
    public ?int $tipoAmbiente = null;
    #[MapFrom('verAplic')]
    public ?string $versaoAplicativo = null;
    #[MapFrom('dhEvento')]
    public ?string $dataHoraEvento = null;
    #[MapFrom('chNFSe')]
    public ?string $chaveNfse = null;
    #[MapFrom('CNPJAutor')]
    public ?string $cnpjAutor = null;
    #[MapFrom('CPFAutor')]
    public ?string $cpfAutor = null;
    #[MapFrom('nPedRegEvento')]
    public int $nPedRegEvento = 1;
    public string $tipoEvento = '101101';
    /**
     * === CANCELAMENTOS ===
     */
    #[MapFrom('e101101')]
    public ?\Nfse\Dto\Nfse\CancelamentoData $e101101 = null;
    #[MapFrom('e105102')]
    public ?\Nfse\Dto\Nfse\CancelamentoSubstituicaoData $e105102 = null;
    #[MapFrom('e101103')]
    public ?\Nfse\Dto\Nfse\AnaliseFiscalSolicitacaoData $e101103 = null;
    #[MapFrom('e105104')]
    public ?\Nfse\Dto\Nfse\AnaliseFiscalData $e105104 = null;
    #[MapFrom('e105105')]
    public ?\Nfse\Dto\Nfse\AnaliseFiscalData $e105105 = null;
    #[MapFrom('e305101')]
    public ?\Nfse\Dto\Nfse\CancelamentoPorOficioData $e305101 = null;
    /**
     * === CONFIRMAÇÕES ===
     */
    #[MapFrom('e202201')]
    public ?\Nfse\Dto\Nfse\ConfirmacaoPrestadorData $e202201 = null;
    #[MapFrom('e203202')]
    public ?\Nfse\Dto\Nfse\ConfirmacaoTomadorData $e203202 = null;
    #[MapFrom('e204203')]
    public ?\Nfse\Dto\Nfse\ConfirmacaoIntermediarioData $e204203 = null;
    #[MapFrom('e205204')]
    public ?\Nfse\Dto\Nfse\ConfirmacaoTacitaData $e205204 = null;
    /**
     * === REJEIÇÕES ===
     */
    #[MapFrom('e202205')]
    public ?\Nfse\Dto\Nfse\RejeicaoPrestadorData $e202205 = null;
    #[MapFrom('e203206')]
    public ?\Nfse\Dto\Nfse\RejeicaoTomadorData $e203206 = null;
    #[MapFrom('e204207')]
    public ?\Nfse\Dto\Nfse\RejeicaoIntermediarioData $e204207 = null;
    #[MapFrom('e205208')]
    public ?\Nfse\Dto\Nfse\AnulacaoRejeicaoData $e205208 = null;
    /**
     * === AÇÕES POR OFÍCIO ===
     */
    #[MapFrom('e305102')]
    public ?\Nfse\Dto\Nfse\BloqueioPorOficioData $e305102 = null;
    #[MapFrom('e305103')]
    public ?\Nfse\Dto\Nfse\DesbloqueioPorOficioData $e305103 = null;
    /**
     * === RESERVADOS PELO SCHEMA ===
     */
    #[MapFrom('e907202')]
    public mixed $e907202 = null;
    #[MapFrom('e967203')]
    public mixed $e967203 = null;
}
