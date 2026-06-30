<?php

declare(strict_types=1);

namespace OpenNfse\Dto;

final class ConsultarNfseResult
{
    public function __construct(
        public bool $found,
        public ?string $chaveAcesso = null,
        public ?string $nfseXml = null,
        public ?string $errorMessage = null,
        public ?string $rawResponse = null,
    ) {
    }
}

