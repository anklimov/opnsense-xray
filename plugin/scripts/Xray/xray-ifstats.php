#!/usr/local/bin/php
<?php
/**
 * xray-ifstats.php — E4: статистика TUN-интерфейса и процессов для панели Diagnostics.
 *
 * Читает имя TUN-интерфейса из OPNsense config.xml,
 * собирает: IP, bytes in/out, состояние, uptime процессов xray-core и tun2socks.
 * Выводит JSON — ServiceController::diagnosticsAction() декодирует и отдаёт в GUI.
 */

require_once('config.inc');
require_once('/usr/local/opnsense/mvc/app/library/OPNsense/Xray/ConfigBuilder.php');

// ─── Per-instance path helpers (mirrors xray-service-control.php) ────────────
function xray_pid_path(string $uuid): string  { return "/var/run/xray_core_{$uuid}.pid"; }
function t2s_pid_path(string $uuid): string   { return "/var/run/tun2socks_{$uuid}.pid"; }

// ─── Читаем config ────────────────────────────────────────────────────────────
// Принимаем UUID инстанса как первый аргумент (передаётся из actions_xray.conf %1)
$instUuid = isset($argv[1]) ? preg_replace('/[^0-9a-fA-F\-]/', '', trim($argv[1])) : '';

$cfg = OPNsense\Core\Config::getInstance()->object();
$ins = $cfg->OPNsense->xray->instances ?? null;

$inst = null;
$instArr = null;
if ($ins) {
    foreach ($ins->instance as $candidate) {
        if ($instUuid === '' || (string)$candidate['uuid'] === $instUuid) {
            $inst = $candidate;
            if ($instUuid !== '') break; // точное совпадение — берём его
        }
    }
}

// v4.0.1: единый парсинг полей (для raw-инстансов TUN-имя и SOCKS-порт
// берутся из самого config.json — extractRuntimeFields)
if ($inst !== null) {
    $instArr = OPNsense\Xray\ConfigBuilder::extractRuntimeFields(
        OPNsense\Xray\ConfigBuilder::parseInstance($inst));
    $instUuid = (string)$inst['uuid'];
}
$tunIface = $instArr !== null ? (string)$instArr['tun_iface'] : 'proxytun2socks0';

// ─── Uptime процесса по PID-файлу ────────────────────────────────────────────
function proc_uptime(string $pidfile): ?int
{
    if (!file_exists($pidfile)) {
        return null;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid <= 0) {
        return null;
    }
    // etimes= returns elapsed seconds as a plain integer.
    // Returns empty for non-existent PIDs (no kill -0 needed, avoids EPERM for root processes).
    $raw = trim((string)shell_exec('ps -o etimes= -p ' . $pid));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int)$raw;
}

function format_uptime(?int $secs): string
{
    if ($secs === null) {
        return 'stopped';
    }
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    if ($d > 0) {
        return "{$d}d {$h}h {$m}m";
    }
    if ($h > 0) {
        return "{$h}h {$m}m {$s}s";
    }
    return "{$m}m {$s}s";
}

// ─── Статистика TUN-интерфейса через ifconfig ─────────────────────────────────
$ifOut = [];
exec('/sbin/ifconfig ' . escapeshellarg($tunIface) . ' 2>/dev/null', $ifOut, $ifRc);
$ifconfig = implode("\n", $ifOut);

// Парсим ключевые поля
$tunIp     = '';
$tunMask   = '';
$tunStatus = 'no carrier';
$bytesIn   = 0;
$bytesOut  = 0;
$pktsIn    = 0;
$pktsOut   = 0;
$mtu       = 0;

