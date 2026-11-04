<?php

namespace OPNsense\Xray;

/**
 * v4.0.0: единая точка генерации config.json для xray-core.
 *
 * Режимы (node_source):
 *   group  — узел (при LB — все узлы группы) + wizard-обвязка
 *   config — свой outbound JSON (при LB — список lb_custom) + wizard-обвязка
 *   none   — только серверные inbound'ы, без upstream
 *   raw    — full_config используется как есть (валидация — через xray -test)
 *
 * Схемы сверены с Xray-core v26.10.x (main):
 *   - tun inbound: settings {name, mtu, gateway[], autoSystemRoutingTable[],
 *     autoOutboundsInterface, autoSystemDnsToGateway}; на FreeBSD имя = tunN.
 *   - балансировщик: routing.balancers[{tag, selector, strategy{type}, fallbackTag}],
 *     стратегии leastPing | roundRobin | leastLoad | random; ссылки из правил
 *     через balancerTag. observatory — burstObservatory{subjectSelector, pingConfig}.
 *   - Xray-core НЕ поддерживает FakeIP — домены для TUN извлекает sniffing.
 */
class ConfigBuilder
{
    public const PROXY_PREFIX = 'proxy-';
    public const BALANCER_TAG = 'lb';
    public const DNS_OUT_TAG  = 'dns-out';

