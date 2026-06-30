<?php

declare (strict_types=1);
namespace OpenNfseVendor\PhpParser\Node\Stmt;

use OpenNfseVendor\PhpParser\Node\Stmt;
class InlineHTML extends Stmt
{
    /** @var string String */
    public string $value;
    /**
     * Constructs an inline HTML node.
     *
     * @param string $value String
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function __construct(string $value, array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->value = $value;
    }
    public function getSubNodeNames(): array
    {
        return ['value'];
    }
    public function getType(): string
    {
        return 'Stmt_InlineHTML';
    }
}
