<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Services\InvoiceHistoryService;
use OpenNfse\Services\NfseService;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * tblinvoices.notes aparece para o cliente na fatura e no PDF: só emissão e cancelamento entram lá.
 */
final class InvoiceHistoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        Capsule::$rows['tblinvoices'] = [(object) ['id' => 100, 'notes' => '']];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
    }

    public function testEmissionIsWrittenToInvoiceNotes(): void
    {
        (new InvoiceHistoryService())->appendEmission(100, '15', 'CHAVE123');

        $notes = $this->lastNotes();
        $this->assertMatchesRegularExpression('/^\[\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}\] \[NFS-e\] /', $notes);
        $this->assertStringEndsWith('NFS-e emitida com sucesso. Número: 15. Chave: CHAVE123.', $notes);
    }

    public function testEmissionWithoutNumeroOrChave(): void
    {
        (new InvoiceHistoryService())->appendEmission(100, null, ' ');

        $this->assertStringEndsWith('[NFS-e] NFS-e emitida com sucesso.', $this->lastNotes());
    }

    public function testCancellationIsWrittenToInvoiceNotes(): void
    {
        (new InvoiceHistoryService())->appendCancellation(100, 'Erro na emissão');

        $this->assertStringEndsWith('[NFS-e] NFS-e cancelada com sucesso. Motivo: Erro na emissão', $this->lastNotes());
    }

    public function testOrphanCancellationShowsItsChave(): void
    {
        (new InvoiceHistoryService())->appendCancellation(100, 'Duplicada', 'CHAVEORFA');

        $this->assertStringEndsWith(
            '[NFS-e] NFS-e órfã cancelada com sucesso. Chave: CHAVEORFA. Motivo: Duplicada',
            $this->lastNotes()
        );
    }

    public function testExistingNotesAreKept(): void
    {
        Capsule::$rows['tblinvoices'] = [(object) ['id' => 100, 'notes' => 'Nota do admin']];

        (new InvoiceHistoryService())->appendCancellation(100, 'Motivo');

        $this->assertStringStartsWith('Nota do admin' . PHP_EOL . '[', $this->lastNotes());
    }

    public function testInvalidInvoiceWritesNothing(): void
    {
        (new InvoiceHistoryService())->appendEmission(0, '1', 'CHAVE');

        $this->assertSame([], Capsule::$updates);
    }

    public function testOnlyEmissionAndCancellationArePublic(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(InvoiceHistoryService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        $this->assertSame(['appendCancellation', 'appendEmission'], $methods);
    }

    /**
     * E-mails, alertas, consultas de status e reemissão pelo admin vão só para o log do addon.
     */
    public function testOnlyNfseServiceWritesInvoiceNotes(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $writers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $content = (string) file_get_contents($file->getPathname());
            if ($relative === 'Services/InvoiceHistoryService.php') {
                continue;
            }
            if (str_contains($content, 'InvoiceHistoryService') || preg_match("/'notes'\s*=>/", $content)) {
                $writers[] = $relative;
            }
        }
        sort($writers);

        $this->assertSame(['Services/NfseService.php'], $writers);
    }

    public function testStatusCheckDoesNotWriteInvoiceNotes(): void
    {
        $method = new \ReflectionMethod(NfseService::class, 'consultarStatusSemEmail');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringNotContainsString('InvoiceHistoryService', $body);
        $this->assertStringContainsString('logConsultaStatus', $body);
    }

    private function lastNotes(): string
    {
        $updates = Capsule::$updates['tblinvoices'] ?? [];
        $this->assertCount(1, $updates);

        return (string) $updates[0]['notes'];
    }
}