    /** Неявное правило: локальные адреса — всегда мимо прокси (антимloops). */
    private const PRIVATE_IPS = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16', '127.0.0.0/8', 'fc00::/7', 'fe80::/10',
    ];

    /* ═══════════════════════════════════════════════════════════════════════
     *  Public API
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Читает config.xml и собирает полный конфиг инстанса по его UUID.
     *
     * @throws \RuntimeException при ошибках конфигурации
     */
    public static function buildForInstance(string $instUuid): array
    {
        $cfg = \OPNsense\Core\Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if (!$ins) {
            throw new \RuntimeException('no xray instances configured');
        }

        $node = null;
        foreach ($ins->instance as $i) {
            if ((string)$i['uuid'] === $instUuid) {
                $node = $i;
                break;
            }
        }
        if ($node === null) {
            throw new \RuntimeException("instance {$instUuid} not found");
        }

        $inst    = self::parseInstance($node);
        $rules   = self::loadRules($cfg, $instUuid);
        $servers = self::loadServers($cfg);

        return self::build($inst, $rules, $servers);
    }

    /**
     * Чистый билдер: $inst — массив из parseInstance(), $rules — список
     * ['type','value','action'], $servers — список серверов групп.
     *
     * @throws \RuntimeException
     */
    public static function build(array $inst, array $rules, array $servers): array
    {
        $mode = $inst['node_source'];
        if ($mode === 'raw') {
            return self::buildRaw($inst);
        }
        $proxies  = self::collectProxies($inst, $servers);
        $balancer = $inst['lb_enabled'] && count($proxies) > 0;
        $defaultProxyTag = count($proxies) > 0 ? $proxies[0]['tag'] : null;

        $needDns = $inst['dns_mode'] === 'hijack'
            || ($inst['tun_auto_dns'] && $inst['tun_auto_routing'] === 'full');

        $inbounds  = self::buildInbounds($inst);
        $outbounds = self::buildOutbounds($proxies, $needDns);
        [$routing, $observatory] = self::buildRouting(
            $inst, $rules, $inbounds, $balancer, $defaultProxyTag
        );

        $config = [
            'log'      => ['loglevel' => $inst['loglevel']],
            'inbounds' => $inbounds,
            'outbounds'=> $outbounds,
            'routing'  => $routing,
        ];
        if ($needDns) {
            $config['dns'] = self::buildDns($inst);
        }
        if ($observatory !== null) {
            $config['burstObservatory'] = $observatory;
        }

        return $config;
    }

    /**
     * Pretty-JSON представление конфига.
     */
    public static function render(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * v4.0.1: нормализует SimpleXMLElement <instance> в плоский массив с дефолтами.
     * Аналог старого xray_parse_instance() из xray-service-control.php.
     */
    public static function parseInstance($node): array
    {
        $s = function (string $field, string $default = '') use ($node): string {
            $v = (string)($node->$field ?? '');
            return $v !== '' ? $v : $default;
        };
        $b = function (string $field, string $default = '0') use ($node): bool {
            $v = (string)($node->$field ?? '');
            return ($v !== '' ? $v : $default) === '1';
        };

        // B6: нормализация loglevel (наследие v1.x)
        $rawLevel = $s('loglevel', 'warning');
        $levelMap = ['e' => 'error', 'loglevel_error' => 'error'];
        $loglevel = $levelMap[$rawLevel] ?? ($rawLevel ?: 'warning');

        $mode = $s('node_source');
        $server = $s('server');
        // Наследие v3.1.0: пустой node_source + выбранный сервер = группа
        if ($mode === '') {
            $mode = $server !== '' ? 'group' : 'config';
        }

        return [
            'name'            => $s('name', 'default'),
            'node_source'     => $mode,
            'outbound_config' => $s('outbound_config'),
            'group'           => $s('group'),
            'server'          => $server,
            'full_config'     => $s('full_config'),

            'socks5_enabled'  => $b('socks5_enabled', '1'),
            'socks5_listen'   => $s('socks5_listen', '127.0.0.1'),
            'socks5_port'     => (int)$s('socks5_port', '10808'),

            'tun_driver'      => $s('tun_driver', 'tun2socks'),
            'tun_iface'       => $s('tun_interface', 'proxytun2socks0'),
            'tun_gateway'    => $s('tun_gateway', '10.255.0.1/30'),
            'mtu'            => (int)$s('mtu', '1500'),
            'tun_auto_routing' => $s('tun_auto_routing', 'off'),
            'tun_auto_dns'   => $b('tun_auto_dns'),

            'dns_mode'       => $s('dns_mode', 'off'),
            'dns_upstream'   => $s('dns_upstream', '1.1.1.1'),

            'lb_enabled'     => $b('lb_enabled'),
            'lb_strategy'    => $s('lb_strategy', 'leastPing'),
            'lb_probe_url'   => $s('lb_probe_url', 'https://connectivitycheck.gstatic.com/generate_204'),
            'lb_probe_interval' => $s('lb_probe_interval', '1m'),
            'lb_custom'      => $s('lb_custom'),

            'srv_enabled'    => $b('srv_enabled'),
            'srv_listen'     => $s('srv_listen', '127.0.0.1'),
            'srv_port'       => (int)$s('srv_port', '10086'),
            'srv_users'      => $s('srv_users'),
            'srv_transport'  => $s('srv_transport', 'ws'),
            'srv_path'       => $s('srv_path'),
            'srv_security'   => $s('srv_security', 'none'),
            'srv_tls_cert'   => $s('srv_tls_cert'),
            'srv_tls_key'    => $s('srv_tls_key'),
            'srv_reality_privatekey' => $s('srv_reality_privatekey'),
            'srv_reality_shortids'   => $s('srv_reality_shortids'),
            'srv_reality_servernames' => $s('srv_reality_servernames'),
            'srv_reality_dest' => $s('srv_reality_dest'),
            'srv_accept_proxy_protocol' => $b('srv_accept_proxy_protocol'),
            'srv_custom_inbounds' => $s('srv_custom_inbounds'),
            'srv_default_outbound' => $s('srv_default_outbound', 'direct'),

            'bypass_networks' => $s('bypass_networks'),
            'loglevel'       => $loglevel,
        ];
    }

    /**
     * v4.0.1: runtime-параметры raw-инстанса берутся ИЗ САМОГО config.json,
     * а не из полей GUI (которые в raw-режиме скрыты): имя TUN-интерфейса и
     * MTU — из tun-inbound, адрес/порт SOCKS — из socks/mixed-inbound
     * (Test-кнопка, legacy-драйвер tun2socks, lo0-alias). Если JSON не
     * декодируется — возвращаем как есть: ошибка выплывёт в buildRaw при старте.
     */
    public static function extractRuntimeFields(array $inst): array
    {
        if ($inst['node_source'] !== 'raw') {
            return $inst;
        }
        $raw = trim((string)($inst['full_config'] ?? ''));
        if ($raw === '') {
            return $inst;
        }
        $config = json_decode(self::stripJsonComments($raw), true);
        if (!is_array($config)) {
            return $inst;
        }

        $hasTun = false;
        $hasSocks = false;
        foreach (($config['inbounds'] ?? []) as $ib) {
            if (!is_array($ib)) {
                continue;
            }
            $proto = (string)($ib['protocol'] ?? '');
            if ($proto === 'tun') {
                $hasTun = true;
                $settings = is_array($ib['settings'] ?? null) ? $ib['settings'] : [];
                // FreeBSD: имя обязано быть tunN; без "name" интерфейс не ждём
                $inst['tun_iface'] = (string)($settings['name'] ?? '');
                if (!empty($settings['mtu'])) {
                    $inst['mtu'] = (int)$settings['mtu'];
                }
            } elseif ($proto === 'socks' || $proto === 'mixed') {
                if (!$hasSocks) { // первый socks/mixed inbound
                    $hasSocks = true;
                    if (isset($ib['listen'])) {
                        $inst['socks5_listen'] = (string)$ib['listen'];
                    }
                    if (!empty($ib['port'])) {
                        $inst['socks5_port'] = (int)$ib['port'];
                    }
                    $inst['socks5_enabled'] = true;
                }
            }
        }
        if (!$hasTun) {
            $inst['tun_iface'] = '';
        }
        if (!$hasSocks) {
            $inst['socks5_enabled'] = false;
        }
        return $inst;
    }

    /**
     * v4.0.4: статические проверки raw-конфига — то, что xray -test НЕ
     * сообщает (он не стартует inbound'ы, а умирает на первом же):
     *  - listen-адрес inbound'а не назначен ни одному интерфейсу роутера —
     *    xray падает на старте с «bind: can't assign requested address»
     *    (EADDRNOTAVAIL; типовой случай — конфиг, скопированный с другой
     *    машины, где этот IP был локальным);
     *  - имя tun-интерфейса не по документированной для FreeBSD схеме tunN
     *    (utunN — macOS-only; syshook ждёт именно имя из конфига).
     * Про "mtu" предупреждать не нужно: xray сам подставляет 1500
     * (infra/conf/tun.go v26.10.31). $localIps — для тестов; по умолчанию
     * собирается из ifconfig -a. Возвращает массив предупреждений.
     */
    public static function rawConfigIssues(string $json, ?array $localIps = null): array
    {
        $issues = [];
        $config = json_decode(self::stripJsonComments($json), true);
        if (!is_array($config)) {
            return $issues;
        }
        if ($localIps === null) {
            $localIps = self::systemIpAddresses();
        }
        $localIps = array_fill_keys($localIps, true);

        foreach (($config['inbounds'] ?? []) as $ib) {
            if (!is_array($ib)) {
                continue;
            }
            $proto = (string)($ib['protocol'] ?? '');
            $tag   = (string)($ib['tag'] ?? '');

            if ($proto === 'tun') {
                $settings = is_array($ib['settings'] ?? null) ? $ib['settings'] : [];
                $name     = (string)($settings['name'] ?? '');
                if ($name !== '' && !preg_match('/^tun\d+$/', $name)) {
                    $issues[] = 'tun inbound "' . $name . '": interface name is not tunN — '
                        . 'on FreeBSD the documented scheme is "tunN" (e.g. tun5); utunN is macOS-only';
                }
                continue; // tun не слушает сокеты
            }

            // v4.0.4: listen-адрес должен существовать на интерфейсах роутера
            $listen = trim((string)($ib['listen'] ?? ''));
            if ($listen === '' || $listen === '0.0.0.0' || $listen === '::') {
                continue;
            }
            if (!filter_var($listen, FILTER_VALIDATE_IP)) {
                continue; // hostname — xray резолвит сам, не проверяем
            }
            if (strpos($listen, '127.') === 0 || $listen === '::1') {
                continue; // 127.x плагин вешает на lo0 (lo0_alias_ensure)
            }
            if (!isset($localIps[strtolower($listen)])) {
                $issues[] = 'inbound "' . ($tag !== '' ? $tag : $proto) . '": listen address ' . $listen
                    . ' is not assigned to any interface on this router — xray will fail to start '
                    . 'with "bind: can\'t assign requested address"; use the router\'s LAN IP, '
                    . '"0.0.0.0" or "127.0.0.1"';
            }
        }
        return $issues;
    }

    /**
     * v4.0.4: все IPv4/IPv6-адреса интерфейсов системы (ifconfig -a).
     * Если выполнить не удалось — пустой массив (проверка listen деградирует).
     */
    private static function systemIpAddresses(): array
    {
        $out = [];
        exec('/sbin/ifconfig -a 2>/dev/null', $lines, $rc);
        if ($rc !== 0) {
            return [];
        }
        foreach ((array)$lines as $line) {
            if (preg_match('/\binet6?\s+([0-9a-fA-F:.]+)/', $line, $m)) {
                $out[] = strtolower(explode('%', $m[1])[0]);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * v4.0.1: вырезает комментарии в стиле xray-core (infra/conf/json/reader.go):
     * строчные `//` и `#`, блочные "slash-star ... star-slash". Внутри строк
     * (в т.ч. "https://...") символы не трогаются. json_decode комментарии
     * не принимает, хотя xray -test такой конфиг считает валидным.
     */
    public static function stripJsonComments(string $json): string
    {
        $len = strlen($json);
        $out = '';
        $state = 'content';
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            switch ($state) {
                case 'content':
                    if ($ch === '"') {
                        $state = 'dquote';
                        $out .= $ch;
                    } elseif ($ch === "'") {
                        $state = 'squote';
                        $out .= $ch;
                    } elseif ($ch === '\\') {
                        $state = 'escape';
                    } elseif ($ch === '#') {
                        $state = 'comment';
                    } elseif ($ch === '/') {
                        $state = 'slash';
                    } else {
                        $out .= $ch;
                    }
                    break;
                case 'escape':
                    $out .= '\\' . $ch;
                    $state = 'content';
                    break;
                case 'dquote':
                    if ($ch === '"') {
                        $state = 'content';
                        $out .= $ch;
                    } elseif ($ch === '\\') {
                        $state = 'dquote_escape';
                        $out .= $ch;
                    } else {
                        $out .= $ch;
                    }
                    break;
                case 'dquote_escape':
                    $out .= $ch;
                    $state = 'dquote';
                    break;
                case 'squote':
                    if ($ch === "'") {
                        $state = 'content';
                        $out .= $ch;
                    } elseif ($ch === '\\') {
                        $state = 'squote_escape';
                        $out .= $ch;
                    } else {
                        $out .= $ch;
                    }
                    break;
                case 'squote_escape':
                    $out .= $ch;
                    $state = 'squote';
                    break;
                case 'comment':
                    if ($ch === "\n") {
                        $state = 'content';
                        $out .= "\n";
                    }
                    break;
                case 'slash':
                    if ($ch === '/') {
                        $state = 'comment';
                    } elseif ($ch === '*') {
                        $state = 'mlcomment';
                    } else {
                        $state = 'content';
                        $out .= '/' . $ch;
                    }
                    break;
                case 'mlcomment':
                    if ($ch === '*') {
                        $state = 'mlstar';
                    }
                    break;
                case 'mlstar':
                    if ($ch === '/') {
                        $state = 'content';
                        $out .= ' ';
                    } elseif ($ch !== '*') {
                        $state = 'mlcomment';
                    }
                    break;
            }
        }
        return $out;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  Загрузка из config.xml
     * ═══════════════════════════════════════════════════════════════════════ */

    /** @return array список правил инстанса в порядке следования в config.xml */
    private static function loadRules($cfg, string $instUuid): array
    {
        $rr = $cfg->OPNsense->xray->routing_rules ?? null;
        if (!$rr) {
            return [];
        }
        $rules = [];
        foreach ($rr->rule as $r) {
            if ((string)($r->instance ?? '') !== $instUuid) {
                continue;
            }
            if ((string)($r->enabled ?? '1') !== '1') {
                continue;
            }
            $rules[] = [
                'type'   => (string)($r->type ?? 'domain'),
                'value'  => (string)($r->value ?? ''),
                'action' => (string)($r->action ?? 'proxy'),
            ];
        }
        return $rules;
    }

    /** @return array список ['uuid','group','name','outbound_config'] */
    private static function loadServers($cfg): array
    {
        $grp = $cfg->OPNsense->xray->groups ?? null;
        if (!$grp) {
            return [];
        }
        $servers = [];
        foreach ($grp->server as $srv) {
            $servers[] = [
                'uuid'            => (string)$srv['uuid'],
                'group'           => (string)($srv->group ?? ''),
                'name'            => (string)($srv->name ?? ''),
                'outbound_config' => (string)($srv->outbound_config ?? ''),
            ];
        }
        return $servers;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  Сборка
     * ═══════════════════════════════════════════════════════════════════════ */

    private static function buildRaw(array $inst): array
    {
        $raw = trim($inst['full_config']);
        if ($raw === '') {
            throw new \RuntimeException('config source is "raw" but full config JSON is empty');
        }
        // v4.0.1: xray принимает комментарии (//, #, блочные) — json_decode нет.
        // Strip до декодирования; в runtime-файл уходит нормализованный JSON
        // (комментарии не сохраняются — источник истины это config.xml).
        $config = json_decode(self::stripJsonComments($raw), true);
        if (!is_array($config)) {
            throw new \RuntimeException('full config is not valid JSON: ' . json_last_error_msg());
        }
        return $config;
    }

    /** @return array proxy-outbound'ы с тегами proxy-1..N */
    private static function collectProxies(array $inst, array $servers): array
    {
        $mode = $inst['node_source'];
        if ($mode === 'none') {
            return [];
        }

        $rawList = [];
        $alreadyDecoded = false;

        if ($mode === 'group') {
            if ($inst['lb_enabled']) {
                $group = $inst['group'];
                if ($group === '') {
                    throw new \RuntimeException('load balancing is enabled but no server group is selected');
                }
                foreach ($servers as $srv) {
                    if ($srv['group'] === $group) {
                        $rawList[] = $srv['outbound_config'];
                    }
                }
                if (empty($rawList)) {
                    throw new \RuntimeException('the selected server group has no servers');
                }
            } else {
                $uuid = $inst['server'];
                if ($uuid === '') {
                    throw new \RuntimeException('node source is "group" but no server is selected');
                }
                $found = false;
                foreach ($servers as $srv) {
                    if ($srv['uuid'] === $uuid) {
                        $rawList[] = $srv['outbound_config'];
                        $found = true;
                        break;
                    }
                }
                if (!$found || trim($rawList[0]) === '') {
                    throw new \RuntimeException("active server {$uuid} not found in groups or has empty config");
                }
            }
        } else { // config
            if ($inst['lb_enabled'] && trim($inst['lb_custom']) !== '') {
                $arr = json_decode(trim($inst['lb_custom']), true);
                if (!is_array($arr) || empty($arr)) {
                    throw new \RuntimeException('lb_custom must be a JSON array of xray outbound objects');
                }
                $rawList = $arr;
                $alreadyDecoded = true;
            } else {
                $raw = trim($inst['outbound_config']);
                if ($raw === '') {
                    throw new \RuntimeException('outbound_config is empty');
                }
                $rawList[] = $raw;
            }
        }

        $proxies = [];
        $i = 0;
        foreach ($rawList as $raw) {
            $ob = $alreadyDecoded ? $raw : json_decode($raw, true);
            if (!is_array($ob)) {
                throw new \RuntimeException('outbound JSON is not valid: ' . json_last_error_msg());
            }
            $ob['tag'] = self::PROXY_PREFIX . (++$i);
            $proxies[] = $ob;
        }
        return $proxies;
    }

    /** @return array inbounds */
    private static function buildInbounds(array $inst): array
    {
        $inbounds = [];

        if ($inst['socks5_enabled']) {
            $inbounds[] = [
                'tag'      => 'socks-in',
                'listen'   => $inst['socks5_listen'],
                'port'     => $inst['socks5_port'],
                'protocol' => 'socks',
                'settings' => [
                    'auth' => 'noauth',
                    'udp'  => true,
                    'ip'   => $inst['socks5_listen'],
                ],
                'sniffing' => ['enabled' => true, 'destOverride' => ['http', 'tls']],
            ];
        }

        if ($inst['tun_driver'] === 'xray') {
            if (!preg_match('/^tun\d+$/', $inst['tun_iface'])) {
                throw new \RuntimeException(
                    'FreeBSD requires the TUN interface name to match "tunN" for the native xray TUN driver (got "'
                    . $inst['tun_iface'] . '")'
                );
            }
            $settings = [
                'name'    => $inst['tun_iface'],
                'mtu'     => $inst['mtu'],
                'gateway' => [$inst['tun_gateway']],
            ];
            if ($inst['tun_auto_routing'] === 'full') {
                $settings['autoSystemRoutingTable'] = ['0.0.0.0/0'];
                $settings['autoOutboundsInterface'] = 'auto';
                if ($inst['tun_auto_dns']) {
                    $settings['autoSystemDnsToGateway'] = true;
                }
            }
            $inbounds[] = [
                'tag'      => 'tun-in',
                'protocol' => 'tun',
                'settings' => $settings,
            ];
        }

        if ($inst['srv_enabled']) {
            $inbounds[] = self::buildVlessInbound($inst);
            if ($inst['socks5_enabled'] && $inst['socks5_port'] === $inst['srv_port']) {
                throw new \RuntimeException('SOCKS5 port and server inbound port must differ');
            }
        }

        $custom = trim($inst['srv_custom_inbounds']);
        if ($custom !== '') {
            $list = json_decode($custom, true);
            if (!is_array($list)) {
                throw new \RuntimeException('custom inbounds field is not valid JSON: ' . json_last_error_msg());
            }
            $tags = [];
            foreach ($inbounds as $ib) {
                $tags[] = $ib['tag'];
            }
            foreach ($list as $idx => $ib) {
                if (!is_array($ib) || !isset($ib['tag']) || !is_string($ib['tag']) || $ib['tag'] === '') {
                    throw new \RuntimeException("custom inbound #{$idx}: each object needs a non-empty \"tag\"");
                }
                if (in_array($ib['tag'], $tags, true)) {
                    throw new \RuntimeException('duplicate inbound tag "' . $ib['tag'] . '"');
                }
                $tags[] = $ib['tag'];
                $inbounds[] = $ib;
            }
        }

        return $inbounds;
    }

    private static function buildVlessInbound(array $inst): array
    {
        $users = json_decode($inst['srv_users'], true);
        if (!is_array($users) || empty($users)) {
            throw new \RuntimeException(
                'server inbound is enabled but srv_users is empty — must be a JSON array like '
                . '[{"id":"<uuid>","email":"user1","flow":""}]'
            );
        }
        foreach ($users as $idx => $u) {
            if (!is_array($u) || empty($u['id'])) {
                throw new \RuntimeException("srv_users[{$idx}]: missing \"id\" (client UUID)");
            }
        }

        $transport = $inst['srv_transport'];
        $stream = ['network' => $transport];
        switch ($transport) {
            case 'ws':
                $stream['wsSettings'] = ['path' => $inst['srv_path'] !== '' ? $inst['srv_path'] : '/vless'];
                break;
            case 'grpc':
                $stream['grpcSettings'] = ['serviceName' => $inst['srv_path'] !== '' ? $inst['srv_path'] : 'vless'];
                break;
            case 'h2':
                $stream['httpSettings'] = ['path' => $inst['srv_path'] !== '' ? $inst['srv_path'] : '/vless'];
                break;
            case 'tcp':
                break;
            default:
                throw new \RuntimeException("unknown server transport {$transport}");
        }

        switch ($inst['srv_security']) {
            case 'none':
                $stream['security'] = 'none';
                break;
            case 'tls':
                $cert = $inst['srv_tls_cert'];
                $key  = $inst['srv_tls_key'];
                if ($cert === '' || $key === '') {
                    throw new \RuntimeException('server TLS requires both certificate and key file paths');
                }
                $stream['security'] = 'tls';
                $stream['tlsSettings'] = [
                    'certificates' => [['certificateFile' => $cert, 'keyFile' => $key]],
                ];
                break;
            case 'reality':
                if ($transport !== 'tcp') {
                    throw new \RuntimeException('Reality server inbound requires TCP transport');
                }
                if ($inst['srv_reality_dest'] === '' || $inst['srv_reality_privatekey'] === '') {
                    throw new \RuntimeException('Reality server inbound requires dest and privateKey');
                }
                $stream['security'] = 'reality';
                $stream['realitySettings'] = [
                    'show'       => false,
                    'dest'       => $inst['srv_reality_dest'],
                    'privateKey' => $inst['srv_reality_privatekey'],
                    'shortIds'   => self::splitValues($inst['srv_reality_shortids']),
                    'serverNames'=> self::splitValues($inst['srv_reality_servernames']),
                ];
                break;
            default:
                throw new \RuntimeException('unknown server security ' . $inst['srv_security']);
        }

        if ($inst['srv_accept_proxy_protocol']) {
            $stream['sockopt'] = ['acceptProxyProtocol' => true];
        }

        return [
            'tag'      => 'vless-in',
            'listen'   => $inst['srv_listen'],
            'port'     => $inst['srv_port'],
            'protocol' => 'vless',
            'settings' => [
                'clients'    => $users,
                'decryption' => 'none',
            ],
            'streamSettings' => $stream,
            'sniffing' => ['enabled' => true, 'destOverride' => ['http', 'tls']],
        ];
    }

    private static function buildOutbounds(array $proxies, bool $needDnsOut): array
    {
        $outbounds = $proxies;
        $outbounds[] = ['tag' => 'direct', 'protocol' => 'freedom'];
        $outbounds[] = ['tag' => 'block', 'protocol' => 'blackhole'];
        if ($needDnsOut) {
            $outbounds[] = ['tag' => self::DNS_OUT_TAG, 'protocol' => 'dns'];
        }
        return $outbounds;
    }

    /**
     * @return array{0:array,1:?array} [routing, burstObservatory]
     */
    private static function buildRouting(
        array $inst,
        array $rules,
        array $inbounds,
        bool $balancer,
        ?string $defaultProxyTag
    ): array {
        $inboundTags = [];
        foreach ($inbounds as $ib) {
            $inboundTags[] = $ib['tag'];
        }

        $routing = ['domainStrategy' => 'IPIfNonMatch', 'rules' => []];

        // 1. DNS hijack: port 53 из туннеля → встроенный DNS xray
        $hijackTags = [];
        if ($inst['tun_driver'] === 'xray' && in_array('tun-in', $inboundTags, true)) {
            $hijackTags = ['tun-in'];
        } elseif (in_array('socks-in', $inboundTags, true)) {
            $hijackTags = ['socks-in'];
        }
        if ($hijackTags !== [] && $inst['dns_mode'] === 'hijack') {
            $routing['rules'][] = [
                'type' => 'field',
                'inboundTag' => $hijackTags,
                'port' => '53',
                'outboundTag' => self::DNS_OUT_TAG,
            ];
        }

        // 2. Пользовательские правила (RoutingRule), в их порядке
        foreach ($rules as $r) {
            $routing['rules'][] = array_merge(
                ['type' => 'field'],
                self::ruleCondition($r['type'], $r['value']),
                self::actionTarget($r['action'], $balancer, $defaultProxyTag)
            );
        }

        // 3. Неявное правило: локальные сети — мимо прокси
        $routing['rules'][] = [
            'type' => 'field',
            'ip' => self::PRIVATE_IPS,
            'outboundTag' => 'direct',
        ];

        // 4. Дефолт для входящих клиентов серверного inbound — ПОСЛЕ
        //    пользовательских правил (per-domain override работает)
        if (in_array('vless-in', $inboundTags, true)) {
            $routing['rules'][] = array_merge(
                ['type' => 'field'],
                ['inboundTag' => ['vless-in']],
                self::actionTarget($inst['srv_default_outbound'], $balancer, $defaultProxyTag)
            );
        }

        // 5. Catch-all через балансировщик (иначе default = первый outbound)
        if ($balancer) {
            $routing['balancers'] = [[
                'tag'      => self::BALANCER_TAG,
                'selector' => [self::PROXY_PREFIX],
                'strategy' => ['type' => $inst['lb_strategy']],
                // при пустом выборе стратегии (все узлы мертвы / старт):
                // уходим на первый узел — connect fail вместо утечки в direct
                'fallbackTag' => $defaultProxyTag ?? 'block',
            ]];
            $routing['rules'][] = [
                'type' => 'field',
                'network' => 'tcp,udp',
                'balancerTag' => self::BALANCER_TAG,
            ];
        }

        $observatory = null;
        if ($balancer) {
            $observatory = [
                'subjectSelector' => [self::PROXY_PREFIX],
                'pingConfig' => [
                    'destination'  => $inst['lb_probe_url'],
                    'connectivity' => $inst['lb_probe_url'],
                    'interval'     => $inst['lb_probe_interval'],
                    'sampling'     => 2,
                    'timeout'      => '5s',
                ],
            ];
        }

        return [$routing, $observatory];
    }

    private static function buildDns(array $inst): array
    {
        return [
            'servers' => [$inst['dns_upstream'] !== '' ? $inst['dns_upstream'] : '1.1.1.1'],
            'queryStrategy' => 'UseIPv4',
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  Маппинг правил
     * ═══════════════════════════════════════════════════════════════════════ */

    /** Условие правила RoutingRule → поля xray routing rule. */
    private static function ruleCondition(string $type, string $value): array
    {
        $values = self::splitValues($value);
        if (empty($values)) {
            throw new \RuntimeException("routing rule of type {$type} has an empty value");
        }
        switch ($type) {
            case 'domain':
                return ['domain' => $values];
            case 'ip':
                return ['ip' => $values];
            case 'geoip':
                return ['ip' => self::withPrefix($values, 'geoip:')];
            case 'geosite':
                return ['domain' => self::withPrefix($values, 'geosite:')];
            case 'port':
                return ['port' => implode(',', $values)];
            case 'source_port':
                return ['sourcePort' => implode(',', $values)];
            case 'network':
                return ['network' => implode(',', $values)];
            case 'inbound_tag':
                return ['inboundTag' => $values];
            default:
                throw new \RuntimeException("unknown routing rule type {$type}");
        }
    }

    /** Действие правила → outboundTag / balancerTag. */
    private static function actionTarget(string $action, bool $balancer, ?string $defaultProxyTag): array
    {
        switch ($action) {
            case 'direct':
                return ['outboundTag' => 'direct'];
            case 'block':
                return ['outboundTag' => 'block'];
            case 'proxy':
                if ($balancer) {
                    return ['balancerTag' => self::BALANCER_TAG];
                }
                if ($defaultProxyTag !== null) {
                    return ['outboundTag' => $defaultProxyTag];
                }
                throw new \RuntimeException(
                    'no upstream configured (node source is "none") but a rule uses action "proxy"'
                );
            case 'balancer':
                if ($balancer) {
                    return ['balancerTag' => self::BALANCER_TAG];
                }
                throw new \RuntimeException(
                    'action "balancer" requires Load Balancing enabled with at least one node'
                );
            default:
                throw new \RuntimeException("unknown routing action {$action}");
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  Утилиты
     * ═══════════════════════════════════════════════════════════════════════ */

    /** Список значений через запятую/пробел → уникальные непустые строки. */
    private static function splitValues(string $value): array
    {
        $parts = preg_split('/[\s,]+/', trim($value));
        if ($parts === false) {
            return [];
        }
        $result = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $result[$p] = true;
            }
        }
        return array_keys($result);
    }

    /** geoip/geosite значения: добавляет префикс, если его нет. */
    private static function withPrefix(array $values, string $prefix): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = (strpos($v, $prefix) === 0) ? $v : $prefix . $v;
        }
        return $out;
    }
}
