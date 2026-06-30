declare namespace Nfse.Dto {
export type AtividadeEventoData = {
nome: string | null;
dataInicio: string | null;
dataFim: string | null;
idAtividadeEvento: string | null;
endereco: Nfse.Dto.EnderecoData | null;
};
export type BeneficioMunicipalData = {
percentualReducaoBcBm: number | null;
valorReducaoBcBm: number | null;
};
export type CodigoServicoData = {
codigoTributacaoNacional: string | null;
codigoTributacaoMunicipal: string | null;
descricaoServico: string | null;
codigoNbs: string | null;
codigoInternoContribuinte: string | null;
};
export type ComercioExteriorData = {
modoPrestacao: number | null;
vinculoPrestacao: number | null;
tipoMoeda: string | null;
valorServicoMoeda: number | null;
mecanismoApoioComexPrestador: string | null;
mecanismoApoioComexTomador: string | null;
movimentacaoTemporariaBens: string | null;
numeroDeclaracaoImportacao: string | null;
numeroRegistroExportacao: string | null;
mdic: string | null;
};
export type DeducaoReducaoData = {
percentualDeducaoReducao: number | null;
valorDeducaoReducao: number | null;
documentos: Array<Nfse.Dto.DocumentoDeducaoData> | null;
};
export type DescontoData = {
valorDescontoIncondicionado: number | null;
valorDescontoCondicionado: number | null;
};
export type DfeData = {
};
export type DocumentoDeducaoData = {
chaveNfse: string | null;
chaveNfe: string | null;
tipoDeducaoReducao: number | null;
descricaoOutrasDeducoes: string | null;
dataEmissaoDocumento: string | null;
valorDedutivelRedutivel: number | null;
valorDeducaoReducao: number | null;
};
export type EmitenteData = {
cnpj: string | null;
cpf: string | null;
inscricaoMunicipal: string | null;
nome: string | null;
nomeFantasia: string | null;
endereco: Nfse.Dto.EnderecoEmitenteData | null;
telefone: string | null;
email: string | null;
};
export type EnderecoData = {
codigoMunicipio: string | null;
cep: string | null;
logradouro: string | null;
numero: string | null;
bairro: string | null;
complemento: string | null;
enderecoExterior: Nfse.Dto.EnderecoExteriorData | null;
};
export type EnderecoEmitenteData = {
logradouro: string | null;
numero: string | null;
complemento: string | null;
bairro: string | null;
codigoMunicipio: string | null;
uf: string | null;
cep: string | null;
};
export type EnderecoExteriorData = {
codigoPais: string | null;
codigoEnderecamentoPostal: string | null;
cidade: string | null;
estadoProvinciaRegiao: string | null;
};
export type InfDpsData = {
id: string | null;
tipoAmbiente: number | null;
dataEmissao: string | null;
versaoAplicativo: string | null;
serie: string | null;
numeroDps: string | null;
dataCompetencia: string | null;
tipoEmitente: number | null;
codigoLocalEmissao: string | null;
motivoEmissaoTomadorIntermediario: string | null;
chaveNfseRejeitada: string | null;
substituicao: Nfse.Dto.SubstituicaoData | null;
prestador: Nfse.Dto.PrestadorData | null;
tomador: Nfse.Dto.TomadorData | null;
intermediario: Nfse.Dto.IntermediarioData | null;
servico: Nfse.Dto.ServicoData | null;
valores: Nfse.Dto.ValoresData | null;
};
export type InfNfseData = {
id: string | null;
numeroNfse: string | null;
numeroDfse: string | null;
codigoVerificacao: string | null;
dataProcessamento: string | null;
ambienteGerador: number | null;
versaoAplicativo: string | null;
processoEmissao: number | null;
localEmissao: string | null;
localPrestacao: string | null;
codigoLocalIncidencia: string | null;
nomeLocalIncidencia: string | null;
descricaoTributacaoNacional: string | null;
descricaoTributacaoMunicipal: string | null;
codigoStatus: number | null;
dps: any | null;
emitente: Nfse.Dto.EmitenteData | null;
valores: Nfse.Dto.ValoresNfseData | null;
};
export type IntermediarioData = {
cnpj: string | null;
cpf: string | null;
nif: string | null;
codigoNaoNif: string | null;
caepf: string | null;
inscricaoMunicipal: string | null;
nome: string | null;
endereco: Nfse.Dto.EnderecoData | null;
telefone: string | null;
email: string | null;
};
export type LocalPrestacaoData = {
codigoLocalPrestacao: string | null;
codigoPaisPrestacao: string | null;
};
export type ObraData = {
inscricaoImobiliariaFiscal: string | null;
codigoObra: string | null;
endereco: Nfse.Dto.EnderecoData | null;
};
export type PrestadorData = {
cnpj: string | null;
cpf: string | null;
nif: string | null;
codigoNaoNif: string | null;
caepf: string | null;
inscricaoMunicipal: string | null;
nome: string | null;
endereco: Nfse.Dto.EnderecoData | null;
telefone: string | null;
email: string | null;
regimeTributario: Nfse.Dto.RegimeTributarioData | null;
};
export type RegimeTributarioData = {
opcaoSimplesNacional: number | null;
regimeApuracaoTributariaSN: number | null;
regimeEspecialTributacao: number | null;
};
export type ServicoData = {
localPrestacao: Nfse.Dto.LocalPrestacaoData | null;
codigoServico: Nfse.Dto.CodigoServicoData | null;
comercioExterior: Nfse.Dto.ComercioExteriorData | null;
obra: Nfse.Dto.ObraData | null;
atividadeEvento: Nfse.Dto.AtividadeEventoData | null;
informacoesComplementares: string | null;
idDocumentoTecnico: string | null;
documentoReferencia: string | null;
descricaoInformacoesComplementares: string | null;
};
export type SubstituicaoData = {
chaveSubstituida: string | null;
codigoMotivo: string | null;
descricaoMotivo: string | null;
};
export type TomadorData = {
cpf: string | null;
cnpj: string | null;
nif: string | null;
codigoNaoNif: string | null;
caepf: string | null;
inscricaoMunicipal: string | null;
nome: string | null;
endereco: Nfse.Dto.EnderecoData | null;
telefone: string | null;
email: string | null;
};
export type TributacaoData = {
tributacaoIssqn: number | null;
tipoImunidade: number | null;
tipoRetencaoIssqn: number | null;
tipoSuspensao: number | null;
numeroProcessoSuspensao: string | null;
beneficioMunicipal: Nfse.Dto.BeneficioMunicipalData | null;
cstPisCofins: string | null;
percentualTotalTributosSN: number | null;
indicadorTotalTributos: number | null;
};
export type ValorServicoPrestadoData = {
valorRecebido: number | null;
valorServico: number | null;
};
export type ValoresData = {
valorServicoPrestado: Nfse.Dto.ValorServicoPrestadoData | null;
desconto: Nfse.Dto.DescontoData | null;
deducaoReducao: Nfse.Dto.DeducaoReducaoData | null;
tributacao: Nfse.Dto.TributacaoData | null;
};
export type ValoresNfseData = {
baseCalculo: number | null;
aliquotaAplicada: number | null;
valorIssqn: number | null;
valorLiquido: number | null;
};
}
