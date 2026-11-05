<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Views;

use OpenNfse\Services\UiService;
use OpenNfse\Views\Ui;
use PHPUnit\Framework\TestCase;

final class UiTest extends TestCase
{
    public function testAttrsAndHidden(): void
    {
        $this->assertSame(' type="text" required data-x="a&quot;b"', Ui::attrs(['type' => 'text', 'required' => true, 'off' => false, 'none' => null, 'data-x' => 'a"b']));
        $this->assertSame(
            '<input type="hidden" name="module" value="OpenNfse" /><input type="hidden" name="page" value="2" />',
            Ui::hidden(['module' => 'OpenNfse', 'page' => 2])
        );
        $this->assertSame('<input type="hidden" name="x" value="&lt;b&gt;" />', Ui::hidden(['x' => '<b>']));
    }

    public function testSectionHeader(): void
    {
        $this->assertSame(
            '<div class="nfse-sechead"><div class="nfse-sechead__text"><h2 class="nfse-sechead__title" id="s1">Itens da fila</h2>'
            . '<div class="nfse-sechead__summary">Resumo &amp; mais</div></div><div class="nfse-sechead__actions"><button>x</button></div></div>',
            Ui::sectionHeader('Itens da fila', 'Resumo & mais', '<button>x</button>', 's1')
        );
        $this->assertSame(
            '<div class="nfse-sechead"><div class="nfse-sechead__text"><h2 class="nfse-sechead__title">&lt;script&gt;</h2></div></div>',
            Ui::sectionHeader('<script>')
        );
    }

    public function testSubnav(): void
    {
        $html = Ui::subnav([
            ['group' => 'Notas'],
            ['label' => 'Emitidas', 'href' => 'addonmodules.php?module=OpenNfse&action=relatorios&tab=emitidas', 'current' => true],
            ['label' => 'Certificado', 'href' => '#c', 'pill' => 'Expira 27d', 'tone' => 'warn'],
            ['label' => 'Logs', 'href' => '#l', 'meta' => 'Técnico'],
            ['label' => 'Fila', 'href' => '#f', 'dot' => 'danger'],
        ], 'Seções do relatório');

        $this->assertStringStartsWith('<nav class="nfse-card nfse-subnav" aria-label="Seções do relatório"><div class="nfse-subnav__group">Notas</div>', $html);
        $this->assertStringContainsString(
            '<a class="nfse-subnav__item" href="addonmodules.php?module=OpenNfse&amp;action=relatorios&amp;tab=emitidas" aria-current="page"><span class="nfse-subnav__label">Emitidas</span></a>',
            $html
        );
        $this->assertStringContainsString('<span class="nfse-subnav__label">Certificado</span><span class="nfse-badge nfse-badge--warn">Expira 27d</span></a>', $html);
        $this->assertStringContainsString('<span class="nfse-subnav__meta">Técnico</span>', $html);
        $this->assertStringContainsString('<span class="nfse-subnav__dot nfse-subnav__dot--danger" aria-hidden="true"></span>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current'));

