<?php

declare(strict_types=1);

namespace OpenNfse\Dto;

final class ConsultarDpsResult
{
    public function __construct(
        public bool $found,
        public string $idDps,
        public ?string $chaveAcesso = null,
        public ?string $errorMessage = null,
        public ?string $rawResponse = null,
    ) {
    }
}

