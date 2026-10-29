<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Dto\EmissionDecision;

/**
 * Decide se uma fatura deve ter a NFS-e enfileirada em cada evento (gerada, paga ou manual).
 *
 * Classe pura: recebe os dados já lidos do banco e não acessa Capsule. Quem chama monta os
 * arrays (fatura, cliente, config e ajustes do gateway) e registra os logs.
 *
 * Nos eventos automáticos, o gatilho efetivo segue esta precedência:
 * 1. custom field do cliente, quando configurado e com valor diferente de "Não configurado";
 * 2. escopo por grupo de clientes (cliente fora dos grupos selecionados não emite);
 * 3. regra global (auto_emit_trigger, com auto_emit_on_payment como fallback).
 *
 * A emissão manual não passa pelo gatilho: segue allow_manual_unpaid, como antes.
 */
final class EmissionPolicyService
{
    public const EVENT_GERADA = 'GERADA';
    public const EVENT_PAGA = 'PAGA';
    public const EVENT_MANUAL = 'MANUAL';

    public const TRIGGER_NUNCA = 'nunca';
    public const TRIGGER_PAGA = 'paga';
    public const TRIGGER_GERADA = 'gerada';

    public const SCOPE_TODOS = 'todos';
    public const SCOPE_GRUPOS = 'grupos';

    public const SOURCE_CUSTOMFIELD = 'customfield';
    public const SOURCE_GRUPO = 'grupo';
    public const SOURCE_GLOBAL = 'global';
    public const SOURCE_MANUAL = 'manual';

    public const CODE_EMIT = 'EMIT';
    public const CODE_QUEUE_DISABLED = 'QUEUE_DISABLED';
    public const CODE_TRIGGER_NEVER = 'TRIGGER_NEVER';
    public const CODE_TRIGGER_ON_PAYMENT_ONLY = 'TRIGGER_ON_PAYMENT_ONLY';
    public const CODE_GROUP_NOT_SELECTED = 'GROUP_NOT_SELECTED';
    public const CODE_ALREADY_ISSUED = 'ALREADY_ISSUED';
    public const CODE_INVOICE_NOT_UNPAID = 'INVOICE_NOT_UNPAID';
    public const CODE_INVOICE_ZERO_TOTAL = 'INVOICE_ZERO_TOTAL';
    public const CODE_GATEWAY_NOT_ON_CREATE = 'GATEWAY_NOT_ON_CREATE';
    public const CODE_MASS_PAY = 'MASS_PAY';
    public const CODE_ADD_FUNDS = 'ADD_FUNDS';
    public const CODE_MASS_PAY_CHILD_ALREADY_ISSUED = 'MASS_PAY_CHILD_ALREADY_ISSUED';
    public const CODE_MASS_PAY_NO_REMAINING_VALUE = 'MASS_PAY_NO_REMAINING_VALUE';
    // Mesmos códigos do EmissionEligibilityService, para manter os logs QUEUE_SKIP_* de antes.
    public const CODE_NOT_PAID = EmissionEligibilityService::SKIP_NOT_PAID;
    public const CODE_CREDIT_PAYMENT = EmissionEligibilityService::SKIP_CREDIT_PAYMENT;
    public const CODE_GATEWAY_DISABLED = EmissionEligibilityService::SKIP_GATEWAY_DISABLED;
    public const CODE_ZERO_VALUE = EmissionEligibilityService::SKIP_ZERO_VALUE;

    public const WARNING_CUSTOMFIELD_UNKNOWN = 'CUSTOMFIELD_UNKNOWN_VALUE';

    public const MSG_CUSTOMFIELD_MISSING = 'O custom field configurado para a emissão automática não existe mais; '
        . 'a regra por cliente está sendo ignorada.';

