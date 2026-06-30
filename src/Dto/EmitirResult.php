<?php

declare(strict_types=1);

namespace OpenNfse\Dto;

final class EmitirResult
{
    public function __construct(
        public bool $success,
        public ?string $errorType = null,
        public ?string $idDps = null,
        public ?string $chaveAcesso = null,
        public ?string $nfseXml = null,
        public ?string $errorMessage = null,
        public ?string $rawResponse = null,
    ) {
    }
}
