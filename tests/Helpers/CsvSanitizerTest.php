<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Helpers;

use OpenNfse\Helpers\CsvSanitizer;
use PHPUnit\Framework\TestCase;

final class CsvSanitizerTest extends TestCase
{
    public static function dangerousValues(): array
    {
        return [
            'igual' => ['=HYPERLINK("http://x")', "'=HYPERLINK(\"http://x\")"],
            'mais' => ['+1+1', "'+1+1"],
            'menos' => ['-2+3', "'-2+3"],
            'arroba' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'cr' => ["\r=1", "'\r=1"],
        ];
    }

    /**
     * @dataProvider dangerousValues
     */
    public function testNeutralizesFormulaPrefixes(string $value, string $expected): void
    {
        $this->assertSame($expected, CsvSanitizer::cell($value));
    }

    public function testKeepsPlainValues(): void
    {
        $this->assertSame('Empresa Exemplo', CsvSanitizer::cell('Empresa Exemplo'));
        $this->assertSame('a=b', CsvSanitizer::cell('a=b'));
        $this->assertSame('2026-10-28 10:00:00', CsvSanitizer::cell('2026-10-28 10:00:00'));
        $this->assertSame('', CsvSanitizer::cell(''));
        $this->assertSame('', CsvSanitizer::cell(null));
    }

    public function testKeepsNumbersIncludingNegatives(): void
    {
        $this->assertSame('100.00', CsvSanitizer::cell('100.00'));
        $this->assertSame('-10.50', CsvSanitizer::cell('-10.50'));
        $this->assertSame('-10,50', CsvSanitizer::cell('-10,50'));
        $this->assertSame('42', CsvSanitizer::cell(42));
    }

    public function testRowSanitizesEveryCell(): void
    {
        $this->assertSame(['1', "'=cmd", 'ok'], CsvSanitizer::row([1, '=cmd', 'ok']));
    }
}
