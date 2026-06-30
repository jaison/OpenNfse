<?php

declare(strict_types=1);

namespace OpenNfse\Dto;

final class CancelarResult
{
    public function __construct(
        public bool $success,
        public ?string $errorType = null,
        public ?string $eventoXmlGZipB64 = null,
        public ?string $errorMessage = null,
        public ?string $rawResponse = null,
    ) {
    }
}

