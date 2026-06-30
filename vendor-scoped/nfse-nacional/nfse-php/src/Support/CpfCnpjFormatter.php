<?php

namespace Nfse\Support;

class CpfCnpjFormatter
{
    public static function formatCpf(string $cpf): string
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }
    public static function formatCnpj(string $cnpj): string
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
    }
    public static function unformat(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
    public static function formatCep(string $cep): string
    {
        $cep = preg_replace('/\D/', '', $cep);
        return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $cep);
    }
}
