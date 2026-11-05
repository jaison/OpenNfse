<?php

declare(strict_types=1);

namespace OpenNfse\Services;

use OpenNfse\Views\Ui;

final class UiService
{
    public function renderHeader(string $title): void
    {
        echo '<div class="nfse-admin">';
    }

    public function renderFooter(): void
    {
        echo '</div>';
    }

    public function renderError(string $message): void
    {
        $this->renderHeader('OpenNfse');
        echo '<div class="nfse-ui nfse-page">' . Ui::callout('danger', 'Erro', $message) . '</div>';
        $this->renderFooter();
    }

    public function renderSuccess(string $message): void
    {
        $this->renderHeader('OpenNfse');
        echo '<div class="nfse-ui nfse-page">' . Ui::callout('ok', $message) . '</div>';
        $this->renderFooter();
    }
}
