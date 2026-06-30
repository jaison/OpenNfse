<?php

declare (strict_types=1);
namespace OpenNfseVendor\PhpParser\Node\Stmt;

use OpenNfseVendor\PhpParser\Node\Identifier;
use OpenNfseVendor\PhpParser\Node\Stmt;
class Goto_ extends Stmt
{
    /** @var Identifier Name of label to jump to */
    public Identifier $name;
    /**
     * Constructs a goto node.
     *
     * @param string|Identifier $name Name of label to jump to
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function __construct($name, array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->name = \is_string($name) ? new Identifier($name) : $name;
    }
    public function getSubNodeNames(): array
    {
        return ['name'];
    }
    public function getType(): string
    {
        return 'Stmt_Goto';
    }
}
