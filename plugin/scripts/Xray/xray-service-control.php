#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('/usr/local/opnsense/mvc/app/library/OPNsense/Xray/ConfigBuilder.php');

use OPNsense\Xray\ConfigBuilder;

// ─── Shared constants (not per-instance) ─────────────────────────────────────
define('XRAY_BIN',          '/usr/local/bin/xray-core');
define('XRAY_CONF_DIR',     '/usr/local/etc/xray-core');
define('T2S_BIN',           '/usr/local/tun2socks/tun2socks');
define('T2S_CONF_DIR',      '/usr/local/tun2socks');
// BUG-7 FIX: stderr демонов в лог-файл вместо /dev/null
define('XRAY_DAEMON_LOG',   '/var/log/xray-core.log'); // общий лог (fallback)
define('XRAY_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt');

// ─── Per-instance path functions ─────────────────────────────────────────────
// v3.0.0: все runtime-файлы именуются по UUID инстанса, чтобы N инстансов
// не конфликтовали за один PID-файл / конфиг / lock.
function xray_conf_path(string $inst_uuid): string
{
    return XRAY_CONF_DIR . "/config-{$inst_uuid}.json";
}
function xray_pid_path(string $inst_uuid): string
{
    return "/var/run/xray_core_{$inst_uuid}.pid";
}
function t2s_conf_path(string $inst_uuid): string
{
    return T2S_CONF_DIR . "/config-{$inst_uuid}.yaml";
}
function t2s_pid_path(string $inst_uuid): string
{
    return "/var/run/tun2socks_{$inst_uuid}.pid";
}
function xray_lock_path(string $inst_uuid): string
{
    return "/var/run/xray_start_{$inst_uuid}.lock";
}
function xray_stopped_flag(string $inst_uuid): string
{
    return "/var/run/xray_stopped_{$inst_uuid}.flag";
}
function xray_instance_log(string $inst_uuid): string
{
    return "/var/log/xray-core-{$inst_uuid}.log";
}

// ─── Read config from OPNsense config.xml ────────────────────────────────────

/**
 * Читает все инстансы из config.xml.
 * Парсинг полей делегирован ConfigBuilder::parseInstance() (v4.0.0).
 * Возвращает массив, индексированный по inst_uuid.
 */
function xray_get_all_instances(): array
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $g   = $cfg->OPNsense->xray->general   ?? null;
    $ins = $cfg->OPNsense->xray->instances ?? null;

    $globalEnabled = (string)($g->enabled ?? '0') === '1';

    if (!$ins) {
        return [];
    }

    $result = [];
    foreach ($ins->instance as $inst) {
        $inst_uuid = (string)$inst['uuid'];
        if ($inst_uuid === '') {
            continue;
        }
        $c = ConfigBuilder::parseInstance($inst);
        // v4.0.1: для raw-инстансов TUN-имя/MTU и SOCKS-адрес/порт берутся
        // из самого config.json (в raw-режиме поля GUI скрыты)
        $c = ConfigBuilder::extractRuntimeFields($c);
        $c['inst_uuid'] = $inst_uuid;
        $c['enabled']    = $globalEnabled && (string)($inst->enabled ?? '1') === '1';
        $result[$inst_uuid] = $c;
    }
    return $result;
}

/**
 * Читает конфиг конкретного инстанса по его UUID.
 * Если UUID не указан — возвращает первый найденный инстанс (обратная совместимость).
 */
function xray_get_config(string $inst_uuid = ''): array
{
    $all = xray_get_all_instances();
    if (empty($all)) {
        return [];
    }
    if ($inst_uuid !== '' && isset($all[$inst_uuid])) {
        return $all[$inst_uuid];
    }
    // Возвращаем первый инстанс если UUID не указан
    return reset($all);
}

/**
 * v4.0.0: сборка полного конфига через ConfigBuilder.
 * Ошибки конфигурации печатаются и возвращают null.
 */
