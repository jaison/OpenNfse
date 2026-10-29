<?php

declare(strict_types=1);

namespace OpenNfse\Tests\Services;

use OpenNfse\Hooks\InvoiceCreatedHook;
use OpenNfse\Hooks\InvoicePaidHook;
use OpenNfse\Repositories\PaymentGatewaySettingsRepository;
use OpenNfse\Services\EmissionPolicyService as P;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class EmissionPolicyServiceTest extends TestCase
{
    private P $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new P();
        Capsule::reset();
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        parent::tearDown();
    }

    private function config(array $overrides = []): array
    {
        return $overrides + [
            'queue_enabled' => '1',
            'auto_emit_on_payment' => '0',
            'auto_emit_trigger' => P::TRIGGER_PAGA,
            'auto_emit_scope' => P::SCOPE_TODOS,
            'auto_emit_group_ids' => '',
            'auto_emit_customfield_id' => 0,
            'allow_manual_unpaid' => '0',
        ];
    }

    private function unpaid(array $overrides = []): array
    {
        return $overrides + ['status' => 'Unpaid', 'total' => '100.00', 'credit' => '0.00', 'paymentmethod' => 'boleto'];
    }

    private function paid(array $overrides = []): array
    {
        return $overrides + ['status' => 'Paid', 'total' => '100.00', 'credit' => '0.00', 'paymentmethod' => 'boleto'];
    }

    private function client(int $groupId = 0, ?string $cf = null): array
    {
        return ['groupid' => $groupId, 'customfield_value' => $cf];
    }

    private function gw(bool $enabled = true, bool $onCreate = true): array
    {
        return ['enabled' => $enabled, 'emit_on_create' => $onCreate];
    }

    // ---- Gatilho global ----

    public function testQueueDisabledNeverEmitsAndIsSilent(): void
    {
        foreach ([P::EVENT_GERADA, P::EVENT_PAGA] as $event) {
            $d = $this->policy->decide($event, $this->paid(), $this->client(), $this->config(['queue_enabled' => '0', 'auto_emit_trigger' => P::TRIGGER_GERADA]), $this->gw());
            $this->assertFalse($d->emit);
            $this->assertSame(P::CODE_QUEUE_DISABLED, $d->code);
            $this->assertTrue($d->silent);
        }
    }

    /**
     * @return array<string, array{string, string, bool, string}>
     */
    public static function globalTriggerMatrix(): array
    {
        return [
            'nunca x gerada' => [P::TRIGGER_NUNCA, P::EVENT_GERADA, false, P::CODE_TRIGGER_NEVER],
            'nunca x paga' => [P::TRIGGER_NUNCA, P::EVENT_PAGA, false, P::CODE_TRIGGER_NEVER],
            'paga x gerada' => [P::TRIGGER_PAGA, P::EVENT_GERADA, false, P::CODE_TRIGGER_ON_PAYMENT_ONLY],
            'paga x paga' => [P::TRIGGER_PAGA, P::EVENT_PAGA, true, P::CODE_EMIT],
            'gerada x gerada' => [P::TRIGGER_GERADA, P::EVENT_GERADA, true, P::CODE_EMIT],
            'gerada x paga (sem NFS-e ainda)' => [P::TRIGGER_GERADA, P::EVENT_PAGA, true, P::CODE_EMIT],
        ];
    }

    /**
     * @dataProvider globalTriggerMatrix
     */
    public function testGlobalTriggerMatrix(string $trigger, string $event, bool $emit, string $code): void
    {
        $invoice = $event === P::EVENT_GERADA ? $this->unpaid() : $this->paid();
        $d = $this->policy->decide($event, $invoice, $this->client(), $this->config(['auto_emit_trigger' => $trigger]), $this->gw());

        $this->assertSame($emit, $d->emit);
        $this->assertSame($code, $d->code);
        $this->assertSame(P::SOURCE_GLOBAL, $d->source);
        if (!$emit) {
            $this->assertTrue($d->silent, 'Gatilho global desligado não deve gerar log.');
        }
    }

    public function testAllowUnpaidOnlyOnCreateEvent(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA]);
        $this->assertTrue($this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $config, $this->gw())->allowUnpaid);
        $this->assertFalse($this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $config, $this->gw())->allowUnpaid);
    }

    // ---- Upgrade e fallback ----

    public function testUpgradeFallbackFromAutoEmitOnPayment(): void
    {
        $legacyOn = ['queue_enabled' => '1', 'auto_emit_on_payment' => '1'];
        $legacyOff = ['queue_enabled' => '1', 'auto_emit_on_payment' => '0'];

        $this->assertSame(P::TRIGGER_PAGA, P::resolveGlobalTrigger($legacyOn));
        $this->assertSame(P::TRIGGER_NUNCA, P::resolveGlobalTrigger($legacyOff));
        $this->assertSame(P::TRIGGER_PAGA, P::resolveGlobalTrigger($legacyOn + ['auto_emit_trigger' => null]));
        $this->assertSame(P::TRIGGER_PAGA, P::resolveGlobalTrigger($legacyOn + ['auto_emit_trigger' => 'lixo']));
        $this->assertSame(P::TRIGGER_GERADA, P::resolveGlobalTrigger($legacyOff + ['auto_emit_trigger' => 'GERADA']));

        // Instalação antiga com auto_emit_on_payment=1 segue emitindo ao pagar, e não na geração.
        $paga = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $legacyOn, $this->gw());
        $this->assertTrue($paga->emit);
        $this->assertFalse($paga->allowUnpaid);
        $gerada = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $legacyOn, $this->gw());
        $this->assertFalse($gerada->emit);
        $this->assertTrue($gerada->silent);

        $off = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $legacyOff, $this->gw());
        $this->assertFalse($off->emit);
        $this->assertTrue($off->silent);
    }

    public function testMigrationSqlDerivesTriggerIdempotently(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/040_alter_config_auto_emit_policy.sql');
        $this->assertStringContainsString("WHEN auto_emit_on_payment = 1 THEN 'paga' ELSE 'nunca'", $sql);
        $this->assertStringContainsString('WHERE auto_emit_trigger IS NULL', $sql);
    }

    // ---- Pagamento: elegibilidade de antes ----

    public function testPaymentKeepsEligibilityCodes(): void
    {
        $config = $this->config();
        $this->assertSame(P::CODE_NOT_PAID, $this->policy->decide(P::EVENT_PAGA, $this->unpaid(), $this->client(), $config, $this->gw())->code);
        $this->assertSame(P::CODE_CREDIT_PAYMENT, $this->policy->decide(P::EVENT_PAGA, $this->paid(['paymentmethod' => 'credit']), $this->client(), $config, $this->gw())->code);
        $this->assertSame(P::CODE_CREDIT_PAYMENT, $this->policy->decide(P::EVENT_PAGA, $this->paid(['total' => '0.00', 'credit' => '50.00']), $this->client(), $config, $this->gw())->code);
        $this->assertSame(P::CODE_GATEWAY_DISABLED, $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $config, $this->gw(false))->code);
        // No pagamento, "emitir ao gerar" do gateway não importa.
        $this->assertTrue($this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $config, $this->gw(true, false))->emit);
    }

    public function testPaymentAfterIssuedOnCreateDoesNothing(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA]);
        $issued = ['status' => 'EMITIDA', 'chave_acesso' => '42000000000000000000000000000000000000000000000000'];

        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $config, $this->gw(), $issued);
        $this->assertFalse($d->emit);
        $this->assertSame(P::CODE_ALREADY_ISSUED, $d->code);

        // Crédito depois da emissão na geração: não reemite (o alerta é de outra rotina).
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(['paymentmethod' => 'credit']), $this->client(), $config, $this->gw(), $issued);
        $this->assertFalse($d->emit);
        $this->assertSame(P::CODE_ALREADY_ISSUED, $d->code);
    }

    public function testPaymentWithNotaInProgressStillEnqueues(): void
    {
        // Sem chave ainda: segue para a fila, que só consulta o status (comportamento de antes).
        $processing = ['status' => 'PROCESSANDO', 'chave_acesso' => '', 'id_dps' => 'DPS123'];
        $this->assertTrue($this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $this->config(), $this->gw(), $processing)->emit);
        $emitidaSemChave = ['status' => 'EMITIDA', 'chave_acesso' => ''];
        $this->assertTrue($this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(), $this->config(), $this->gw(), $emitidaSemChave)->emit);
    }

    // ---- Geração da fatura ----

    public function testCreateRequiresPublishedUnpaidWithTotal(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA]);

        foreach (['Draft', 'Paid', 'Cancelled', ''] as $status) {
            $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(['status' => $status]), $this->client(), $config, $this->gw());
            $this->assertFalse($d->emit, 'Status ' . $status);
            $this->assertSame(P::CODE_INVOICE_NOT_UNPAID, $d->code);
            $this->assertFalse($d->silent);
        }

        foreach (['0.00', '0', '-5.00'] as $total) {
            $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(['total' => $total]), $this->client(), $config, $this->gw());
            $this->assertFalse($d->emit);
            $this->assertSame(P::CODE_INVOICE_ZERO_TOTAL, $d->code);
        }
    }

    public function testCreateRequiresGatewayMarkedToEmitOnCreate(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA]);

        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $config, $this->gw(true, false));
        $this->assertFalse($d->emit);
        $this->assertSame(P::CODE_GATEWAY_NOT_ON_CREATE, $d->code);

        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $config, $this->gw(false, true));
        $this->assertSame(P::CODE_GATEWAY_DISABLED, $d->code);

        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(['paymentmethod' => 'credit']), $this->client(), $config, $this->gw());
        $this->assertSame(P::CODE_CREDIT_PAYMENT, $d->code);

        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $config, ['enabled' => true]);
        $this->assertSame(P::CODE_GATEWAY_NOT_ON_CREATE, $d->code, 'Sem ajuste salvo, o gateway não emite na geração.');
    }

    public function testCreateSkipsWhenAlreadyIssued(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA]);
        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(), $config, $this->gw(), ['status' => 'EMITIDA', 'chave_acesso' => 'X']);
        $this->assertSame(P::CODE_ALREADY_ISSUED, $d->code);
    }

    // ---- Escopo por grupo ----

    public function testGroupScope(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_GERADA, 'auto_emit_scope' => P::SCOPE_GRUPOS, 'auto_emit_group_ids' => '2,5']);

        $in = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(5), $config, $this->gw());
        $this->assertTrue($in->emit);
        $this->assertSame(P::SOURCE_GRUPO, $in->source);

        foreach ([0, 3] as $group) {
            $out = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client($group), $config, $this->gw());
            $this->assertFalse($out->emit);
            $this->assertSame(P::CODE_GROUP_NOT_SELECTED, $out->code);
            $this->assertFalse($out->silent);
        }

        $paga = $this->config(['auto_emit_trigger' => P::TRIGGER_PAGA, 'auto_emit_scope' => P::SCOPE_GRUPOS, 'auto_emit_group_ids' => '2']);
        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(2), $paga, $this->gw());
        $this->assertSame(P::CODE_TRIGGER_ON_PAYMENT_ONLY, $d->code);
        $this->assertTrue($this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(2), $paga, $this->gw())->emit);
    }

    public function testGroupScopeWithGlobalNeverStaysSilent(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_NUNCA, 'auto_emit_scope' => P::SCOPE_GRUPOS, 'auto_emit_group_ids' => '2']);
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(9), $config, $this->gw());
        $this->assertSame(P::CODE_TRIGGER_NEVER, $d->code);
        $this->assertTrue($d->silent);
    }

    public function testParseGroupIds(): void
    {
        $this->assertSame([1, 4, 7], P::parseGroupIds('1, 4,7,,4'));
        $this->assertSame([3], P::parseGroupIds(['3', 'x', '0', '-2']));
        $this->assertSame([], P::parseGroupIds(null));
    }

    // ---- Custom field por cliente ----

    /**
     * @return array<string, array{string, string, string, bool, string, string}>
     */
    public static function customFieldMatrix(): array
    {
        // valor, gatilho global, evento, emite?, código, origem
        return [
            'não emitir bloqueia pagamento' => [P::CF_NAO_EMITIR, P::TRIGGER_GERADA, P::EVENT_PAGA, false, P::CODE_TRIGGER_NEVER, P::SOURCE_CUSTOMFIELD],
            'não emitir bloqueia geração' => [P::CF_NAO_EMITIR, P::TRIGGER_GERADA, P::EVENT_GERADA, false, P::CODE_TRIGGER_NEVER, P::SOURCE_CUSTOMFIELD],
            'apenas manual bloqueia pagamento' => [P::CF_APENAS_MANUAL, P::TRIGGER_PAGA, P::EVENT_PAGA, false, P::CODE_TRIGGER_NEVER, P::SOURCE_CUSTOMFIELD],
            'apenas pagas com global nunca' => [P::CF_APENAS_PAGAS, P::TRIGGER_NUNCA, P::EVENT_PAGA, true, P::CODE_EMIT, P::SOURCE_CUSTOMFIELD],
            'apenas pagas não emite na geração' => [P::CF_APENAS_PAGAS, P::TRIGGER_GERADA, P::EVENT_GERADA, false, P::CODE_TRIGGER_ON_PAYMENT_ONLY, P::SOURCE_CUSTOMFIELD],
            'todas com global nunca, geração' => [P::CF_TODAS, P::TRIGGER_NUNCA, P::EVENT_GERADA, true, P::CODE_EMIT, P::SOURCE_CUSTOMFIELD],
            'todas com global paga, pagamento' => [P::CF_TODAS, P::TRIGGER_PAGA, P::EVENT_PAGA, true, P::CODE_EMIT, P::SOURCE_CUSTOMFIELD],
            'não configurado segue global' => [P::CF_NAO_CONFIGURADO, P::TRIGGER_PAGA, P::EVENT_PAGA, true, P::CODE_EMIT, P::SOURCE_GLOBAL],
            'não configurado segue global nunca' => [P::CF_NAO_CONFIGURADO, P::TRIGGER_NUNCA, P::EVENT_PAGA, false, P::CODE_TRIGGER_NEVER, P::SOURCE_GLOBAL],
        ];
    }

    /**
     * @dataProvider customFieldMatrix
     */
    public function testCustomFieldMatrix(string $value, string $global, string $event, bool $emit, string $code, string $source): void
    {
        $config = $this->config(['auto_emit_trigger' => $global, 'auto_emit_customfield_id' => 12]);
        $invoice = $event === P::EVENT_GERADA ? $this->unpaid() : $this->paid();

        $d = $this->policy->decide($event, $invoice, $this->client(0, $value), $config, $this->gw());

        $this->assertSame($emit, $d->emit);
        $this->assertSame($code, $d->code);
        $this->assertSame($source, $d->source);
        $this->assertSame([], $d->warnings);
        if ($source === P::SOURCE_CUSTOMFIELD && !$emit) {
            $this->assertFalse($d->silent, 'Bloqueio pelo custom field deve ir para o log.');
        }
    }

    public function testCustomFieldWinsOverGroupScope(): void
    {
        $config = $this->config([
            'auto_emit_trigger' => P::TRIGGER_PAGA,
            'auto_emit_scope' => P::SCOPE_GRUPOS,
            'auto_emit_group_ids' => '2',
            'auto_emit_customfield_id' => 12,
        ]);

        // Fora do grupo, mas o custom field manda emitir de todas.
        $d = $this->policy->decide(P::EVENT_GERADA, $this->unpaid(), $this->client(9, P::CF_TODAS), $config, $this->gw());
        $this->assertTrue($d->emit);
        $this->assertSame(P::SOURCE_CUSTOMFIELD, $d->source);

        // No grupo, mas o custom field desliga.
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(2, P::CF_NAO_EMITIR), $config, $this->gw());
        $this->assertFalse($d->emit);
        $this->assertSame(P::SOURCE_CUSTOMFIELD, $d->source);

        // "Não configurado" cai no grupo.
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(9, P::CF_NAO_CONFIGURADO), $config, $this->gw());
        $this->assertSame(P::CODE_GROUP_NOT_SELECTED, $d->code);
    }

    public function testCustomFieldMatchIsCaseInsensitive(): void
    {
        $this->assertSame(P::CF_TODAS, P::matchCustomFieldOption('  emitir DE todas   as faturas '));
        $this->assertSame(P::CF_NAO_EMITIR, P::matchCustomFieldOption('NÃO EMITIR AUTOMATICAMENTE'));
        $this->assertNull(P::matchCustomFieldOption('Emitir sempre'));
        $this->assertNull(P::matchCustomFieldOption(''));

        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_NUNCA, 'auto_emit_customfield_id' => 12]);
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(0, 'EMITIR APENAS DE FATURAS PAGAS'), $config, $this->gw());
        $this->assertTrue($d->emit);
    }

    public function testUnknownOrEmptyCustomFieldFallsBackWithWarning(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_PAGA, 'auto_emit_customfield_id' => 12]);

        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(0, 'Talvez'), $config, $this->gw());
        $this->assertTrue($d->emit);
        $this->assertSame(P::SOURCE_GLOBAL, $d->source);
        $this->assertCount(1, $d->warnings);
        $this->assertSame(P::WARNING_CUSTOMFIELD_UNKNOWN, $d->warnings[0]['code']);

        foreach ([null, '', '   '] as $empty) {
            $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(0, $empty), $config, $this->gw());
            $this->assertTrue($d->emit);
            $this->assertSame(P::WARNING_CUSTOMFIELD_EMPTY, $d->warnings[0]['code']);
        }

        // Aviso também quando a decisão final é não emitir.
        $never = $this->config(['auto_emit_trigger' => P::TRIGGER_NUNCA, 'auto_emit_customfield_id' => 12]);
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(0, 'Talvez'), $never, $this->gw());
        $this->assertFalse($d->emit);
        $this->assertCount(1, $d->warnings);
    }

    public function testCustomFieldIgnoredWhenNotConfigured(): void
    {
        $config = $this->config(['auto_emit_trigger' => P::TRIGGER_PAGA, 'auto_emit_customfield_id' => 0]);
        $d = $this->policy->decide(P::EVENT_PAGA, $this->paid(), $this->client(0, P::CF_NAO_EMITIR), $config, $this->gw());
        $this->assertTrue($d->emit);
        $this->assertSame([], $d->warnings);
    }

    // ---- Emissão manual (comportamento de antes) ----

    public function testManualFollowsAllowManualUnpaid(): void
    {
        $base = $this->config(['auto_emit_trigger' => P::TRIGGER_NUNCA, 'auto_emit_customfield_id' => 12]);

        $d = $this->policy->decide(P::EVENT_MANUAL, $this->paid(), $this->client(0, P::CF_NAO_EMITIR), $base, $this->gw());
        $this->assertTrue($d->emit, 'Custom field não bloqueia a emissão manual.');
        $this->assertFalse($d->allowUnpaid);

        $this->assertSame(P::CODE_NOT_PAID, $this->policy->decide(P::EVENT_MANUAL, $this->unpaid(), [], $base, $this->gw())->code);

        $allow = $this->config(['allow_manual_unpaid' => '1']);
        $d = $this->policy->decide(P::EVENT_MANUAL, $this->unpaid(), [], $allow, $this->gw());
        $this->assertTrue($d->emit);
        $this->assertTrue($d->allowUnpaid);

        $noQueue = $this->config(['allow_manual_unpaid' => '1', 'queue_enabled' => '0']);
        $this->assertSame(P::CODE_NOT_PAID, $this->policy->decide(P::EVENT_MANUAL, $this->unpaid(), [], $noQueue, $this->gw())->code);
        $this->assertTrue($this->policy->decide(P::EVENT_MANUAL, $this->paid(), [], $noQueue, $this->gw())->emit);

        $this->assertSame(P::CODE_NOT_PAID, $this->policy->decide(P::EVENT_MANUAL, $this->unpaid(['status' => 'Cancelled']), [], $allow, $this->gw())->code);
        $this->assertSame(P::CODE_CREDIT_PAYMENT, $this->policy->decide(P::EVENT_MANUAL, $this->paid(['paymentmethod' => 'credit']), [], $allow, $this->gw())->code);
        $this->assertSame(P::CODE_GATEWAY_DISABLED, $this->policy->decide(P::EVENT_MANUAL, $this->paid(), [], $allow, $this->gw(false))->code);
    }

    public function testInvalidEventThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->decide('OUTRO', $this->paid(), [], $this->config(), $this->gw());
    }

    // ---- Processamento da fila ----

    public function testQueueAllowsUnpaidForCreatedOriginWithoutManualSetting(): void
    {
        $config = ['allow_manual_unpaid' => '0'];
        $job = ['origem' => P::QUEUE_ORIGEM_GERADA, 'allow_unpaid' => 1];

        $this->assertTrue(P::queueAllowsUnpaid($job, ['status' => 'Unpaid'], $config));
        $this->assertFalse(P::queueAllowsUnpaid($job, ['status' => 'Cancelled'], $config));
        $this->assertFalse(P::queueAllowsUnpaid($job, ['status' => 'Paid'], $config), 'Paga segue o caminho normal.');
        $this->assertFalse(P::queueAllowsUnpaid(['origem' => P::QUEUE_ORIGEM_GERADA, 'allow_unpaid' => 0], ['status' => 'Unpaid'], $config));
        $this->assertFalse(P::queueAllowsUnpaid(['origem' => P::QUEUE_ORIGEM_GERADA, 'allow_unpaid' => null], ['status' => 'Unpaid'], $config));
    }

    public function testQueueAllowsUnpaidKeepsPreviousRuleForOtherOrigins(): void
    {
        $on = ['allow_manual_unpaid' => '1'];
        $off = ['allow_manual_unpaid' => '0'];
        $unpaid = ['status' => 'Unpaid'];

        foreach ([null, P::QUEUE_ORIGEM_MANUAL, P::QUEUE_ORIGEM_PAGA] as $origem) {
            $this->assertTrue(P::queueAllowsUnpaid(['origem' => $origem, 'allow_unpaid' => 1], $unpaid, $on));
            $this->assertTrue(P::queueAllowsUnpaid(['origem' => $origem, 'allow_unpaid' => null], $unpaid, $on), 'Item antigo (nulo).');
            $this->assertFalse(P::queueAllowsUnpaid(['origem' => $origem, 'allow_unpaid' => 0], $unpaid, $on));
            $this->assertFalse(P::queueAllowsUnpaid(['origem' => $origem, 'allow_unpaid' => 1], $unpaid, $off));
        }
        $this->assertTrue(P::queueAllowsUnpaid(['allow_unpaid' => 1], $unpaid, $on), 'Item sem a coluna origem.');
    }

    // ---- Atalhos dos hooks ----

    public function testHookShortcuts(): void
    {
        $this->assertFalse(InvoicePaidHook::automationMayApply(['queue_enabled' => '0', 'auto_emit_on_payment' => '1']));
        $this->assertTrue(InvoicePaidHook::automationMayApply(['queue_enabled' => '1', 'auto_emit_on_payment' => '1']));
        $this->assertFalse(InvoicePaidHook::automationMayApply(['queue_enabled' => '1', 'auto_emit_trigger' => 'nunca']));
        $this->assertTrue(InvoicePaidHook::automationMayApply(['queue_enabled' => '1', 'auto_emit_trigger' => 'nunca', 'auto_emit_customfield_id' => 3]));

        $this->assertFalse(InvoiceCreatedHook::onCreateMayApply(['queue_enabled' => '1', 'auto_emit_on_payment' => '1']));
        $this->assertFalse(InvoiceCreatedHook::onCreateMayApply(['queue_enabled' => '0', 'auto_emit_trigger' => 'gerada']));
        $this->assertTrue(InvoiceCreatedHook::onCreateMayApply(['queue_enabled' => '1', 'auto_emit_trigger' => 'gerada']));
        $this->assertTrue(InvoiceCreatedHook::onCreateMayApply(['queue_enabled' => '1', 'auto_emit_trigger' => 'paga', 'auto_emit_customfield_id' => 3]));
    }

    // ---- Ajustes do gateway ----

    public function testGatewayPolicySettings(): void
    {
        Capsule::$rows['mod_opennfse_payment_gateway_settings'] = [
            (object) ['gateway' => 'Boleto', 'enabled' => 1, 'emit_on_create' => 1],
            (object) ['gateway' => 'paypal', 'enabled' => 1, 'emit_on_create' => 0],
            (object) ['gateway' => 'legacy', 'enabled' => 0],
        ];
        $repo = new PaymentGatewaySettingsRepository();

        $this->assertSame(['enabled' => true, 'emit_on_create' => true], $repo->getPolicySettings('boleto'));
        $this->assertSame(['enabled' => true, 'emit_on_create' => false], $repo->getPolicySettings('paypal'));
        $this->assertSame(['enabled' => false, 'emit_on_create' => false], $repo->getPolicySettings('legacy'));
        $this->assertSame(['enabled' => false, 'emit_on_create' => false], $repo->getPolicySettings('ausente'));
        $this->assertSame(['enabled' => true, 'emit_on_create' => false], $repo->getPolicySettings(''));
    }
}
