<?php

function xray_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return isset($cfg->OPNsense->xray->general->enabled)
        && (string)$cfg->OPNsense->xray->general->enabled === '1';
}

function xray_configure(): array
{
    return ['bootup' => ['xray_configure_do:10']];
}

/**
 * BUG-1 FIX: убрана хрупкая goto-логика управления lock-файлом.
 *
 * v4.0.0: делегирует запуск скрипту xray-service-control.php — он один
 * знает о драйверах TUN (xray/tun2socks), генерирует конфиг через
 * ConfigBuilder и валидирует его перед стартом.
 */
function xray_configure_do(bool $verbose = false): void
{
    if (!xray_enabled()) {
        return;
    }

    $ctrl = '/usr/local/opnsense/scripts/Xray/xray-service-control.php';
    if (!file_exists($ctrl) || !file_exists('/usr/local/bin/xray-core')) {
        return;
    }

    exec('/usr/local/bin/php ' . escapeshellarg($ctrl) . ' start 2>&1', $out, $rc);
    if ($verbose) {
        echo implode("\n", $out) . "\n";
        echo "xray: boot setup " . ($rc === 0 ? 'complete' : 'failed') . "\n";
    }

    // reload firewall/routing — выполняется всегда
    @include_once('filter.inc');
    @include_once('interfaces.inc');
    @include_once('system.inc');

    if (function_exists('filter_configure_sync')) {
        filter_configure_sync();
    } elseif (function_exists('filter_configure')) {
        filter_configure();
    }

    if (function_exists('system_routing_configure')) {
        system_routing_configure();
    }

    if ($verbose) {
        echo "xray: boot setup complete\n";
    }
}

/**
 * v3.0.0: регистрирует один сервис на каждый инстанс.
 */
function xray_services()
{
    $services  = [];
    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;

    if ($instances) {
        foreach ($instances->instance as $inst) {
            $inst_uuid = (string)$inst['uuid'];
            if ($inst_uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name       = (string)($inst->name ?? 'default');
            $services[] = [
                'description' => 'Xray (' . $name . ')',
                'configd'     => [
                    'restart' => ['xray restart ' . $inst_uuid],
                    'start'   => ['xray start '   . $inst_uuid],
                    'stop'    => ['xray stop '    . $inst_uuid],
                ],
                'name'    => 'xray',
                'pidfile' => "/var/run/xray_core_{$inst_uuid}.pid",
            ];
        }
    }

    return $services;
}

/**
 * E1: регистрирует cron-задание для watchdog.
 */
function xray_cron(): array
{
    return [
        ['xray_cron_watchdog', '/', '*', '*', '*', '*'],
    ];
}

function xray_cron_watchdog(): void
{
    (new OPNsense\Core\Backend())->configdRun('xray watchdog');
}

function xray_xmlrpc_sync()
{
    return [];
}