if ($ifRc === 0) {
    // inet x.x.x.x netmask 0xFFFFFF00
    if (preg_match('/inet\s+(\S+)\s+netmask\s+(\S+)/', $ifconfig, $m)) {
        $tunIp   = $m[1];
        // netmask может быть hex (0xffffff00) или CIDR — нормализуем в CIDR
        $hexMask = $m[2];
        if (str_starts_with($hexMask, '0x')) {
            $bits    = 0;
            $dec     = hexdec(substr($hexMask, 2));
            for ($i = 31; $i >= 0; $i--) {
                if ($dec & (1 << $i)) {
                    $bits++;
                }
            }
            $tunMask = '/' . $bits;
        } else {
            $tunMask = '/' . $hexMask;
        }
    }
    // flags: UP,RUNNING → статус
    if (preg_match('/flags=\S+<([^>]+)>/', $ifconfig, $m)) {
        $flags     = explode(',', strtolower($m[1]));
        $tunStatus = in_array('running', $flags, true) ? 'running' : 'down';
    }
    // mtu
    if (preg_match('/mtu\s+(\d+)/', $ifconfig, $m)) {
        $mtu = (int)$m[1];
    }
    // bytes in / bytes out (FreeBSD ifconfig format)
    // "        RX bytes:1234567 (1.2 MiB)"  — формат зависит от версии
    // FreeBSD 14: "        bytes 1234567 " в блоке RX/TX
    // Более надёжно: netstat
    $nsOut = [];
    exec('netstat -ibn -I ' . escapeshellarg($tunIface) . ' 2>/dev/null', $nsOut);
    foreach ($nsOut as $line) {
        $parts = preg_split('/\s+/', trim($line));
        // Ищем строку <Link> — только в ней реальные байты/пакеты.
        // FreeBSD формат с Idrop: Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
        //                               [0]  [1]  [2]     [3]     [4]   [5]   [6]   [7]    [8]   [9]   [10]  [11]
        // Name column may be truncated for long names; -I already filters to this iface
        if (isset($parts[2]) && strpos($parts[2], '<Link') === 0) {
            // Находим позицию Ipkts по первом целому числу после Network-поля.
            // Address может совпадать с именем интерфейса и содержать пробелы (разные длины имен) —
            // ищем позицию динамически: первое целое число > 0 после Network.
            $ipktsIdx = null;
            for ($pi = 3; $pi < count($parts); $pi++) {
                if (ctype_digit($parts[$pi])) {
                    $ipktsIdx = $pi;
                    break;
                }
            }
            if ($ipktsIdx !== null) {
                // Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
                $pktsIn   = (int)$parts[$ipktsIdx];
                $bytesIn  = (int)$parts[$ipktsIdx + 3];
                $pktsOut  = (int)$parts[$ipktsIdx + 4];
                $bytesOut = (int)$parts[$ipktsIdx + 6];
            }
            break;
        }
    }
}

// ─── Uptime процессов ─────────────────────────────────────────────────────────
$xrayUptimeSecs = $instUuid !== '' ? proc_uptime(xray_pid_path($instUuid)) : null;
$t2sUptimeSecs  = $instUuid !== '' ? proc_uptime(t2s_pid_path($instUuid))  : null;

// v4.0.0: драйвер TUN определяет, живёт ли tun2socks у инстанса
$tunDriver = $instArr !== null ? (string)$instArr['tun_driver'] : '';
if ($tunDriver === '') {
    $tunDriver = 'tun2socks';
}
if ($tunDriver === 'xray') {
    $t2sUptimeSecs = null;
}

// v4.0.0: диагностика окружения — версия xray-core, geo-данные, net.fibs
$xrayVersion = 'not installed';
if (file_exists('/usr/local/bin/xray-core')) {
    exec('/usr/local/bin/xray-core version 2>/dev/null', $verOut);
    $xrayVersion = trim((string)($verOut[0] ?? 'unknown'));
}

$geoipOk   = file_exists('/usr/local/etc/xray-core/geoip.dat') ? 1 : 0;
$geositeOk = file_exists('/usr/local/etc/xray-core/geosite.dat') ? 1 : 0;

$netFibs = null;
exec('/sbin/sysctl -n net.fibs 2>/dev/null', $fibsOut, $fibsRc);
if ($fibsRc === 0 && isset($fibsOut[0]) && ctype_digit(trim($fibsOut[0]))) {
    $netFibs = (int)trim($fibsOut[0]);
}