function xray_build_config(string $inst_uuid, bool $verbose = true): ?array
{
    try {
        return ConfigBuilder::buildForInstance($inst_uuid);
    } catch (\RuntimeException $e) {
        if ($verbose) {
            echo "ERROR: {$e->getMessage()}\n";
        }
        return null;
    }
}

// ─── Write xray config.json (per-instance) ───────────────────────────────────
function xray_write_config(string $inst_uuid): bool
{
    if (!is_dir(XRAY_CONF_DIR)) {
        mkdir(XRAY_CONF_DIR, 0750, true);
    }

    $config = xray_build_config($inst_uuid);
    if ($config === null) {
        return false;
    }

    $confFile = xray_conf_path($inst_uuid);
    file_put_contents($confFile, ConfigBuilder::render($config));
    chmod($confFile, 0640);
    return true;
}

// ─── Write tun2socks config.yaml (per-instance, legacy driver) ───────────────
function t2s_write_config(array $c): void
{
    if (!is_dir(T2S_CONF_DIR)) {
        mkdir(T2S_CONF_DIR, 0750, true);
    }
    $inst_uuid = $c['inst_uuid'];
    $yaml = "proxy: socks5://{$c['socks5_listen']}:{$c['socks5_port']}\n"
          . "device: {$c['tun_iface']}\n"
          . "mtu: {$c['mtu']}\n"
          . "loglevel: info\n";
    file_put_contents(t2s_conf_path($inst_uuid), $yaml);
    chmod(t2s_conf_path($inst_uuid), 0640);
}

