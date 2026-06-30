<?php

declare(strict_types=1);

namespace OpenNfse\Services;

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
        $this->renderHeader('OpenNFS-e');
        echo '<div class="errorbox"><strong>Erro:</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        $this->renderFooter();
    }

    public function renderSuccess(string $message): void
    {
        $this->renderHeader('OpenNFS-e');
        echo '<div class="successbox">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        $this->renderFooter();
    }
}