// Слушающие порты инстанса (sockstat)
$listenPorts = [];
$portsToList = [];
$socksEnabled = $instArr !== null && $instArr['socks5_enabled'] === true;
if ($socksEnabled) {
    $portsToList[] = (int)$instArr['socks5_port'];
}
if ($instArr !== null && $instArr['srv_enabled']) {
    $portsToList[] = (int)$instArr['srv_port'];
}
foreach (array_unique($portsToList) as $p) {
    if ($p <= 0 || $p > 65535) {
        continue;
    }
    exec('/usr/bin/sockstat -46l 2>/dev/null', $ssOut, $ssRc);
    $found = false;
    foreach ($ssOut as $line) {
        if (preg_match('/[:.]' . $p . '\s*$/', trim($line))) {
            $found = true;
            break;
        }
    }
    $listenPorts[] = ['port' => $p, 'listening' => $found ? 1 : 0];
}

// ─── Ping RTT до VPN-сервера ─────────────────────────────────────────────────
// v3.2.0: адрес берётся из сервера группы, если инстанс работает в режиме group
// (или это конфиг от v3.1.0, где режима нет, но сервер выбран).
$outboundJson = $instArr !== null ? (string)$instArr['outbound_config'] : '';
$activeServer = $instArr !== null ? (string)$instArr['server'] : '';
$nodeSource   = $instArr !== null ? (string)$instArr['node_source'] : '';
if ($activeServer !== '' && $nodeSource !== 'config') {
    $groups = $cfg->OPNsense->xray->groups ?? null;
    if ($groups) {
        foreach ($groups->server as $srv) {
            if ((string)$srv['uuid'] === $activeServer) {
                $outboundJson = (string)($srv->outbound_config ?? '');
                break;
            }
        }
    }
}
$outboundArr  = json_decode($outboundJson, true);
$serverAddr   = $outboundArr['settings']['vnext'][0]['address'] ?? '';
$pingRtt = 'N/A';
if ($serverAddr !== '') {
    exec('/sbin/ping -c 3 -W 2 ' . escapeshellarg($serverAddr) . ' 2>/dev/null', $pingOut, $pingRc);
    if ($pingRc === 0) {
        // Ищем "round-trip min/avg/max/stddev = 1.234/2.345/3.456/0.567 ms"
        $pingOutput = implode("\n", $pingOut);
        if (preg_match('/round-trip.+=\s*[\d.]+\/([\d.]+)\//', $pingOutput, $pm)) {
            $pingRtt = $pm[1] . ' ms';
        }
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

// ─── Сборка результата ────────────────────────────────────────────────────────
$result = [
    'tun_interface' => $tunIface,
    'tun_driver'    => $tunDriver,
    'tun_status'    => $tunIface !== '' ? $tunStatus : 'no interface configured',
    'tun_ip'        => $tunIp ? ($tunIp . $tunMask) : '',
    'mtu'           => $mtu,
    'bytes_in'      => $bytesIn,
    'bytes_out'     => $bytesOut,
    'pkts_in'       => $pktsIn,
    'pkts_out'      => $pktsOut,
    'bytes_in_hr'   => format_bytes($bytesIn),
    'bytes_out_hr'  => format_bytes($bytesOut),
    'xray_uptime'       => format_uptime($xrayUptimeSecs),
    'xray_uptime_secs'  => $xrayUptimeSecs,
    'tun2socks_uptime'      => format_uptime($t2sUptimeSecs),
    'tun2socks_uptime_secs' => $t2sUptimeSecs,
    'server_address'        => $serverAddr,
    'ping_rtt'              => $pingRtt,
    'xray_version'          => $xrayVersion,
    'xray_native_tun_ok'    => (preg_match('/Xray\s+(\d+)\./', $xrayVersion, $vm) && (int)$vm[1] >= 26) ? 1 : 0,
    'geoip_ok'              => $geoipOk,
    'geosite_ok'             => $geositeOk,
    'net_fibs'              => $netFibs,
    'listen_ports'          => $listenPorts,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