        $this->assertSame(
            '<div class="nfse-withnav"><nav></nav><div class="nfse-withnav__content"><p>c</p></div></div>',
            Ui::withNav('<nav></nav>', '<p>c</p>')
        );
    }

    public function testFilterBarFieldInputSelect(): void
    {
        $html = Ui::filterBar(
            ['id' => 'f1', 'hidden' => ['module' => 'OpenNfse', 'action' => 'fila']],
            Ui::field('Data inicial', Ui::input('data_inicial', '2026-11-01', 'date')),
            Ui::button('Filtrar', ['primary']) . Ui::button('Limpar', ['ghost'], ['href' => 'addonmodules.php?module=OpenNfse&action=fila'])
        );

        $this->assertStringStartsWith(
            '<section class="nfse-card nfse-filterbar" aria-label="Filtros"><form class="nfse-filterbar__form" id="f1" method="get" action="addonmodules.php">'
            . '<input type="hidden" name="module" value="OpenNfse" /><input type="hidden" name="action" value="fila" />',
            $html
        );
        $this->assertStringContainsString(
            '<label class="nfse-field"><span class="nfse-field__label">Data inicial</span><input type="date" name="data_inicial" value="2026-11-01" class="nfse-input" /></label>',
            $html
        );
        $this->assertStringContainsString(
            '<div class="nfse-filterbar__actions"><button type="submit" class="nfse-btn nfse-btn--primary">Filtrar</button>'
            . '<a class="nfse-btn nfse-btn--ghost" href="addonmodules.php?module=OpenNfse&amp;action=fila">Limpar</a></div></form></section>',
            $html
        );

        $this->assertSame(
            '<select name="status" class="nfse-input nfse-input--w-md"><option value="">Todos</option><option value="ERROR" selected>Erro &amp; cia</option></select>',
            Ui::select('status', ['' => 'Todos', 'ERROR' => 'Erro & cia'], 'ERROR', [], 'nfse-input--w-md')
        );
        $this->assertSame('<input type="text" name="q" value="&quot;x&quot;" class="nfse-input" placeholder="ID" />', Ui::input('q', '"x"', 'text', ['placeholder' => 'ID']));
    }

    public function testButtonVariants(): void
    {
        $this->assertSame('<button type="submit" class="nfse-btn">Salvar</button>', Ui::button('Salvar'));
        $this->assertSame('<button type="button" class="nfse-btn nfse-btn--sm nfse-copy" data-copy="a">Copiar</button>', Ui::button('Copiar', ['sm'], ['type' => 'button', 'class' => 'nfse-copy', 'data-copy' => 'a']));
        $this->assertSame('<a class="nfse-btn nfse-btn--danger" href="x?a=1&amp;b=2">Cancelar &lt;NFS-e&gt;</a>', Ui::button('Cancelar <NFS-e>', ['danger'], ['href' => 'x?a=1&b=2']));
    }

    public function testBadges(): void
    {
        $this->assertSame('<span class="nfse-badge">PENDING</span>', Ui::badge('PENDING'));
        $this->assertSame('<span class="nfse-badge nfse-badge--warn">Expira</span>', Ui::badge('Expira', 'warn'));
        $map = [
            'EMITIDA' => 'nfse-badge--emitida',
            'CANCELADA' => 'nfse-badge--cancelada',
            'ERRO' => 'nfse-badge--erro',
            'ERROR' => 'nfse-badge--erro',
            'RESOLVIDO' => 'nfse-badge--erro',
            'REJEITADA' => 'nfse-badge--danger',
            'DONE' => 'nfse-badge--ok',
        ];
        foreach ($map as $status => $class) {
            $this->assertSame('<span class="nfse-badge ' . $class . '">' . $status . '</span>', Ui::statusBadge($status), $status);
        }
        $this->assertSame('<span class="nfse-badge">WAIT_STATUS</span>', Ui::statusBadge('WAIT_STATUS'), 'aguardando é neutro');
        $this->assertSame('<span class="nfse-badge">&lt;x&gt;</span>', Ui::statusBadge('<x>'));
    }

    public function testCallout(): void
    {
        $html = Ui::callout('warn', 'Atenção', 'Texto & mais', ' <a href="#">Ver</a>');
        $this->assertStringStartsWith('<div class="nfse-callout nfse-callout--warn" role="alert"><svg class="nfse-callout__icon"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringEndsWith(
            '<div class="nfse-callout__body"><div class="nfse-callout__title">Atenção</div><div class="nfse-callout__text">Texto &amp; mais <a href="#">Ver</a></div></div></div>',
            $html
        );
        $this->assertStringStartsWith('<div class="nfse-callout nfse-callout--ok" role="status">', Ui::callout('ok', 'Salvo'));
        $this->assertStringNotContainsString('nfse-callout__text', Ui::callout('ok', 'Salvo'));
        $this->assertStringStartsWith('<div class="nfse-callout nfse-callout--info"', Ui::callout('qualquer', 'x'), 'tom desconhecido vira info');
        $this->assertStringContainsString('&lt;b&gt;', Ui::callout('danger', '<b>', '<i>'));
        $this->assertStringNotContainsString('<b>', Ui::callout('danger', '<b>', '<i>'));
    }

    public function testKpis(): void
    {
        $html = Ui::kpis([
            ['label' => 'Itens', 'value' => 1200],
            ['label' => 'Erros', 'value' => 3, 'tone' => 'danger'],
            ['label' => 'Pendentes', 'value' => 0],
            ['label' => 'Taxa', 'value' => '99,5%'],
        ]);

        $this->assertStringStartsWith('<div class="nfse-kpis nfse-kpis--sm">', $html);
        $this->assertStringContainsString('<div class="nfse-kpi"><div class="nfse-kpi__label">Itens</div><div class="nfse-kpi__value">1.200</div></div>', $html);
        $this->assertStringContainsString('<div class="nfse-kpi__value nfse-kpi__value--danger">3</div>', $html);
        $this->assertStringContainsString('<div class="nfse-kpi__value nfse-kpi__value--zero">0</div>', $html);
        $this->assertStringContainsString('<div class="nfse-kpi__value">99,5%</div>', $html);
        $this->assertStringStartsWith('<div class="nfse-kpis">', Ui::kpis([], false));
    }

    public function testTableFooterRangeAndEmptyRow(): void
    {
        $this->assertSame('Exibindo <b>1–50</b> de <b>1.200</b> itens', Ui::rangeText(1, 50, 1200));
        $this->assertSame('Nenhum resultado', Ui::rangeText(0, 0, 0));
        $this->assertSame(
            '<div class="nfse-tfoot"><div class="nfse-tfoot__summary">S</div><div class="nfse-tfoot__tools">T</div></div>',
            Ui::tableFooter('S', 'T')
        );
        $this->assertSame('<div class="nfse-tfoot"><div class="nfse-tfoot__summary">S</div></div>', Ui::tableFooter('S'));
        $this->assertSame('<tr><td class="nfse-table__empty" colspan="13">Nada &amp; nada</td></tr>', Ui::emptyRow(13, 'Nada & nada'));
    }

    public function testPager(): void
    {
        $params = ['module' => 'OpenNfse', 'action' => 'fila', 'status' => 'ERROR'];
        $middle = Ui::pager($params, 2, 3);
        $this->assertStringContainsString('<a class="nfse-btn nfse-btn--sm" href="addonmodules.php?module=OpenNfse&amp;action=fila&amp;status=ERROR&amp;page=1">Anterior</a>', $middle);
        $this->assertStringContainsString('<span class="nfse-pager__current">Página 2 de 3</span>', $middle);
        $this->assertStringContainsString('&amp;page=3">Próxima</a>', $middle);

        $only = Ui::pager($params, 1, 1);
        $this->assertStringContainsString('<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Anterior</span>', $only);
        $this->assertStringContainsString('<span class="nfse-btn nfse-btn--sm" aria-disabled="true">Próxima</span>', $only);
        $this->assertStringContainsString('Página 1 de 1', Ui::pager($params, 9, 0), 'página fora do intervalo é ajustada');
    }

    public function testPageForm(): void
    {
        $html = Ui::pageForm(['module' => 'OpenNfse', 'action' => 'fila', 'status' => ''], 2, 3, 100, [50, 100, 500]);

        $this->assertStringStartsWith(
            '<form class="nfse-tfoot__form" method="get" action="addonmodules.php"><input type="hidden" name="module" value="OpenNfse" />'
            . '<input type="hidden" name="action" value="fila" /><input type="hidden" name="status" value="" />',
            $html
        );
        $this->assertStringContainsString('<select name="page" class="nfse-input nfse-input--sm" id="nfse-page-select">', $html);
        $this->assertStringContainsString('<option value="2" selected>Página 2 de 3</option>', $html);
        $this->assertStringContainsString('<select name="per_page" class="nfse-input nfse-input--sm" id="nfse-per-page-select">', $html);
        $this->assertStringContainsString('<option value="100" selected>100 por página</option>', $html);
        $this->assertStringEndsWith('<button type="submit" class="nfse-btn nfse-btn--sm">Aplicar</button></form>', $html);
    }

    public function testFormRow(): void
    {
        $this->assertSame(
            '<div class="nfse-form__row"><label class="nfse-form__label" for="cnpj">CNPJ<span class="nfse-form__req" aria-hidden="true">*</span></label>'
            . '<div class="nfse-form__field"><input id="cnpj" /><div class="nfse-form__help">Só números &amp; pontos</div></div></div>',
            Ui::formRow('CNPJ', '<input id="cnpj" />', 'Só números & pontos', ['id' => 'cnpj', 'required' => true])
        );
        $this->assertSame(
            '<div class="nfse-form__row nfse-form__row--invalid"><div class="nfse-form__label">Série</div>'
            . '<div class="nfse-form__field"><select></select><div class="nfse-form__error">Informe a série</div></div></div>',
            Ui::formRow('Série', '<select></select>', '', ['error' => 'Informe a série'])
        );
    }

    public function testSaveBarAndChips(): void
    {
        $this->assertSame('<div class="nfse-savebar"><button>S</button></div>', Ui::saveBar('<button>S</button>'));
        $this->assertSame(
            '<div class="nfse-savebar nfse-savebar--sticky"><span class="nfse-savebar__note">Alterações não salvas</span><button>S</button></div>',
            Ui::saveBar('<button>S</button>', 'Alterações não salvas', true)
        );
        $this->assertSame(
            '<nav class="nfse-chips" aria-label="Meses"><a class="nfse-chip" href="?m=8">Agosto</a><a class="nfse-chip" href="?m=9&amp;x=1" aria-current="true">Setembro</a></nav>',
            Ui::chips([['label' => 'Agosto', 'href' => '?m=8'], ['label' => 'Setembro', 'href' => '?m=9&x=1', 'current' => true]], 'Meses')
        );
        $this->assertStringStartsWith('<nav class="nfse-chips nfse-chips--sm"', Ui::chips([], 'x', true));
    }

    public function testUiServiceMessagesUseCallouts(): void
    {
        ob_start();
        (new UiService())->renderError('Item <x> não encontrado.');
        $error = (string) ob_get_clean();
        $this->assertStringStartsWith('<div class="nfse-admin"><div class="nfse-ui nfse-page"><div class="nfse-callout nfse-callout--danger" role="alert">', $error);
        $this->assertStringContainsString('<div class="nfse-callout__title">Erro</div><div class="nfse-callout__text">Item &lt;x&gt; não encontrado.</div>', $error);

        ob_start();
        (new UiService())->renderSuccess('Pronto.');
        $ok = (string) ob_get_clean();
        $this->assertStringContainsString('<div class="nfse-callout nfse-callout--ok" role="status">', $ok);
        $this->assertStringContainsString('<div class="nfse-callout__title">Pronto.</div>', $ok);
    }
}
