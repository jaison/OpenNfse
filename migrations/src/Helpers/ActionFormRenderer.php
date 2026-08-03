<?php

declare(strict_types=1);

namespace OpenNfse\Helpers;

final class ActionFormRenderer
{
    public static function render(string $action, string $style, array $hiddenInputsHtml, string $buttonHtml): string
    {
        $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"' . ($style !== '' ? ' style="' . $style . '"' : '') . '>';
        foreach ($hiddenInputsHtml as $hiddenInputHtml) {
            $html .= $hiddenInputHtml;
        }
        $html .= $buttonHtml;
        $html .= '</form>';

        return $html;
    }

    public static function invoiceIdInput(int $invoiceId): string
    {
        return '<input type="hidden" name="invoiceid" value="' . $invoiceId . '" />';
    }

    public static function tokenInput(string $token): string
    {
        return $token !== '' ? '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />' : '';
    }
}