    public const MSG_MASS_PAY_NO_REMAINING_VALUE = 'Não há valor a emitir nesta fatura de pagamento em massa '
        . '(Mass Pay): descontadas as NFS-e das faturas agrupadas e o crédito aplicado, o valor restante é zero.';
    public const MSG_ZERO_VALUE = 'Fatura com valor zero: não há serviço a declarar na NFS-e.';
    public const MSG_MASS_PAY_CHILD_ISSUED = 'Todas as faturas agrupadas nesta fatura de pagamento em massa '
        . '(Mass Pay) já têm NFS-e emitida ou em processamento. Emitir outra NFS-e aqui duplicaria a nota do '
        . 'mesmo serviço.';

    public const CF_NAO_CONFIGURADO = 'Não configurado';
    public const CF_NAO_EMITIR = 'Não emitir automaticamente';
    public const CF_APENAS_MANUAL = 'Emitir apenas manualmente';
    public const CF_APENAS_PAGAS = 'Emitir apenas de faturas pagas';
    public const CF_TODAS = 'Emitir de todas as faturas';

    private const ACCENT_MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'É' => 'e', 'Ê' => 'e', 'Í' => 'i',
        'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'Ú' => 'u', 'Ü' => 'u', 'Ç' => 'c',
    ];

    /** Origem gravada no item da fila criado na geração da fatura. */
    public const QUEUE_ORIGEM_GERADA = 'gerada';
    public const QUEUE_ORIGEM_PAGA = 'paga';
    public const QUEUE_ORIGEM_MANUAL = 'manual';

    /**
     * Opções canônicas do custom field e o gatilho de cada uma (null = segue grupo/global).
     *
     * @return array<string, string|null>
     */
    public static function customFieldOptions(): array
    {
        return [
            self::CF_NAO_CONFIGURADO => null,
            self::CF_NAO_EMITIR => self::TRIGGER_NUNCA,
            self::CF_APENAS_MANUAL => self::TRIGGER_NUNCA,
            self::CF_APENAS_PAGAS => self::TRIGGER_PAGA,
            self::CF_TODAS => self::TRIGGER_GERADA,
        ];
    }

    /** @return array<int, string> */
    public static function validTriggers(): array
    {
        return [self::TRIGGER_NUNCA, self::TRIGGER_PAGA, self::TRIGGER_GERADA];
    }

    /** @return array<int, string> */
    public static function validScopes(): array
    {
        return [self::SCOPE_TODOS, self::SCOPE_GRUPOS];
    }

    /**
     * @param array $invoice tblinvoices (status, total, credit, paymentmethod), item_types (tblinvoiceitems.type) e
     *                       nfse_value (NfseValueResolver: na agregadora de Mass Pay, valor sem as filhas com nota)
     * @param array $client groupid (int), customfield_value (?string, valor do custom field da política) e
     *                      customfield_missing (bool, campo configurado que não existe mais no WHMCS)
     * @param array $config mod_opennfse_config
     * @param array $gatewaySettings enabled (bool) e emit_on_create (bool) do gateway da fatura
     * @param array|null $nota linha atual de mod_opennfse_notas da fatura, se houver
     */
    public function decide(
        string $event,
        array $invoice,
        array $client,
        array $config,
        array $gatewaySettings,
        ?array $nota = null
    ): EmissionDecision {
        $event = strtoupper(trim($event));
        if ($event === self::EVENT_MANUAL) {
            return $this->decideManual($invoice, $config, $gatewaySettings);
        }
        if ($event !== self::EVENT_GERADA && $event !== self::EVENT_PAGA) {
            throw new \InvalidArgumentException('Evento de emissão inválido: ' . $event);
        }

        if ((string) ($config['queue_enabled'] ?? '0') !== '1') {
            return $this->skip(self::CODE_QUEUE_DISABLED, 'Fila/cron desabilitados.', self::SOURCE_GLOBAL, '', true);
        }

        [$trigger, $source, $warnings, $groupExcluded] = $this->resolveTrigger($client, $config);

        if ($groupExcluded) {
            return new EmissionDecision(
                false,
                self::CODE_GROUP_NOT_SELECTED,
                'O grupo do cliente não está entre os grupos selecionados para a emissão automática.',
                false,
                $source,
                $trigger,
                false,
                $warnings
            );
        }

        $silent = $source === self::SOURCE_GLOBAL;
        if ($trigger === self::TRIGGER_NUNCA) {
            return new EmissionDecision(
                false,
                self::CODE_TRIGGER_NEVER,
                $source === self::SOURCE_CUSTOMFIELD
                    ? 'O custom field do cliente desativa a emissão automática.'
                    : 'Emissão automática desativada.',
                false,
                $source,
                $trigger,
                $silent,
                $warnings
            );
        }

        // Regra "só no pagamento" na geração não é um bloqueio: a decisão vem no pagamento. Sem log.
        if ($event === self::EVENT_GERADA && $trigger !== self::TRIGGER_GERADA) {
            return new EmissionDecision(
                false,
                self::CODE_TRIGGER_ON_PAYMENT_ONLY,
                'A regra aplicada só emite quando a fatura é paga.',
                false,
                $source,
                $trigger,
                true,
                $warnings
            );
        }

        if ($this->isAlreadyIssued($nota)) {
            return new EmissionDecision(
                false,
                self::CODE_ALREADY_ISSUED,
                'A fatura já tem NFS-e emitida.',
                false,
                $source,
                $trigger,
                false,
                $warnings
            );
        }

        $invoiceCheck = $event === self::EVENT_GERADA
            ? $this->checkInvoiceOnCreate($invoice, $gatewaySettings)
            : $this->checkInvoiceOnPayment($invoice, $gatewaySettings);
        if ($invoiceCheck !== null) {
            [$code, $message] = $invoiceCheck;

            return $this->skip($code, $message, $source, $trigger, $invoiceCheck[2] ?? false, $warnings);
        }

        return new EmissionDecision(
            true,
            self::CODE_EMIT,
            $event === self::EVENT_GERADA
                ? 'Emissão na geração da fatura autorizada.'
                : 'Emissão no pagamento autorizada.',
            $event === self::EVENT_GERADA,
            $source,
            $trigger,
            false,
            $warnings
        );
    }

    /**
     * Gatilho global: auto_emit_trigger quando válido; senão, deriva de auto_emit_on_payment
     * (instalações anteriores à v0.1.25 ou coluna ainda sem valor).
     */
    public static function resolveGlobalTrigger(array $config): string
    {
        $trigger = strtolower(trim((string) ($config['auto_emit_trigger'] ?? '')));
        if (in_array($trigger, self::validTriggers(), true)) {
            return $trigger;
        }

        return (string) ($config['auto_emit_on_payment'] ?? '0') === '1' ? self::TRIGGER_PAGA : self::TRIGGER_NUNCA;
    }

    /**
     * Converte a lista de grupos gravada na config ("1,4,7") em IDs positivos e únicos.
     *
     * @return array<int, int>
     */
    public static function parseGroupIds(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $id = (int) $part;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * Casa o valor do custom field com uma opção canônica, sem diferenciar maiúsculas/minúsculas
     * e ignorando espaços extras. Retorna a opção canônica ou null se o valor não for reconhecido.
     */
    public static function matchCustomFieldOption(string $value): ?string
    {
        $normalized = self::normalizeText($value);
        if ($normalized === '') {
            return null;
        }
        foreach (array_keys(self::customFieldOptions()) as $option) {
            if (self::normalizeText($option) === $normalized) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Diz se o processamento da fila pode emitir a fatura Unpaid deste item.
     *
     * - Item da geração (origem "gerada"): a política já autorizou no enfileiramento, então basta
     *   o item ter allow_unpaid = 1 e a fatura seguir Unpaid. Não depende de allow_manual_unpaid.
     * - Demais itens: regra anterior, com allow_manual_unpaid ligado, fatura Unpaid e
     *   allow_unpaid igual a 1 ou nulo (itens antigos).
     */
    public static function queueAllowsUnpaid(array $job, array $invoice, array $config): bool
    {
        $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
        if ($invoiceStatus !== 'unpaid') {
            return false;
        }

        $jobAllowUnpaid = $job['allow_unpaid'] ?? null;
        $origem = strtolower(trim((string) ($job['origem'] ?? '')));
        if ($origem === self::QUEUE_ORIGEM_GERADA) {
            return $jobAllowUnpaid !== null && (int) $jobAllowUnpaid === 1;
        }

        return (string) ($config['allow_manual_unpaid'] ?? '0') === '1'
            && ($jobAllowUnpaid === null || (int) $jobAllowUnpaid === 1);
    }

    /**
     * Item da fila criado na geração da fatura que ainda está Unpaid: a política precisa ser
     * reavaliada no processamento, porque config, gateway, grupo ou custom field podem ter mudado
     * desde o enfileiramento. Fatura já paga segue o caminho normal, sem reavaliar.
     */
    public static function queueItemNeedsGeradaRecheck(array $job, array $invoice): bool
    {
        return strtolower(trim((string) ($job['origem'] ?? ''))) === self::QUEUE_ORIGEM_GERADA
            && strtolower(trim((string) ($invoice['status'] ?? ''))) === 'unpaid';
    }

    /**
     * Reavalia, no processamento da fila, se o item de origem "gerada" continua autorizado.
     * Retorna null quando não há o que reavaliar (outra origem ou fatura que não está Unpaid);
     * senão, a decisão do evento GERADA com os dados atuais.
     */
    public function recheckQueuedGerada(
        array $job,
        array $invoice,
        array $client,
        array $config,
        array $gatewaySettings,
        ?array $nota = null
    ): ?EmissionDecision {
        if (!self::queueItemNeedsGeradaRecheck($job, $invoice)) {
            return null;
        }

        return $this->decide(self::EVENT_GERADA, $invoice, $client, $config, $gatewaySettings, $nota);
    }

    /**
     * @return array{0: string, 1: string, 2: array<int, array{code: string, message: string}>, 3: bool}
     *         gatilho, origem da regra, avisos e se o cliente ficou fora dos grupos selecionados
     */
    private function resolveTrigger(array $client, array $config): array
    {
        $warnings = [];

        // Campo apagado no WHMCS (customfield_missing): a regra por cliente é ignorada, sem log;
        // o aviso único fica na tela de configuração e no dashboard.
        // Valor vazio ou cliente sem valor gravado: "Não configurado", sem log (caso normal).
        $customFieldId = (int) ($config['auto_emit_customfield_id'] ?? 0);
        if ($customFieldId > 0 && empty($client['customfield_missing'])) {
            $value = trim((string) ($client['customfield_value'] ?? ''));
            if ($value !== '') {
                $option = self::matchCustomFieldOption($value);
                if ($option === null) {
                    $warnings[] = [
                        'code' => self::WARNING_CUSTOMFIELD_UNKNOWN,
                        'message' => 'Valor desconhecido no custom field de emissão automática ("'
                            . self::truncate($value, 80) . '"); tratado como "' . self::CF_NAO_CONFIGURADO . '".',
                    ];
                } else {
                    $trigger = self::customFieldOptions()[$option];
                    if ($trigger !== null) {
                        return [$trigger, self::SOURCE_CUSTOMFIELD, $warnings, false];
                    }
                }
            }
        }

        $globalTrigger = self::resolveGlobalTrigger($config);
        $scope = strtolower(trim((string) ($config['auto_emit_scope'] ?? self::SCOPE_TODOS)));
        if ($scope === self::SCOPE_GRUPOS && $globalTrigger !== self::TRIGGER_NUNCA) {
            $groupIds = self::parseGroupIds($config['auto_emit_group_ids'] ?? '');
            $clientGroup = (int) ($client['groupid'] ?? 0);
            if ($clientGroup <= 0 || !in_array($clientGroup, $groupIds, true)) {
                return [self::TRIGGER_NUNCA, self::SOURCE_GRUPO, $warnings, true];
            }

            return [$globalTrigger, self::SOURCE_GRUPO, $warnings, false];
        }

        return [$globalTrigger, self::SOURCE_GLOBAL, $warnings, false];
    }

    private function decideManual(array $invoice, array $config, array $gatewaySettings): EmissionDecision
    {
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));
        $allowUnpaid = $status === 'unpaid'
            && (string) ($config['allow_manual_unpaid'] ?? '0') === '1'
            && (string) ($config['queue_enabled'] ?? '0') === '1';

        if ($status !== 'paid' && !$allowUnpaid) {
            return $this->skip(self::CODE_NOT_PAID, 'A fatura não está paga.', self::SOURCE_MANUAL);
        }
        $financials = new InvoiceFinancialsService();
        if ($financials->isCreditOnlyPayment($invoice)) {
            return $this->skip(self::CODE_CREDIT_PAYMENT, 'Fatura paga só com crédito.', self::SOURCE_MANUAL);
        }
        if ($financials->isZeroValue($invoice)) {
            return $this->skip(self::CODE_ZERO_VALUE, self::MSG_ZERO_VALUE, self::SOURCE_MANUAL);
        }
        if (!$this->gatewayEnabled($gatewaySettings)) {
            return $this->skip(self::CODE_GATEWAY_DISABLED, 'Gateway desabilitado para emissão.', self::SOURCE_MANUAL);
        }
        $massPayBlock = $this->massPayBlockFor($invoice);
        if ($massPayBlock !== null) {
            return $this->skip($massPayBlock[0], $massPayBlock[1], self::SOURCE_MANUAL);
        }

        return new EmissionDecision(
            true,
            self::CODE_EMIT,
            'Emissão manual autorizada.',
            $allowUnpaid,
            self::SOURCE_MANUAL
        );
    }

    /** @return array{0: string, 1: string}|null */
    private function checkInvoiceOnCreate(array $invoice, array $gatewaySettings): ?array
    {
        $financials = new InvoiceFinancialsService();
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));
        if ($status !== 'unpaid') {
            // Já Paid (ex.: crédito automático na geração, com o InvoicePaid chegando antes):
            // o evento de pagamento cuida da fatura, então não há o que logar aqui.
            return [
                self::CODE_INVOICE_NOT_UNPAID,
                'A emissão na geração só vale para fatura Unpaid publicada (status atual: '
                    . ($status !== '' ? $status : '-') . ').',
                $status === 'paid',
            ];
        }
        if ($financials->getInvoiceTotal($invoice) <= 0.00001) {
            return [self::CODE_INVOICE_ZERO_TOTAL, 'Fatura com total zero.'];
        }
        if ($financials->isCreditOnlyPayment($invoice)) {
            return [self::CODE_CREDIT_PAYMENT, 'Fatura com pagamento só por crédito.'];
        }
        // Mass Pay agrupa outras faturas (a nota sairia em duplicidade) e Add Funds é crédito ainda
        // não recebido: as duas seguem só pela regra do pagamento.
        $itemTypes = self::normalizedItemTypes($invoice['item_types'] ?? []);
        if (in_array('invoice', $itemTypes, true)) {
            return [
                self::CODE_MASS_PAY,
                'Fatura de pagamento em massa (Mass Pay): emissão só pela regra do pagamento.',
            ];
        }
        if (in_array('addfunds', $itemTypes, true)) {
            return [
                self::CODE_ADD_FUNDS,
                'Fatura de adição de crédito (Add Funds): emissão só pela regra do pagamento.',
            ];
        }
        if (!$this->gatewayEnabled($gatewaySettings)) {
            return [self::CODE_GATEWAY_DISABLED, 'Gateway desabilitado para emissão.'];
        }
        if (empty($gatewaySettings['emit_on_create'])) {
            return [
                self::CODE_GATEWAY_NOT_ON_CREATE,
                'O gateway da fatura não está marcado para emitir ao gerar a fatura.',
            ];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private function checkInvoiceOnPayment(array $invoice, array $gatewaySettings): ?array
    {
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));
        if ($status !== 'paid') {
            return [self::CODE_NOT_PAID, 'A fatura não está paga.'];
        }
        $financials = new InvoiceFinancialsService();
        if ($financials->isCreditOnlyPayment($invoice)) {
            return [self::CODE_CREDIT_PAYMENT, 'Fatura paga só com crédito.'];
        }
        if ($financials->isZeroValue($invoice)) {
            return [self::CODE_ZERO_VALUE, self::MSG_ZERO_VALUE];
        }
        if (!$this->gatewayEnabled($gatewaySettings)) {
            return [self::CODE_GATEWAY_DISABLED, 'Gateway desabilitado para emissão.'];
        }
        $massPayBlock = $this->massPayBlockFor($invoice);
        if ($massPayBlock !== null) {
            return $massPayBlock;
        }

        return null;
    }

    /**
     * Bloqueio da agregadora de Mass Pay a partir do resultado do NfseValueResolver: valor <= 0 depois
     * de descontar as filhas com nota. Com todas as filhas cobertas, MASS_PAY_CHILD_ALREADY_ISSUED;
     * se o crédito aplicado zerou o restante, MASS_PAY_NO_REMAINING_VALUE. Com parte das filhas
     * coberta e valor restante, a nota de M sai só com o restante (null, não bloqueia).
     * Usado pela política, pela fila e pela emissão direta.
     *
     * @return array{0: string, 1: string}|null código e mensagem
     */
    public static function massPayBlock(?array $resolved): ?array
    {
        if ($resolved === null || ($resolved['excluded_children'] ?? []) === []) {
            return null;
        }
        if ((float) ($resolved['value'] ?? 0) > 0.00001) {
            return null;
        }

        return !empty($resolved['all_children_covered'])
            ? [self::CODE_MASS_PAY_CHILD_ALREADY_ISSUED, self::MSG_MASS_PAY_CHILD_ISSUED]
            : [self::CODE_MASS_PAY_NO_REMAINING_VALUE, self::MSG_MASS_PAY_NO_REMAINING_VALUE];
    }

    /** @return array{0: string, 1: string}|null */
    private function massPayBlockFor(array $invoice): ?array
    {
        if (!in_array('invoice', self::normalizedItemTypes($invoice['item_types'] ?? []), true)) {
            return null;
        }
        $resolved = $invoice['nfse_value'] ?? null;

        return self::massPayBlock(is_array($resolved) ? $resolved : null);
    }

    /**
     * @param array<int, array{code: string, message: string}> $warnings
     */
    private function skip(
        string $code,
        string $message,
        string $source,
        string $trigger = '',
        bool $silent = false,
        array $warnings = []
    ): EmissionDecision {
        return new EmissionDecision(false, $code, $message, false, $source, $trigger, $silent, $warnings);
    }

    private function gatewayEnabled(array $gatewaySettings): bool
    {
        return !array_key_exists('enabled', $gatewaySettings) || (bool) $gatewaySettings['enabled'];
    }

    /** Mesmo critério da fila para dar o item como concluído: EMITIDA com chave de acesso. */
    private function isAlreadyIssued(?array $nota): bool
    {
        if ($nota === null) {
            return false;
        }

        return trim((string) ($nota['status'] ?? '')) === 'EMITIDA'
            && trim((string) ($nota['chave_acesso'] ?? '')) !== '';
    }

    /**
     * Texto para comparação: sem acentos, minúsculo e com um espaço entre as palavras.
     * Não depende de mbstring nem de intl: as marcas combinantes (acento decomposto, NFD) saem por
     * PCRE /u, os acentos compostos do português saem por mapa e as maiúsculas viram minúsculas só
     * na faixa ASCII, sem influência do locale. O espaço não separável (U+00A0) conta como espaço.
     * Com UTF-8 inválido, o preg_* devolve null e a etapa é pulada, sem quebrar.
     */
    private static function normalizeText(string $value): string
    {
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $value = strtr($value, self::ACCENT_MAP);
        $value = strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<int, string>
     */
    private static function normalizedItemTypes(mixed $types): array
    {
        if (!is_array($types)) {
            return [];
        }
        $out = [];
        foreach ($types as $type) {
            $type = strtolower(trim((string) $type));
            if ($type !== '') {
                $out[] = $type;
            }
        }

        return $out;
    }

    private static function truncate(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') . '…' : $value;
        }

        return strlen($value) > $max ? substr($value, 0, $max) . '...' : $value;
    }
}
