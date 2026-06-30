<?php

declare (strict_types=1);
namespace OpenNfseVendor\PhpParser\Node\Expr;

use OpenNfseVendor\PhpParser\Node;
use OpenNfseVendor\PhpParser\Node\Arg;
use OpenNfseVendor\PhpParser\Node\Expr;
use OpenNfseVendor\PhpParser\Node\Identifier;
use OpenNfseVendor\PhpParser\Node\VariadicPlaceholder;
class MethodCall extends CallLike
{
    /** @var Expr Variable holding object */
    public Expr $var;
    /** @var Identifier|Expr Method name */
    public Node $name;
    /** @var array<Arg|VariadicPlaceholder> Arguments */
    public array $args;
    /**
     * Constructs a function call node.
     *
     * @param Expr $var Variable holding object
     * @param string|Identifier|Expr $name Method name
     * @param array<Arg|VariadicPlaceholder> $args Arguments
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function __construct(Expr $var, $name, array $args = [], array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->var = $var;
        $this->name = \is_string($name) ? new Identifier($name) : $name;
        $this->args = $args;
    }
    public function getSubNodeNames(): array
    {
        return ['var', 'name', 'args'];
    }
    public function getType(): string
    {
        return 'Expr_MethodCall';
    }
    public function getRawArgs(): array
    {
        return $this->args;
    }
}
