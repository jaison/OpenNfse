<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views;

use OpenNfse\Views\ReportView;
use PHPUnit\Framework\TestCase;

final class ReportViewTest extends TestCase
{
    public function testPostButtonKeepsTheSameFieldsWithoutInlineStyle(): void
    {
        $html = ReportView::postButton('relatoriosExport', 'Exportar CSV', ['tab' => 'emitidas', 'cliente' => 'A&B'], 'tok"en');

        $this->assertSame(
            '<form class="nfse-inline-form" method="post" action="addonmodules.php">'
            . '<input type="hidden" name="module" value="OpenNfse" /><input type="hidden" name="action" value="relatoriosExport" />'
            . '<input type="hidden" name="token" value="tok&quot;en" />'
            . '<input type="hidden" name="tab" value="emitidas" /><input type="hidden" name="cliente" value="A&amp;B" />'
            . '<button type="submit" class="nfse-btn nfse-btn--sm">Exportar CSV</button></form>',
            $html
        );
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('name="token"', ReportView::postButton('logView', 'Abrir', ['id' => 3], ''), 'sem token, sem campo');
        $this->assertStringContainsString('<button type="submit" class="nfse-chip">Outubro</button>', ReportView::postButton('relatoriosExportZip', 'Outubro', [], 't', 'nfse-chip'));
        $this->assertStringContainsString(
            'onclick="return confirm(&quot;Continuar?&quot;);"',
            ReportView::postButton('x', 'X', [], 't', 'nfse-btn', 'Continuar?')
        );
    }

    public function testTheadAndTableCard(): void
    {
        $this->assertSame(
            '<thead><tr><th>Data</th><th class="nfse-num">Valor</th></tr></thead>',
            ReportView::thead(['Data', ['Valor', 'nfse-num']])
        );

        $empty = ReportView::tableCard(['A', 'B', 'C'], '', 'Nada aqui.', 'Título & cia', '3 itens', '<div class="nfse-tfoot">F</div>', 'sec-1');
        $this->assertStringStartsWith('<section class="nfse-card nfse-card--table" aria-labelledby="sec-1">', $empty);
        $this->assertStringContainsString('<div class="nfse-card__head"><h3 class="nfse-card__title" id="sec-1">Título &amp; cia</h3><span class="nfse-card__summary">3 itens</span></div>', $empty);
        $this->assertStringContainsString('<div class="nfse-table-wrap"><table class="nfse-table"><thead>', $empty);
        $this->assertStringContainsString('<tbody><tr><td class="nfse-table__empty" colspan="3">Nada aqui.</td></tr></tbody>', $empty);
        $this->assertStringEndsWith('</table></div><div class="nfse-tfoot">F</div></section>', $empty);

        $rows = ReportView::tableCard(['A'], '<tr><td>1</td></tr>', 'Nada');
        $this->assertStringContainsString('<tbody><tr><td>1</td></tr></tbody>', $rows);
        $this->assertStringNotContainsString('nfse-card__head', $rows);
        $this->assertStringStartsWith('<section class="nfse-card nfse-card--table">', $rows);
    }

    public function testInfoCardAndKeyValues(): void
    {
        $html = ReportView::infoCard('Estado', [['Ambiente', 'producao'], ['Último NSU', 0], ['Vazio', ''], ['Link', '<a href="#">1</a>', true]], 'Nota <code>x</code>');

        $this->assertStringStartsWith('<section class="nfse-card nfse-card--pad nfse-rel-info"><h3 class="nfse-card__title">Estado</h3><dl class="nfse-dl">', $html);
        $this->assertStringContainsString('<div class="nfse-dl__item"><dt>Ambiente</dt><dd>producao</dd></div>', $html);
        $this->assertStringContainsString('<dt>Último NSU</dt><dd>0</dd>', $html);
        $this->assertStringContainsString('<dt>Vazio</dt><dd>-</dd>', $html);
        $this->assertStringContainsString('<dt>Link</dt><dd><a href="#">1</a></dd>', $html);
        $this->assertStringEndsWith('<div class="nfse-rel-note">Nota <code>x</code></div></section>', $html);
        $this->assertStringContainsString('<dd>&lt;b&gt;</dd>', ReportView::keyValues([['X', '<b>']]));
        $this->assertStringNotContainsString('nfse-card__title', ReportView::infoCard('', [['A', 'b']]));
    }

    public function testSmallHelpers(): void
    {
        $this->assertSame('abc', ReportView::orDash(' abc '));
        $this->assertSame('<span class="nfse-muted">-</span>', ReportView::orDash(''));
        $this->assertSame('&lt;x&gt;', ReportView::orDash('<x>'));
        $this->assertSame('<a href="invoices.php?action=edit&amp;id=12">12</a>', ReportView::invoiceLink(12));
        $this->assertSame('<span class="nfse-muted">-</span>', ReportView::invoiceLink(0));
        $this->assertSame(
            '<div class="nfse-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="40"><div class="nfse-progress__fill" id="bar" style="width:40%"></div></div>',
            ReportView::progress(40, 'bar')
        );
        $this->assertStringContainsString('aria-valuenow="100"', ReportView::progress(140));
        $this->assertStringContainsString('style="width:0%"', ReportView::progress(-5));
    }
}
