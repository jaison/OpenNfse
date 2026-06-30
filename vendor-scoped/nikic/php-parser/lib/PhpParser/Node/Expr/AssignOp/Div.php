<?php

declare (strict_types=1);
namespace OpenNfseVendor\PhpParser\Node\Expr\AssignOp;

use OpenNfseVendor\PhpParser\Node\Expr\AssignOp;
class Div extends AssignOp
{
    public function getType(): string
    {
        return 'Expr_AssignOp_Div';
    }
}
