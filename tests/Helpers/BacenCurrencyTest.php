<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\BacenCurrency;
use PHPUnit\Framework\TestCase;

final class BacenCurrencyTest extends TestCase
{
    public function testUsesBacenTableNotIsoNumeric(): void
    {
        $this->assertSame('790', BacenCurrency::fromIso('BRL'));
        $this->assertSame('220', BacenCurrency::fromIso('usd'));
        $this->assertSame('978', BacenCurrency::fromIso('EUR'));
        $this->assertSame('540', BacenCurrency::fromIso('GBP'));
        $this->assertSame('165', BacenCurrency::fromIso('CAD'));
        $this->assertSame('070', BacenCurrency::fromIso(' SEK '));
    }

    public function testEmptyIsRealAndDigitsPassThrough(): void
    {
        $this->assertSame(BacenCurrency::REAL, BacenCurrency::fromIso(''));
        $this->assertSame('220', BacenCurrency::fromIso('220'));
    }

    public function testUnknownCurrencyIsNull(): void
    {
        $this->assertNull(BacenCurrency::fromIso('XYZ'));
        $this->assertNull(BacenCurrency::fromIso('R$'));
    }
}