// ─── PID helpers (FreeBSD: no posix extension — use /bin/kill) ───────────────
function proc_is_running(string $pidfile): bool
{
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function proc_kill(string $pidfile): void
{
    if (!file_exists($pidfile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid > 0) {
        // БАГ-4 FIX: проверяем что PID принадлежит нашему процессу
        $comm = trim((string)shell_exec('ps -o comm= -p ' . $pid . ' 2>/dev/null'));
        if ($comm === '' || (strpos($comm, 'xray') === false && strpos($comm, 'tun2socks') === false)) {
            @unlink($pidfile);
            return;
        }

        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

// Pidfile супервизора daemon(8). По этим файлам xray-log-reopen.sh после
// ротации шлёт SIGHUP — только демонам, запущенным с -H.
function daemon_sup_pid_path(string $pidfile): string
{
    return '/var/run/xray-daemon-' . basename($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile, string $logfile = '', array $env = []): void
{
    // Лог держит сам daemon(8): -o пишет вывод процесса в файл, -H по SIGHUP
    // закрывает его и открывает заново (SIGHUP шлёт xray-log-reopen.sh из
    // newsyslog, см. etc/newsyslog.conf.d/xray.conf). Прежний shell-редирект
    // `>> log` открывал файл один раз: после ротации демон писал в удалённый
    // inode, и лог молча пропадал. Свой stdout daemon отдаёт в /dev/null,
    // иначе exec() ждал бы закрытия пайпа.
    // v4.0.1: $env — переменные окружения процесса (см. XRAY_LOCATION_ASSET).
    // Имя переменной нельзя заключать в кавычки ('VAR'=x не является присваиванием
    // в sh) — поэтому ключи валидируются регуляркой, экранируется только значение.
    $envPrefix = '';
    foreach ($env as $k => $v) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$k)) {
            continue;
        }
        $envPrefix .= $k . '=' . escapeshellarg((string)$v) . ' ';
    }
    $log = $logfile !== '' ? $logfile : XRAY_DAEMON_LOG;
    exec($envPrefix . '/usr/sbin/daemon -H -o ' . escapeshellarg($log)
       . ' -P ' . escapeshellarg(daemon_sup_pid_path($pidfile))
       . ' -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' > /dev/null 2>&1 &');
}

// ─── Per-instance lock helpers ───────────────────────────────────────────────
/**
 * Захватывает эксклюзивный non-blocking lock для конкретного инстанса.
 * Возвращает дескриптор при успехе, false если lock уже захвачен.
 * Вызывающий ОБЯЗАН вызвать lock_release($fd, $inst_uuid) после завершения.
 *
 * @return resource|false
 */
function lock_acquire(string $inst_uuid)
{
    $lockPath = xray_lock_path($inst_uuid);
    $fd = fopen($lockPath, 'c');
    if ($fd === false) {
        return false;
    }
    if (!flock($fd, LOCK_EX | LOCK_NB)) {
        fclose($fd);
        return false;
    }
    fwrite($fd, (string)getmypid());
    fflush($fd);
    return $fd;
}

/**
 * Освобождает lock инстанса, закрывает дескриптор, удаляет lock-файл.
 *
 * @param resource $fd
 */
function lock_release($fd, string $inst_uuid): void
{
    flock($fd, LOCK_UN);
    fclose($fd);
    @unlink(xray_lock_path($inst_uuid));
}

// ─── BUG-3 FIX: config validation before start ──────────────────────────────
function xray_validate_config(string $confFile): bool
{
    if (!file_exists(XRAY_BIN)) {
        return true;
    }
    if (!file_exists($confFile)) {
        echo "ERROR: config file not found: {$confFile}\n";
        return false;
    }
    // v4.0.1: XRAY_LOCATION_ASSET — xray ищет geoip.dat/geosite.dat в CWD
    // (у daemon(8)/configd это "/"), а не рядом с конфигом; без env
    // любые geosite/geoip правила роняли -test и старт
    exec('XRAY_LOCATION_ASSET=' . escapeshellarg(XRAY_CONF_DIR)
       . ' ' . escapeshellarg(XRAY_BIN)
       . ' -test -c ' . escapeshellarg($confFile) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "ERROR: xray config validation failed:\n" . implode("\n", $out) . "\n";
        return false;
    }
    return true;
}

// ─── lo0 alias management ─────────────────────────────────────────────────────
function lo0_needs_alias(string $addr): bool
{
    if ($addr === '127.0.0.1' || $addr === '0.0.0.0') {
        return false;
    }
    $parts = explode('.', $addr);
    return count($parts) === 4 && $parts[0] === '127';
}

function lo0_alias_ensure(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        echo "WARNING: Cannot read lo0 interface\n";
        return;
    }
    $ifOutput = implode("\n", $out);
    if (strpos($ifOutput, $addr) !== false) {
        return;
    }
    exec('/sbin/ifconfig lo0 alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out2, $rc2);
    if ($rc2 !== 0) {
        echo "WARNING: Failed to add lo0 alias {$addr}\n";
    } else {
        echo "INFO: Added lo0 alias {$addr}\n";
    }
}

function lo0_alias_remove(string $addr): void
{
    if (!lo0_needs_alias($addr)) {
        return;
    }
    exec('/sbin/ifconfig lo0 -alias ' . escapeshellarg($addr) . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) {
        echo "INFO: Removed lo0 alias {$addr}\n";
    }
}

// ─── TUN interface helpers ────────────────────────────────────────────────────

function tun_iface_up(string $iface): bool
{
    if ($iface === '') {
        return false;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/**
 * B9: уничтожение TUN-интерфейса. Для xray-драйвера — подстраховка после
 * SIGKILL (чистый shutdown уничтожает интерфейс сам), для tun2socks — не
 * вызывается (интерфейс принадлежит t2s).
 */
function tun_destroy(string $iface): void
{
    if (empty($iface)) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' destroy 2>/dev/null');
}

// ─── High-level per-instance actions ─────────────────────────────────────────

/**
 * do_stop() — останавливает инстанс (xray-core и, для legacy-драйвера,
 * tun2socks), выставляет stopped flag.
 */
function do_stop(string $inst_uuid, ?string $tunIface = null): void
{
    $c      = xray_get_config($inst_uuid);
    $driver = ($c['tun_driver'] ?? 'tun2socks') === 'xray' ? 'xray' : 'tun2socks';
    if ($tunIface === null) {
        $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
    }

    // Останавливаем tun2socks первым — он держит TUN open (legacy driver)
    proc_kill(t2s_pid_path($inst_uuid));
    // Останавливаем xray-core
    proc_kill(xray_pid_path($inst_uuid));

    // v4.0.0: xray-драйвер — уничтожаем TUN, если демон убит нечисто
    // (после SIGKILL интерфейс остаётся и мешает следующему старту)
    if ($driver === 'xray') {
        tun_destroy($tunIface);
    }

    // Удаляем lo0 alias если был добавлен
    $c2 = xray_get_config($inst_uuid);
    if (!empty($c2) && !empty($c2['socks5_enabled'])) {
        lo0_alias_remove($c2['socks5_listen'] ?? '127.0.0.1');
    }

    // БАГ-5 FIX: флаг намеренной остановки — watchdog не перезапускает
    file_put_contents(xray_stopped_flag($inst_uuid), date('Y-m-d H:i:s'));

    echo "Stopped.\n";
}

/**
 * do_start() — генерирует конфиг через ConfigBuilder, запускает xray-core и,
 * для legacy-драйвера, tun2socks.
 */
function do_start(array $c): bool
{
    if (!file_exists(XRAY_BIN)) {
        echo "ERROR: xray-core not found at " . XRAY_BIN . "\n";
        return false;
    }

    $driver    = ($c['tun_driver'] ?? 'tun2socks') === 'xray' ? 'xray' : 'tun2socks';
    $inst_uuid = $c['inst_uuid'];

    if ($driver === 'tun2socks' && !file_exists(T2S_BIN)) {
        echo "ERROR: tun2socks not found at " . T2S_BIN
           . " — switch the instance TUN driver to \"Xray TUN inbound (native)\"\n";
        return false;
    }

    // B7: захватываем per-instance lock перед запуском
    $lock = lock_acquire($inst_uuid);
    if ($lock === false) {
        echo "INFO: Another start is already in progress for instance {$inst_uuid} (lock held). Skipping.\n";
        return true;
    }

    try {
        // БАГ-5 FIX: снимаем флаг намеренной остановки
        @unlink(xray_stopped_flag($inst_uuid));

        if (!xray_write_config($inst_uuid)) {
            return false;
        }
        if ($driver === 'tun2socks') {
            t2s_write_config($c);
        }

        if (!empty($c['socks5_enabled'])) {
            lo0_alias_ensure($c['socks5_listen']);
        }

        // BUG-3 FIX: валидация конфига до запуска
        if (!xray_validate_config(xray_conf_path($inst_uuid))) {
            return false;
        }

        $instLog = xray_instance_log($inst_uuid);
        if (!proc_is_running(xray_pid_path($inst_uuid))) {
            // v4.0.1: XRAY_LOCATION_ASSET — geoip.dat/geosite.dat ищутся
            // в /usr/local/etc/xray-core, а не в CWD демона
            proc_start(
                XRAY_BIN,
                'run -c ' . escapeshellarg(xray_conf_path($inst_uuid)),
                xray_pid_path($inst_uuid),
                $instLog,
                ['XRAY_LOCATION_ASSET' => XRAY_CONF_DIR]
            );
            usleep(800000);
        }
        if ($driver === 'tun2socks' && !proc_is_running(t2s_pid_path($inst_uuid))) {
            proc_start(T2S_BIN, '--config ' . escapeshellarg(t2s_conf_path($inst_uuid)), t2s_pid_path($inst_uuid), $instLog);
            usleep(800000);
        }

        // Назначаем IP на TUN через syshook (ждёт TUN, для tun2socks читает IP
        // из config, reload firewall)
        exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray ' . escapeshellarg($inst_uuid) . ' &');

        echo "Started.\n";
        return true;
    } finally {
        // B7: освобождаем lock гарантированно (даже при исключении)
        lock_release($lock, $inst_uuid);
    }
}

/**
 * Статус одного инстанса. v4.0.0: для xray-драйвера tun-статус берётся из
 * наличия интерфейса (tun2socks-ключ = "n/a"); raw-инстансы могут не иметь
 * tun inbound вовсе, поэтому ok = xray-core running.
 */
function xray_instance_status(array $c): array
{
    $inst_uuid = $c['inst_uuid'];
    $xray = proc_is_running(xray_pid_path($inst_uuid));
    $driver = ($c['tun_driver'] ?? 'tun2socks') === 'xray' ? 'xray' : 'tun2socks';

    if ($driver === 'xray') {
        // v4.0.1: raw-конфиг может не иметь tun-inbound (или имени) — n/a
        $tun = ($c['tun_iface'] ?? '') !== ''
            ? (tun_iface_up($c['tun_iface']) ? 'running' : 'stopped')
            : 'n/a';
        return [
            'status'    => $xray ? 'ok' : 'stopped',
            'xray_core' => $xray ? 'running' : 'stopped',
            'tun'       => $tun,
            'tun2socks' => 'n/a',
            'driver'    => 'xray',
            'inst_uuid' => $inst_uuid,
        ];
    }

    $t2s = proc_is_running(t2s_pid_path($inst_uuid));
    return [
        'status'    => ($xray && $t2s) ? 'ok' : 'stopped',
        'xray_core' => $xray ? 'running' : 'stopped',
        'tun2socks' => $t2s ? 'running' : 'stopped',
        'driver'    => 'tun2socks',
        'inst_uuid' => $inst_uuid,
    ];
}

function do_status(string $inst_uuid = ''): void
{
    if ($inst_uuid !== '') {
        $c = xray_get_config($inst_uuid);
        if (empty($c)) {
            echo json_encode(['status' => 'stopped']) . "\n";
            return;
        }
        echo json_encode(xray_instance_status($c)) . "\n";
        return;
    }

    // Без UUID: статус всех инстансов + агрегированный статус
    $all = xray_get_all_instances();
    if (empty($all)) {
        echo json_encode(['status' => 'stopped', 'xray_core' => 'stopped']) . "\n";
        return;
    }
    // Для совместимости с текущим GUI: возвращаем статус первого инстанса
    $first = reset($all);
    echo json_encode(xray_instance_status($first)) . "\n";
}

function do_status_all(): void
{
    $all    = xray_get_all_instances();
    $result = [];
    foreach ($all as $inst_uuid => $c) {
        $st = xray_instance_status($c);
        $st['name'] = $c['name'];
        $result[$inst_uuid] = $st;
    }
    echo json_encode($result) . "\n";
}

// ─── Main ─────────────────────────────────────────────────────────────────────
$action    = $argv[1] ?? 'status';
$inst_uuid = isset($argv[2]) ? trim($argv[2]) : '';

// Базовая санитизация UUID аргумента
// configd передаёт литерал "%1" когда аргумент не указан — отбрасываем
if ($inst_uuid !== '') {
    $inst_uuid = preg_replace('/[^0-9a-fA-F\-]/', '', $inst_uuid);
    // UUID должен быть минимум 32 hex-символа + 4 дефиса = 36 символов
    if (strlen($inst_uuid) < 36) {
        $inst_uuid = '';
    }
}

switch ($action) {
    case 'start':
        if ($inst_uuid !== '') {
            $c = xray_get_config($inst_uuid);
            if (empty($c) || !$c['enabled']) {
                echo "Xray is disabled or instance not found.\n";
                exit(0);
            }
            $ok = do_start($c);
            exit($ok ? 0 : 1);
        }
        // Запускаем все включённые инстансы
        $all = xray_get_all_instances();
        if (empty($all)) {
            echo "No instances configured.\n";
            exit(0);
        }
        $anyFailed = false;
        foreach ($all as $uuid => $c) {
            if (!$c['enabled']) continue;
            if (!do_start($c)) {
                $anyFailed = true;
            }
        }
        exit($anyFailed ? 1 : 0);

    case 'stop':
        if ($inst_uuid !== '') {
            do_stop($inst_uuid);
        } else {
            foreach (array_keys(xray_get_all_instances()) as $uuid) {
                do_stop($uuid);
            }
        }
        break;

    case 'restart':
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                do_start($c);
            }
        } else {
            $all = xray_get_all_instances();
            foreach ($all as $uuid => $c) {
                $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
                do_stop($uuid, $tunIface);
            }
            sleep(1);
            foreach ($all as $uuid => $c) {
                if ($c['enabled']) do_start($c);
            }
        }
        break;

    case 'reconfigure':
        // B10: возвращаем реальный статус
        if ($inst_uuid !== '') {
            $c        = xray_get_config($inst_uuid);
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($inst_uuid, $tunIface);
            sleep(1);
            if (!empty($c) && $c['enabled']) {
                $ok = do_start($c);
                if ($ok) {
                    echo "OK\n";
                    exit(0);
                } else {
                    echo "ERROR: Failed to start Xray services for instance {$inst_uuid}.\n";
                    exit(1);
                }
            } else {
                echo "Xray disabled — services stopped.\n";
                exit(0);
            }
        }
        // Без UUID: рекофигурируем все инстансы
        $all       = xray_get_all_instances();
        $allStopped = [];
        foreach ($all as $uuid => $c) {
            $tunIface = $c['tun_iface'] ?? 'proxytun2socks0';
            do_stop($uuid, $tunIface);
            $allStopped[$uuid] = $c;
        }
        sleep(1);
        $anyFailed = false;
        foreach ($allStopped as $uuid => $c) {
            if ($c['enabled']) {
                if (!do_start($c)) {
                    $anyFailed = true;
                }
            }
        }
        if ($anyFailed) {
            echo "ERROR: One or more instances failed to start.\n";
            exit(1);
        }
        echo "OK\n";
        exit(0);

    case 'status':
        do_status($inst_uuid);
        break;

    case 'statusall':
        do_status_all();
        break;

    case 'generate':
        // v4.0.0: печать сгенерированного конфига (wizard) как есть (raw)
        $c = $inst_uuid !== '' ? xray_get_config($inst_uuid) : xray_get_config();
        if (empty($c)) {
            echo "ERROR: No xray config found in OPNsense config.xml\n";
            exit(1);
        }
        $config = xray_build_config($c['inst_uuid']);
        if ($config === null) {
            exit(1);
        }
        echo ConfigBuilder::render($config) . "\n";
        exit(0);

    case 'validate':
        // БАГ-6 FIX: сухой прогон через временный файл (рабочий конфиг не перезаписывается)
        $c = $inst_uuid !== '' ? xray_get_config($inst_uuid) : xray_get_config();
        if (empty($c)) {
            echo "ERROR: No xray config found in OPNsense config.xml\n";
            exit(1);
        }
        $tmpBase = tempnam('/tmp', 'xray-validate-');
        if ($tmpBase === false) {
            echo "ERROR: Cannot create temp file for validation\n";
            exit(1);
        }
        $tmpConf = $tmpBase . '.json';
        @unlink($tmpBase);
        $exitCode = 1;
        try {
            $config = xray_build_config($c['inst_uuid']);
            if ($config !== null) {
                file_put_contents($tmpConf, ConfigBuilder::render($config));
                chmod($tmpConf, 0600);
                if (xray_validate_config($tmpConf)) {
                    echo "OK: config is valid\n";
                    $exitCode = 0;
                }
            }
        } finally {
            @unlink($tmpConf);
        }
        exit($exitCode);

    case 'validatefile':
        // v4.0.0: валидация произвольного файла-черновика (кнопка Validate
        // Config в редакторе Raw JSON). Строгий белый список путей /tmp.
        $path = isset($argv[2]) ? (string)$argv[2] : '';
        if (!preg_match('#^/tmp/xray-val-[A-Za-z0-9._-]+$#', $path) || !is_file($path)) {
            echo "ERROR: invalid validation file path\n";
            exit(1);
        }
        if (xray_validate_config($path)) {
            echo "OK: config is valid\n";
            exit(0);
        }
        exit(1);

    case 'version':
        $ver = file_exists(XRAY_VERSION_FILE) ? trim(file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}

