<?php

declare(strict_types=1);

namespace OpenNfse;

use OpenNfse\Controllers\AdminController;
use OpenNfse\Controllers\ClientController;
use OpenNfse\Hooks\ClientInvoiceHook;
use OpenNfse\Hooks\InvoiceHook;
use OpenNfse\Hooks\InvoicePaidHook;
use OpenNfse\Migrations\Migrator;
use OpenNfse\Services\CronService;
use OpenNfse\Services\UiService;

final class Module
{
    public static function migrator(): Migrator
    {
        return new Migrator();
    }

    public static function ui(): UiService
    {
        return new UiService();
    }

    public static function controller(): AdminController
    {
        return new AdminController();
    }

    public static function clientController(): ClientController
    {
        return new ClientController();
    }

    public static function invoiceHook(): InvoiceHook
    {
        return new InvoiceHook();
    }

    public static function clientInvoiceHook(): ClientInvoiceHook
    {
        return new ClientInvoiceHook();
    }

    public static function invoicePaidHook(): InvoicePaidHook
    {
        return new InvoicePaidHook();
    }

    public static function cron(): CronService
    {
        return new CronService();
    }
}
