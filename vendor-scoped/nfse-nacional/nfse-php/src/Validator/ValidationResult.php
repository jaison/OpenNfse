<?php

namespace Nfse\Validator;

class ValidationResult
{
    public function __construct(public readonly bool $isValid, public readonly array $errors = [])
    {
    }
    public static function success(): self
    {
        return new self(\true);
    }
    public static function failure(array $errors): self
    {
        return new self(\false, $errors);
    }
    public function getErrors(): array
    {
        return $this->errors;
    }
}
