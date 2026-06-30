<?php

namespace Nfse\Dto\Nfse;

use Nfse\Dto\Dto;
use Spatie\DataTransferObject\Attributes\MapFrom;
class PrestadorData extends Dto
{
    /**
     * CNPJ do prestador.
     * Obrigatório se não for pessoa física.
     */
    #[MapFrom('CNPJ')]
    public ?string $cnpj = null;
    /**
     * CPF do prestador.
     * Obrigatório se pessoa física.
     */
    #[MapFrom('CPF')]
    public ?string $cpf = null;
    /**
     * Número de Identificação Fiscal (NIF) do prestador.
     * Não permitido se tpEmit=1.
     */
    #[MapFrom('NIF')]
    public ?string $nif = null;
    /**
     * Código do motivo de não informar o NIF.
     */
    #[MapFrom('cNaoNIF')]
    public ?string $codigoNaoNif = null;
    /**
     * Cadastro de Atividade Econômica da Pessoa Física.
     */
    #[MapFrom('CAEPF')]
    public ?string $caepf = null;
    /**
     * Inscrição Municipal do prestador.
     */
    #[MapFrom('IM')]
    public ?string $inscricaoMunicipal = null;
    /**
     * Razão Social ou Nome do prestador.
     */
    #[MapFrom('xNome')]
    public ?string $nome = null;
    /**
     * Endereço do prestador.
     */
    #[MapFrom('end')]
    public ?\Nfse\Dto\Nfse\EnderecoData $endereco = null;
    /**
     * Telefone do prestador.
     */
    #[MapFrom('fone')]
    public ?string $telefone = null;
    /**
     * Email do prestador.
     */
    #[MapFrom('email')]
    public ?string $email = null;
    /**
     * Regime tributário do prestador.
     */
    #[MapFrom('regTrib')]
    public ?\Nfse\Dto\Nfse\RegimeTributarioData $regimeTributario = null;
}
