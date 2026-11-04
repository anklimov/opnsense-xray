[English](README.md) | **Русский**

# os-xray

[![Release](https://img.shields.io/github/v/release/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/releases)
[![License](https://img.shields.io/github/license/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/blob/main/LICENSE)
[![Downloads](https://img.shields.io/github/downloads/MrTheory/os-xray/total)](https://github.com/MrTheory/os-xray/releases)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v4.0.0 (форк [MrTheory/os-xray](https://github.com/MrTheory/os-xray), см. [отличия](#форк-чем-отличается-от-апстрима))

Нативный Xray-core для OPNsense: **встроенный TUN inbound** перехватывает трафик (tun2socks больше не нужен), **маршрутизация движка xray** решает, кто куда идёт (правила по доменам/IP/портам/GeoIP/GeoSite), **balancer + observatory** (leastPing / roundRobin / leastLoad / random) выбирают живой узел выхода, а **серверный VLESS-inbound** (за nginx, если хочется) превращает роутер в точку входа. Полный `config.json` можно редактировать как Raw JSON с подсветкой синтаксиса и валидацией `xray -test`. VLESS+Reality через визард или кастомный конфиг. Обходит DPI-блокировки за счёт маскировки трафика под легитимный TLS.

---

## Форк: чем отличается от апстрима

Это форк [MrTheory/os-xray](https://github.com/MrTheory/os-xray) (ветка `develop`). Функциональность апстрима сохранена; форк развивался двумя большими шагами.

**v4.0.0 — нативные механизмы Xray (без внешних демонов).** Весь конфиг собирается одним генератором и управляет возможностями xray-core напрямую:

- **Нативный TUN inbound** (`"protocol": "tun"`) — интерфейс создаёт сам xray-core (схема сверена с `proxy/tun/tun_freebsd.go` в Xray-core 26.10+); tun2socks остаётся per-instance legacy-фолбэком (`tun_driver`). Дефолт нового инстанса: интерфейс `tun100`, шлюз `10.255.0.1/30`.
- **Маршрутизация движка xray** — новая модель `OPNsense\Xray\RoutingRule` (`//OPNsense/xray/routing_rules`), вкладка **Routing** и API `api/xray/rule/*`: правила по domain / ip / port / sourcePort / network / inboundTag / **geoip** / **geosite** → proxy / balancer / direct / block. `install.sh` скачивает `geoip.dat`/`geosite.dat`; старый `bypass_networks` мигрирует в правило.
- **Балансировщик + observatory** — `lb_enabled` со стратегиями leastPing / roundRobin / leastLoad / random (все подтверждены по `app/router/config.go`), члены — все серверы выбранной группы либо свой список outbound'ов; билдер генерирует `routing.balancers` + `burstObservatory` + catch-all правило; `fallbackTag` = первый узел — мёртвый узел рвёт соединение, а не сливает трафик напрямую.
- **Серверный inbound** — VLESS/VMess/Trojan/Shadowsocks (включая SS-2022), транспорты WebSocket/XHTTP/gRPC/H2/HTTPUpgrade/TCP, security none (TLS на nginx)/TLS/REALITY; генерация креденшалов по протоколу и **QR + ссылка импорта** на каждого клиента; генератор nginx-сниппетов (вкладка выбирается по транспорту)
- **Режим Raw JSON** — `node_source` расширен до `config | group | none | raw`: полный `config.json` в редакторе CodeMirror с кнопками **Validate Config** (черновик, через `xray -test`), **Format** и **Preview Generated Config**.
- **ConfigBuilder** (`mvc/app/library/OPNsense/Xray/ConfigBuilder.php`) — единственный источник генерации конфига для start/reconfigure/validate/preview; сборка конфига больше не дублируется в сервисном скрипте. Строгие ошибки конфигурации (конфликт портов, имя `tunN`, action=balancer без LB и т.п.).
- **Опция полного туннеля** — `tun_auto_routing = full`: xray сам управляет системными маршрутами (`autoSystemRoutingTable`, escape FIB через `autoOutboundsInterface`, опционально `autoSystemDnsToGateway`); требует `net.fibs=2`, значение показывается в Diagnostics.
- **DNS hijack** — `dns_mode = hijack`: DNS (порт 53) из туннеля отвечает встроенный DNS xray (outbound `dns-out` + правило маршрутизации генерируются автоматически). Важно: в Xray-core **нет FakeIP** (проверено по `infra/conf/dns.go`) — домены TUN-клиентов извлекает sniffing.

**v3.2 — группы серверов с импортом подписки.** Инстанс может указывать на **группу** и один **активный сервер** в ней; смена точки выхода — выбор в дропдауне плюс Apply. Группа из URL подписки получает кнопку **Refresh**. Парсер `vless://` живёт в общем классе `OPNsense\Xray\VlessLink`.

**Исправлено попутно** (избранное): дубль инстанса при установке поверх рабочей 3.x; свой форматтер кнопок игнорируется на OPNsense 26.7, если назван не `commands`; `requestHandler` читается только из `options`; логи xray-core/tun2socks пропадали после первой ротации (теперь демоны под `daemon -H -o` с SIGHUP-reopen); tun2socks 2.7 требует `--config`.

**Совместимость.** Существующие конфигурации работают без правок: мигрированные инстансы сохраняют драйвер `tun2socks` и имя интерфейса, пустой `node_source` с выбранным сервером по-прежнему означает `group`, а `outbound_config` остаётся полноправным способом задать узел. Переход инстанса на нативный TUN — осознанное действие в диалоге (интерфейс нужно переименовать в `tunN`). Возврат на апстрим: из `config.xml` руками придётся убрать `//OPNsense/xray/groups`, `//OPNsense/xray/routing_rules` и новые поля инстанса.


## Возможности

- **Нативный TUN inbound** — TUN-интерфейс создаёт сам xray-core (`"protocol": "tun"`), внешние демоны не нужны; tun2socks остаётся per-instance legacy-фолбэком
- **Маршрутизация движка xray** — per-instance правила по доменам / IP / портам / sourcePort / network / inboundTag / **GeoIP** / **GeoSite** (dat-файлы ставятся автоматически); кнопка **Preview Generated Config** показывает результат
- **Балансировка и health-checks** — balancer со стратегиями leastPing / roundRobin / leastLoad / random и фоновым `burstObservatory`, пробирующим каждый узел; члены — все серверы группы либо свой список outbound'ов
- **Серверный inbound** — приём входящих прокси-соединений: VLESS/VMess/Trojan/SS поверх WebSocket/XHTTP/gRPC/H2/HTTPUpgrade/TCP с none/TLS/REALITY; QR + ссылки на клиента; дополнительные inbound'ы через JSON-список
- **Режим Raw JSON** — полный `config.json` в редакторе CodeMirror с подсветкой синтаксиса, кнопками **Validate Config** (черновик, через `xray -test`) и **Format**; в wizard-режиме всё генерируется из полей GUI
- **Переключатель Config Source** — group / свой JSON / только сервер / raw; в диалоге показаны только поля выбранного режима
- **Группы серверов и импорт подписки** — список `vless://` ссылок импортируется как группа, активный узел выбирается в дропдауне, кнопка **Refresh** перескачивает список по требованию
- **Полный туннель или селективно** — xray может сам управлять системными маршрутами (`autoSystemRoutingTable`, нужен `net.fibs=2`) или оставить выбор правилам OPNsense и шлюзу через TUN
- **DNS hijack** — DNS из туннеля отвечает встроенный DNS xray (без утечек в полном туннеле); опционально `autoSystemDnsToGateway` для резолвера самого роутера
- **Мульти-инстанс** — несколько VPN-подключений одновременно, каждое с отдельным конфигом, портом и TUN-интерфейсом
- **Per-instance статус** — таблица инстансов показывает статус xray/tun (обновление каждые 5 секунд)
- **Импорт VLESS-ссылки** прямо в диалоге — кнопка Import внутри окна создания/редактирования инстанса
- Полная поддержка параметров VLESS+Reality (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Управление туннелем через GUI: **VPN → Xray**
- При установке автоматически определяет и импортирует существующий конфиг xray-core и tun2socks
- **Кнопки Start / Stop / Restart** — управление сервисом прямо из GUI без перезагрузки страницы
- **Кнопка Validate Config** — сухой прогон конфига через `xray -test` без остановки сервиса
- **Кнопка Test Connection** — проверяет, что xray-core реально проксирует трафик
- **Вкладка Log** — Boot Log и Xray Core Log прямо в GUI
- **Вкладка Diagnostics** — статистика TUN: IP, MTU, байты, пакеты, uptime, Ping RTT; плюс окружение: версия xray, geo-данные, `net.fibs`, слушающие порты
- **Кнопка Copy Debug Info** — собирает diagnostics + логи в модалку для копирования в issue-репорт
- **Watchdog** — автоматический перезапуск при падении xray-core (и legacy tun2socks)
- **Автозапуск после ребута** — интерфейс поднимается автоматически, нажимать Apply вручную не нужно
- ACL-права — доступ к GUI и API только для авторизованных пользователей с ролью `page-vpn-xray`

---

## Стек

```
                    ┌────────────────────────── xray-core (один процесс) ──────────────────────────┐
                    │ inbounds: tun-in (protocol "tun") · socks-in · vless-in (сервер, опционально) │
                    │ routing:  правила (domain/ip/port/geoip/geosite) → balancer lb / direct / block│
                    │ outbounds: proxy-1..N (серверы групп) · balancer+observatory · freedom · dns    │
                    └────────────────────────────────────────────────────────────────────────────────┘
                                           ↓ TUN-интерфейс (tun100, на FreeBSD — tunN)
OPNsense Gateway PROXYTUN_GW (селективно)  ·  или системные маршруты xray (полный туннель)
                                           ↓
Firewall Rules (селективная маршрутизация)
```

Legacy-фолбэк per instance: `tun2socks → TUN → SOCKS5 → xray-core` (поведение v3.x).

---

## Системные требования

| Компонент  | Версия                  |
|------------|-------------------------|
| OPNsense   | 25.x / 26.x             |
| FreeBSD    | 14.x amd64              |
| xray-core  | **26.10+** (нативный TUN; установщик обновляет автоматически) |
| tun2socks  | Опционален — только legacy TUN-драйвер |
| net.fibs   | ≥ 2 — только для режима полного туннеля (`/boot/loader.conf`, ребут) |

---

## Установка

**Вариант 1 — через git clone (рекомендуется)**

```sh
cd /tmp
git clone https://github.com/MrTheory/os-xray.git
cd os-xray
sh install.sh
```

**Вариант 2 — через архив**

```sh
fetch -o /tmp/os-xray-v5.tar https://raw.githubusercontent.com/MrTheory/os-xray/refs/heads/main/os-xray-v5.tar
cd /tmp && tar xf os-xray-v5.tar && cd os-xray-v5
sh install.sh
```

Установщик автоматически:

- Покажет текущую и новую версию плагина и запросит подтверждение
- Проверит версию xray-core — если ниже **26.10** (нативный TUN inbound на FreeBSD), предложит автоматическое обновление до последнего релиза
- Скачает **geoip.dat** и **geosite.dat** в `/usr/local/etc/xray-core/` (для правил GeoIP/GeoSite)
- Установит web-ассеты (редактор CodeMirror) в `/usr/local/opnsense/www/js/xray/`
- Мигрирует инстансы v3.x: legacy `bypass_networks` станет правилом маршрутизации, TUN-драйвер сохранится как `tun2socks`
- Проверит, не занят ли SOCKS5-порт (10808 по умолчанию) другим процессом
- Найдёт существующие конфиги и импортирует их в OPNsense (поля в GUI заполнятся сразу)
- Скопирует все файлы плагина, перезапустит configd, очистит кеши
- Установит boot-скрипт для автозапуска после ребута

Проверить установленную версию:
```sh
configctl xray version
```

---

## Настройка в GUI

Обнови браузер (`Ctrl+F5`) → **VPN → Xray**

1. Вкладка **Instances** → кнопка **+** (добавить инстанс)
   - **Config Source** — откуда берётся конфиг:
     - *Xray config (JSON)* — нажми **Import VLESS link** (сворачиваемая панель вверху) → вставь ссылку → **Parse & Fill** (или вставь outbound JSON руками); бэкенд обернёт его секциями TUN/SOCKS/маршрутизации
     - *Server group* — выбери группу и активный сервер (или включи Load Balancing, чтобы использовать все)
     - *Server only* — без upstream: чистый входящий прокси (см. Серверный inbound ниже)
     - *Custom full config (Raw JSON)* — весь `config.json` в редакторе с подсветкой; **Validate Config** проверяет черновик через `xray -test`, **Format** форматирует
   - **TUN Driver** — `Xray TUN inbound (native)` (дефолт для новых инстансов; на FreeBSD имя должно быть `tunN`), `tun2socks (legacy)` или `Off (no TUN)` — без интерфейса и захвата трафика: инстанс только обслуживает свои inbound'ы (SOCKS5 / серверный inbound для входящих клиентов / кастомные), например чистая точка входа. Хотя бы один inbound должен остаться включённым
   - **Preview Generated Config** (footer, wizard-режимы) — показывает, что именно будет записано в `/usr/local/etc/xray-core/`
2. Вкладка **Routing** → добавь правила (per instance): условия domain / ip / port / sourcePort / network / inboundTag / **geoip** / **geosite** → действия proxy / balancer / direct / block
3. Вкладка **General** → установи галку **Enable Xray** (и **Enable Watchdog** по желанию)
4. Нажми **Apply**
5. Кнопка **Test Connection** — убедись, что туннель работает (показывает HTTP 200)

---

## Интерфейс и шлюз (селективная маршрутизация)

С нативным TUN-драйвером интерфейс создаёт сам xray и назначает адрес **сразу после шлюза** (`10.255.0.1/30` → интерфейс получает `10.255.0.2/30`); сам шлюз никому не принадлежит и пакеты на него уходят в TUN.

| Шаг | Путь в GUI | Значение |
|-----|-----------|----------|
| Назначить интерфейс | Interfaces → Assignments | + Add: **tun100** (на FreeBSD обязано быть `tunN`) |
| Включить и настроить | Interfaces → \<имя\> | Enable ✓, IPv4: Static, IP: `10.255.0.2/30` (= шлюз + 1) |
| **Предотвратить удаление** | Interfaces → \<имя\> | **Prevent interface removal ✓** |
| Создать шлюз | System → Gateways → Add | Gateway IP: `10.255.0.1`, Far Gateway ✓, Monitoring off ✓ |

> **Важно:** галочка **Prevent interface removal** обязательна — без неё OPNsense может удалить интерфейс из конфига при ребуте, если xray ещё не успел его создать. Статический IP должен совпадать с тем, что назначает xray (шлюз + 1 с той же маской), иначе OPNsense переопределит адрес и сломает маршрутизацию.

> **Режим полного туннеля:** при `System Routing = Full tunnel` всё это не нужно — xray сам ставит системные маршруты. Требуется `net.fibs=2` в `/boot/loader.conf` и ребут (собственные соединения xray уходят мимо TUN через вторую таблицу маршрутизации). Значение видно на вкладке **Diagnostics**.

---

## Селективная маршрутизация (уровень OPNsense)

- **Firewall → Aliases** — создай список IP/сетей/доменов для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

MSS Clamping для Xray не требуется (в отличие от WireGuard).

---

## Правила маршрутизации (движок xray)

Вкладка **Routing** хранит правила, компилируемые в `routing.rules` сгенерированного конфига и применяемые **сверху вниз** — тащи за ручку ⋮⋮, чтобы менять порядок (порядок в grid = порядок в конфиге; после drop нажми Apply, чтобы пересобрать работающие конфиги). Пустой *Instance* = **общее правило**, действующее во всех инстансах — фильтр по инстансу показывает его правила и общие. Правило DNS-hijack добавляется автоматически первым, а private-сети → direct — после пользовательских, чтобы локальный трафик никогда не заворачивался в прокси:

| Условие | Примеры значений | Примечания |
|---------|------------------|------------|
| domain | `google.com, full:example.org, keyword:cdn, regexp:.*\.foo\..*` | доменные шаблоны v2ray |
| ip | `203.0.113.0/24, 10.8.0.1` | CIDR / хосты |
| port | `80, 443, 1000-2000` | порт назначения |
| sourcePort | `60000-61000` | порт источника |
| network | `tcp`, `udp`, `tcp,udp` | |
| inboundTag | `tun-in`, `socks-in`, `vless-in` | например, по-разному маршрутизировать входящих клиентов |
| geoip | `private, cn, ru` | префикс `geoip:` добавится автоматически; geoip.dat |
| geosite | `category-ads-all, google` | префикс `geosite:` добавится автоматически; geosite.dat |
| protocol | `quic, bittorrent, http, tls` | матчинг по протоколу из sniffing |

Действия: `proxy` (upstream или balancer), `balancer`, `direct` (freedom), `block` (blackhole).

**Переупорядочивание (v4.3.0)** — ручка в первой колонке двигает правило в любое место списка; новый порядок сохраняется сразу (splice-семантика: видимый блок встаёт на место своего самого раннего правила, скрытые правила сохраняют относительный порядок). Работает и при активном фильтре по инстансу.

**Общие правила (v4.3.0)** — правило с пустым *Instance* применяется во **всех** инстансах (в grid — `(all)`); инстансовые и общие правила чередуются в одном общем порядке, так что ты сам решаешь, сработает ли общий блок до или после инстансового правила.

**AND-условия (v4.2.0)** — у каждого правила могут быть дополнительные *Network* (tcp / udp / tcp,udp) и *Source CIDR* (список через запятую); они встраиваются в ТО ЖЕ правило xray. Например, `geosite: category-ru` + `network: tcp,udp` + `direct` компилируется один-в-один в рукописное `{"domain":["geosite:category-ru"],"network":"tcp,udp","outboundTag":"direct"}`.

**Routing Domain Strategy (v4.2.0)** — per-instance в диалоге (секция Routing & Load Balancing): `AsIs`, `IPIfNonMatch` (дефолт, как раньше), `IPOnDemand`.

Пример: блокировать рекламу везде, а в корпоративные подсети ходить напрямую:

| Условие | Значение | Действие |
|---------|----------|----------|
| geosite | category-ads-all | block |
| geoip | private | direct |

### Пошаговый разбор — рукописный конфиг через UI

Вот этот raw-JSON (реальный рабочий сетап: два upstream-узла VLESS, балансировщик leastLoad, сплит по РФ, блок рекламы и quic):

```json
"observatory": {
    "subjectSelector": ["out_tunnel_vortex-vless-nginx", "out_tunnel_marvel-vless-nginx"],
    "probeUrl": "https://connectivitycheck.gstatic.com/generate_204",
    "probeInterval": "30s",
    "enableConcurrency": true
},
"routing": {
    "domainStrategy": "IPIfNonMatch",
    "balancers": [{ "tag": "out_balancer", "selector": ["out_tunnel_..."],
                    "fallbackTag": "out_tunnel_...", "strategy": { "type": "leastLoad" } }],
    "rules": [
        { "outboundTag": "block", "protocol": ["quick"] },
        { "outboundTag": "block", "domain": ["geosite:category-ads-all"] },
        { "remarks": "Direct domains", "domain": ["geosite:category-ru"], "network": "tcp,udp", "outboundTag": "out_direct" },
        { "remarks": "Direct IPs", "ip": ["geoip:ru"], "network": "tcp,udp", "outboundTag": "out_direct" },
        { "remarks": "Balanced out via tunnel", "network": "tcp,udp", "balancerTag": "out_balancer" }
    ]
}
```

собирается через UI так:

| Кусок JSON | Где в UI |
|------------|----------|
| два upstream-узла | вкладка Groups: создать группу, добавить оба сервера (или вставить каждую ссылку кнопкой **Import** в диалоге инстанса) |
| observatory: probeUrl / probeInterval | диалог инстанса → Routing & Load Balancing: **Enable Balancer** ✓, стратегия `leastLoad`, Probe URL / Probe Interval. `subjectSelector`/`enableConcurrency` генерируются автоматически (все outbound'ы группы; burstObservatory проверяет конкурентно) |
| balancers: selector / fallbackTag | генерируются автоматически: selector = все outbound'ы группы, fallbackTag = первый узел (или block при полном отказе) |
| domainStrategy | диалог инстанса → Routing & Load Balancing → **Routing Domain Strategy** |
| правило `{"protocol":["quick"],"outboundTag":"block"}` | Routing → + → Match **protocol**, Value `quic`, Action **block** |
| блок рекламы | Match **geosite**, Value `category-ads-all`, Action **block** |
| «Direct domains» | Match **geosite**, Value `category-ru`, **Network (AND)** `tcp,udp`, Action **direct** |
| «Direct IPs» | Match **geoip**, Value `ru`, **Network (AND)** `tcp,udp`, Action **direct** |
| «Balanced out via tunnel» | Match **network**, Value `tcp,udp`, Action **balancer** (при включённом LB такой catch-all также генерируется автоматически) |
| TUN-inbound | диалог инстанса → TUN Driver `Xray TUN inbound (native)`, интерфейс `tun5` (или `xraytun0`, чтобы назначить его в OPNsense), gateway, MTU |

Проверь результат кнопкой **Preview Generated Config** — он совпадёт с рукописным JSON (плюс автоматические правила DNS-hijack и private-networks → direct).

---

## Балансировка нагрузки

Включи **Load Balancing** в диалоге инстанса (Config Source = Server group использует **все серверы группы**; при своём JSON — поле *Custom Outbound List*). Сгенерированный конфиг получает:

- `outbounds`: `proxy-1..N` (по одному на сервер) + balancer `lb` с выбранной стратегией — **leastPing**, **roundRobin**, **leastLoad** или **random**
- `burstObservatory`: фоновые health-checks каждого члена (`probeURL`, `probeInterval`)
- catch-all правило, отправляющее весь остальной трафик через балансировщик; `fallbackTag` = первый узел (мёртвый узел рвёт соединение вместо утечки трафика напрямую)

Узел, исчезнувший из подписки, остаётся в группе с отметкой `stale`; балансировщик исключит его, когда пробы начнут падать.

---

## Режим Raw JSON

**Config Source = Custom full config (Raw JSON)** — твой `config.json` используется дословно:

- Редактор CodeMirror с подсветкой синтаксиса JSON
- **Комментарии разрешены** — в стиле xray: `//`, `#` и блочные (плагин вырезает их так же, как собственный ридер xray, поэтому конфиг, который принимает `xray -test`, импортируется как есть)
- **Validate Config** — черновик проверяется через `xray -test` на роутере (именно текст из редактора, а не сохранённый конфиг!); правила GeoIP/GeoSite проверяются по dat-файлам, которые ставит `install.sh`
- **Format** — pretty-print
- **Противоречивых GUI-полей нет**: в этом режиме секции TUN/SOCKS/DNS/LB/Server Inbound диалога скрыты, а runtime-параметры выводятся из самого JSON — имя TUN-интерфейса и MTU из inbound'а `tun`, адрес/порт SOCKS (кнопка Test, legacy-драйвер tun2socks) из inbound'а `socks`/`mixed`
- **Validate Config** также предупреждает о ловушках, которые `xray -test` не ловит (он не стартует inbound'ы): `listen`-адрес, не назначенный ни одному интерфейсу роутера (xray умрёт на старте с «bind: can't assign requested address» — типично для конфигов, скопированных с другой машины), или имя tun-интерфейса не `tunN` (macOS-стиль `utunN`)
- Нюансы: при legacy-драйвере tun2socks конфиг обязан содержать SOCKS/mixed-inbound; правила маршрутизации / секции балансировщика на raw-режим не действуют (это возможности wizard'а). На FreeBSD нативному `tun`-inbound нужен `"name": "tunN"` (utunN — только macOS) и актуальная схема `gateway` — старое поле `address` игнорируется

---

## Серверный inbound (входящий прокси за nginx)

Движок может и принимать соединения — роутер становится точкой входа прокси. Включи **Server Inbound** в диалоге инстанса:

1. **Protocol** — `VLESS` (рекомендуется: flow/REALITY), `VMess`, `Trojan` или `Shadowsocks` (включая 2022-blake3 с серверным ключом)
2. **Users** — кнопка **Add user** генерирует подходящий креденшал под выбранный протокол (UUID для VLESS/VMess, случайный пароль для Trojan/Shadowsocks, base64-ключ для SS-2022). У каждого клиента — кнопка **QR**: модалка с ссылкой импорта (`vless://`, `vmess://`, `trojan://`, `ss://`) и QR-кодом для сканирования в v2rayNG/Nekoray/Streisand; строится из текущего состояния диалога, сохранять не надо
3. **Transport** — `WebSocket`, `XHTTP`, `gRPC`, `HTTP/2`, `HTTPUpgrade` (все за nginx) или `TCP` (напрямую); задай path/service name и Host header
4. **Security** — `None` (TLS терминирует nginx), `TLS` (свои файлы сертификатов) или `Reality` (напрямую; TCP/gRPC/XHTTP)
5. **Public Host / Public Port (links/QR)** — куда подключаются клиенты (домен + 443 за nginx; WAN-IP + Listen Port напрямую). Используются генератором QR/ссылок
6. Кнопка **nginx snippet** — готовый конфиг под текущие настройки (вкладки WS / XHTTP / gRPC / stream, активная выбирается по транспорту) для вставки в плагин nginx (Services → Nginx) или custom include
7. Открой публичный порт в **Firewall → Rules** (WAN) при прямом exposure
8. **Incoming Traffic Default** — куда идёт трафик клиентов без совпадения правил: `direct`, `proxy` (цепочка через upstream) или `balancer`; per-domain — правилом по `inboundTag: vless-in` (тег исторический, используется для всех протоколов)

Пример ссылки клиента (VLESS за nginx, TLS на 443):
`vless://<uuid>@<твой-хост>:443?security=tls&sni=<твой-хост>&fp=chrome&type=xhttp&path=/vless#my-opnsense`

Генератор QR выводит публичный ключ Reality из приватного на стороне сервера (libsodium x25519) — ссылки Reality получают готовый `pbk=`.

---

## Outbound NAT (обязательно!)

Без этого трафик через туннель не будет NATиться и не уйдёт дальше VPN-сервера.

**Firewall → NAT → Outbound**

1. Переключи режим на **Hybrid outbound NAT rule generation** (если ещё не переключён)
2. Добавь правило **+**:

| Поле | Значение |
|------|----------|
| Interface | PROXYTUN (tun100 / имя твоего TUN) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

> **Почему это нужно:** OPNsense по умолчанию NATит трафик только через WAN. Трафик, уходящий через TUN-интерфейс, не попадает под автоматические правила NAT. Без ручного правила пакеты уйдут с исходным LAN-адресом (например 192.168.1.x) и VPN-сервер их отбросит.

---

## Автозапуск после ребута

После ребута TUN-интерфейс поднимается автоматически — xray стартует (создавая интерфейс нативным драйвером, или вместе с tun2socks на legacy-драйвере), интерфейс получает IP, firewall rules перезагружаются. Вручную нажимать Apply не нужно.

Работает через два механизма с защитой от двойного запуска (flock):
- **`xray_configure_do()`** — boot hook (приоритет 10), делегирует `xray-service-control.php start` (сборка конфига + `xray -test` + драйвер-зависимый запуск)
- **`/usr/local/etc/rc.syshook.d/start/50-xray`** — финальный скрипт, ждёт интерфейс и применяет routing/firewall когда OPNsense полностью загружен

Лог сохраняется в `/tmp/xray_syshook.log` (дозапись, ротация при превышении 50 KB).

---

## Watchdog

При включённом **Enable Watchdog** cron каждую минуту проверяет живость xray-core (и tun2socks у legacy-инстансов). При падении — инстанс перезапускается автоматически. События пишутся в `/var/log/xray-watchdog.log` (ротация: 3 файла по 100 KB).

Watchdog не перезапускает сервис если он был остановлен вручную через кнопку **Stop** или **Apply** с отключённым Enable.

---

## Остановка сервиса

При остановке (`Stop` в GUI или `Apply` с отключённым Enable) плагин:
1. Останавливает tun2socks (legacy-драйвер) — он сам уничтожает TUN-интерфейс при завершении
2. Останавливает xray-core — нативный TUN-драйвер уничтожает интерфейс при shutdown (плагин принудительно добивает его, если процесс убит нечисто)
3. Выставляет флаг намеренной остановки — watchdog не будет перезапускать сервис

---

## Удаление

```sh
cd /tmp/os-xray-v5
sh install.sh uninstall
```

---

## Устранение неполадок

### Пошаговая диагностика

Выполняй команды по порядку. Каждый шаг сужает проблему. `<uuid>` — UUID инстанса (см. `configctl xray statusall` или вкладку Diagnostics).

```sh
# Шаг 1 — Плагин и бинарники
configctl xray version
ls -la /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks   # tun2socks опционален (legacy)
/usr/local/bin/xray-core version          # версия xray-core (26.10+ для нативного TUN)

# Шаг 2 — Статус сервисов
configctl xray statusall                   # JSON по инстансам: xray_core + tun (или tun2socks) + driver
ps aux | grep -E 'xray|tun2socks'         # реальные процессы

# Шаг 3 — Валидация / предпросмотр конфига
configctl xray validate <uuid>             # сухой прогон без перезапуска
configctl xray generate <uuid>             # печать сгенерированного config.json
# или напрямую:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json

# Шаг 4 — Сеть
ifconfig tun100                            # TUN-интерфейс (драйвер xray): UP + inet 10.255.0.2?
netstat -rn | grep tun                    # записи в таблице маршрутизации?
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 10
# 200 = туннель работает, 000 = нет связи

# Шаг 5 — Файрвол (селективный режим)
pfctl -sr | grep tun                      # правила файрвола для TUN
pfctl -sn | grep tun                      # NAT-правила для TUN

# Шаг 6 — Логи (последние ошибки)
tail -30 /var/log/xray-core-<uuid>.log
cat /tmp/xray_syshook.log
tail -20 /var/log/xray-watchdog.log

# Шаг 7 — Конфиги и geo-данные
ls -la /usr/local/etc/xray-core/           # config-<uuid>.json, geoip.dat, geosite.dat
cat /usr/local/tun2socks/config-<uuid>.yaml # только legacy-драйвер
```

---

### Справочник ошибок — все сообщения плагина

#### Ошибки запуска/остановки

| Сообщение | Причина | Решение |
|---|---|---|
| `xray-core not found at /usr/local/bin/xray-core` | Бинарник не установлен | Установить xray-core (см. раздел ниже) |
| `tun2socks not found … switch the instance TUN driver to "Xray TUN inbound (native)"` | Выбран legacy-драйвер, но tun2socks нет | Установить tun2socks **или** переключить TUN-драйвер инстанса на нативный |
| `Xray is disabled or instance not found` | Не включён Enable | GUI → General → Enable Xray ✓ → Apply |
| `Another start is already in progress (lock held)` | Параллельный запуск, per-instance lock занят | Подождать 30с. Если не проходит: `rm -f /var/run/xray_start_<uuid>.lock` |
| `ERROR: Failed to start Xray services` | xray-core (или legacy tun2socks) упал при старте | Смотри `/var/log/xray-core-<uuid>.log` |
| `No instances configured` | Не создано ни одного инстанса | Вкладка Instances → **+** → заполнить поля → Apply |

#### Ошибки валидации конфига (ConfigBuilder)

| Сообщение | Причина | Решение |
|---|---|---|
| `ERROR: config validation failed` + вывод xray | Невалидный конфиг xray-core | Смотри вывод xray ниже ошибки |
| `full config is empty` (raw-режим) | Raw JSON с пустым редактором | Вставить config.json, нажать Validate Config |
| `full config is not valid JSON` | Некорректный JSON в raw-редакторе | Нажать **Format** или проверить запятые/скобки/кавычки |
| `outbound_config is empty` | Config Source = JSON без outbound | Импортировать VLESS-ссылку или вставить outbound JSON |
| `the selected server group has no servers` / `no server is selected` | Ненастроен режим группы | Выбрать группу с серверами (или активный сервер) |
| `FreeBSD requires the TUN interface name to match "tunN"` | xray-драйвер с именем не `tunN` | Переименовать интерфейс, например `tun100` |
| `SOCKS5 port and server inbound port must differ` | Конфликт портов | Сменить один из портов |
| `action "balancer" requires Load Balancing enabled…` | Правило использует balancer без LB | Включить Load Balancing или сменить действие правила |
| `no upstream configured … but a rule uses action "proxy"` | Инстанс без upstream с proxy-правилом | Сменить действие на direct/block или задать Config Source с узлом |
| `Cannot create temp file for validation` | /tmp заполнен или проблема прав | `df /tmp` — проверить место на диске |

#### Ошибки валидации xray-core (из `xray -test`)

| Вывод xray-core | Причина | Решение |
|---|---|---|
| `invalid user id` | Некорректный UUID | Проверить формат: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |
| `unknown transport protocol: xhttp` | Очень старый билд xray-core | Обновить xray-core до 26.10+ (v4.0.0 больше не нормализует xhttp→splithttp) |
| `REALITY only supports TCP, H2, gRPC and DomainSocket` | Неподдерживаемая комбинация транспорт+security | Сменить транспорт/security (Reality в визарде серверного inbound требует TCP) |
| `failed to dial` | VPN-сервер недоступен | Проверить адрес и порт: `ping` или `nc -zv host port` |
| `address already in use` | Занят порт SOCKS5/серверный | `sockstat -4l \| grep 10808` — сменить порт в GUI или остановить конфликтующий процесс |
| `interface already exists` | Имя TUN занято (драйвер xray) | Выбрать другой свободный `tunN` для инстанса |
| `automatic outbound interface on FreeBSD needs a second routing table…` | Полный туннель без `net.fibs=2` | Добавить `net.fibs=2` в `/boot/loader.conf` и перезагрузиться |

#### Сетевые предупреждения

| Предупреждение | Причина | Влияние |
|---|---|---|
| `WARNING: Cannot read lo0 interface` | Проблема интерфейса lo0 (очень редко) | SOCKS5 на 127.0.0.1 может не работать |
| `WARNING: Failed to add lo0 alias` | Alias уже существует или проблема прав | Обычно безвредно — alias уже настроен |

---

### Меню VPN → Xray не появляется

```sh
# Очистить кеш меню OPNsense
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5 в браузере

# Если не помогло — проверить что файлы плагина на месте:
ls /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu/Menu.xml
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/IndexController.php
```

---

### Туннель не поднимается

```sh
# Запустить вручную для полного вывода:
/usr/local/opnsense/scripts/Xray/xray-service-control.php start

# Если нет вывода — проверить включён ли сервис:
configctl xray status

# Если "disabled in config":
# GUI → General → Enable Xray ✓ → Apply

# Валидация конфига отдельно:
configctl xray validate
# или напрямую:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json
```

---

### Сервис "stopped" после перезагрузки

Xray стартует автоматически при загрузке, если `General → Enable Xray` включён. Если не стартует:

```sh
# 1. Проверить лог загрузки
cat /tmp/xray_syshook.log

# 2. Проверить скрипты загрузки
ls -la /usr/local/etc/rc.syshook.d/start/50-xray
ls -la /usr/local/etc/inc/plugins.inc.d/xray.inc

# 3. Проверить флаг намеренной остановки (per instance)
ls -la /var/run/xray_stopped_*.flag
# Если есть — удалить:
rm -f /var/run/xray_stopped_<uuid>.flag

# 4. Запустить вручную
configctl xray start
```

---

### Кнопка Apply / Start / Stop не работает ("No response from configd")

```sh
# 1. Проверить что configd запущен
service configd status

# 2. Если не запущен
service configd start

# 3. Проверить lock-файл (per instance)
ls -la /var/run/xray_start_*.lock
# Если старше 1 минуты:
rm -f /var/run/xray_start_<uuid>.lock

# 4. Проверить зависший PHP-процесс
ps aux | grep xray-service-control
# Если старше 2 минут:
kill <PID>

# 5. Перезапустить configd
service configd restart
# Подождать 3 секунды, затем попробовать в GUI

# 6. Проверить лог configd
tail -20 /var/log/system/latest.log
```

---

### Сайты не открываются через VPN

**Симптом:** `curl --socks5` возвращает 200, но браузер/LAN-клиенты не могут открыть сайты через VPN.

**Причина:** Нет Outbound NAT правила для TUN-интерфейса.

```sh
# Проверить NAT-правила для TUN-интерфейса:
pfctl -sn | grep tun

# Если пусто — добавить Outbound NAT правило (см. раздел выше)
```

**Другие причины:**
```sh
# DNS не резолвит через туннель?
# Попробуй напрямую по IP:
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10

# Если IP работает, а домен нет — проблема DNS
# Проверить настройки DNS: GUI → System → General → DNS

# MTU слишком большой? Попробуй уменьшить:
# GUI → Instance → MTU → 1400 (по умолчанию 1500)
```

---

### Проблемы TUN-интерфейса

```sh
# Какой драйвер у инстанса? (вкладка Diagnostics или:)
configctl xray statusall

# — Нативный драйвер xray —
# Интерфейс не существует:
ifconfig tun100
# "does not exist" → xray не запущен или не смог создать TUN
ps aux | grep xray-core
configctl xray start <uuid>

# Интерфейс есть, но нет IP:
# xray сам назначает gateway+1 (например, 10.255.0.2/30 для 10.255.0.1/30)
tail -50 /var/log/xray-core-<uuid>.log    # искать ошибки TUN
# Убедись, что статический IP интерфейса OPNsense = gateway+1 —
# несовпадающий адрес переопределяется OPNsense и ломает маршрутизацию

# Остаточный интерфейс после нечистого kill:
ifconfig tun100 destroy && configctl xray start <uuid>

# — Legacy-драйвер tun2socks —
ps aux | grep tun2socks                    # не запущен → configctl xray start
ifconfig proxytun2socks0                   # нет строки "inet" → запустить syshook вручную:
/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray <uuid>

# Интерфейс UP, но нет трафика (любой драйвер)
netstat -ibn | grep tun100                 # Ibytes/Obytes = 0?
netstat -rn | grep tun                     # запись маршрутизации? шлюз/правила (селективный режим)
# Маршруты полного туннеля управляет xray — смотри: netstat -rn

# Интерфейс исчезает после ребута
# GUI → Interfaces → <TUN-интерфейс> → Prevent interface removal ✓
# Обязательно! Без этого OPNsense удаляет интерфейс при загрузке
```

---

### Проблемы режима Raw JSON

```sh
# Конфиг не применяется в raw-режиме
# 1. Содержимое редактора используется дословно — проверь его кнопкой
#    Validate Config (она гоняет xray -test по ЧЕРНОВИКУ, до сохранения) или:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json

# 2. Частые ошибки JSON:
# - Запятая после последнего элемента массива/объекта
# - Одинарные кавычки вместо двойных
# - Отсутствие кавычек у ключей
# - Комментарии (// или /* */) — не допускаются в JSON

# 3. При legacy-драйвере tun2socks raw-конфиг ОБЯЗАН содержать SOCKS-inbound
#    на адресе/порту инстанса — tun2socks форвардит в него трафик TUN

# 4. Возможности wizard'а (правила маршрутизации, балансировщик, секции
#    TUN/SOCKS) на raw-режим не действуют — конфиг целиком твой
```

---

### Проблемы с импортом VLESS-ссылки

```sh
# Кнопка Import не работает / возвращает ошибку
# 1. Проверить консоль браузера (F12 → Console) на JS-ошибки

# 2. Ссылка должна начинаться с "vless://"
# Другие протоколы (vmess://, ss://) не поддерживаются для импорта
# Используй Custom Config и вставь config.json вручную

# 3. Формат ссылки:
# vless://UUID@HOST:PORT?params#name
# Обязательно: UUID, HOST, PORT
# Опционально: type, security, sni, fp, pbk, sid, flow, path, host и т.д.

# 4. Порт 1-65535, HOST не пустой, UUID не пустой

# 5. После импорта нажми Apply для запуска сервиса!
```

---

### Проблемы подключения VLESS+Reality

```sh
# "failed to dial" в xray-core-<uuid>.log
# 1. Сервер доступен?
nc -zv <server_host> <server_port>
# или:
ping <server_host>

# 2. Неправильный порт?
# Проверить: GUI → Instance → Server Port

# 3. Ошибка Reality handshake в логе:
tail -50 /var/log/xray-core-<uuid>.log | grep -i reality
# Частые причины:
# - Неправильный PublicKey (pbk) — должен соответствовать приватному ключу сервера
# - Неправильный ShortID (sid) — должен совпадать с конфигом сервера
# - Неправильный SNI — должен быть в списке разрешённых на сервере
# - Неправильный fingerprint — попробуй "chrome" (самый совместимый)
# - Расхождение времени — Reality чувствителен к синхронизации
#   ntpdate pool.ntp.org    # синхронизировать время
#   date                     # проверить текущее время

# 4. Сервер блокирует ваш IP?
# Попробуй с другой сети или проверь логи сервера
```

---

### Проблемы Watchdog

```sh
# Watchdog не перезапускает после падения
# 1. Watchdog включён?
# GUI → General → Enable Watchdog ✓

# 2. Флаг намеренной остановки? (per instance)
ls -la /var/run/xray_stopped_*.flag
# Если есть — watchdog намеренно игнорирует. Удалить:
rm -f /var/run/xray_stopped_<uuid>.flag

# 3. Cron запускает watchdog?
crontab -l | grep xray
# Должна быть запись xray-watchdog.php

# 4. Лог watchdog
tail -30 /var/log/xray-watchdog.log
# "restart FAILED" → смотри xray-core-<uuid>.log для причины

# Watchdog в цикле перезапусков
# Причина: xray-core падает сразу после старта
# Сначала исправь причину:
configctl xray generate <uuid> > /tmp/cfg.json && /usr/local/bin/xray-core -test -c /tmp/cfg.json
# Исправь конфиг — после этого watchdog заработает
```

---

### Проблемы версии xray-core

```sh
# Проверить установленную версию
/usr/local/bin/xray-core version

# v4.0.0 ориентируется на xray-core 26.10+ (нативный TUN inbound на FreeBSD
# с autoSystemRoutingTable / autoOutboundsInterface / autoSystemDnsToGateway).
# На старых билдах работает только legacy-драйвер tun2socks.

# Обновить до последней версии:
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip -o xray.zip xray
# Сначала остановить сервис:
configctl xray stop
install -m 0755 /tmp/xray /usr/local/bin/xray-core
configctl xray start
/usr/local/bin/xray-core version    # проверить
```

---

### Проблемы производительности

```sh
# Высокая задержка через туннель
# 1. Проверить RTT до VPN-сервера
ping -c 5 <server_host>
# GUI → вкладка Diagnostics показывает Ping RTT

# 2. Туннель перегружен?
netstat -ibn | grep tun100
# Сравнить Ibytes/Obytes во времени

# 3. Попробовать уменьшить MTU
# GUI → Instance → MTU → 1400 (или 1300 для двойной инкапсуляции)

# 4. Проверить нагрузку сервера — сам VPN-сервер может тормозить
#    При включённом Load Balancing стратегия leastPing выберет узел с минимальным RTT

# Использование CPU/памяти
ps aux | grep -E 'xray|tun2socks' | awk '{print $3, $4, $11}'
# Столбец 1 = %CPU, 2 = %MEM, 3 = процесс
# xray-core обычно <5% CPU, ~30MB RAM (чуть больше при нативном TUN-стеке)
# tun2socks (legacy) обычно <3% CPU, ~15MB RAM
```

---

### Проблемы прав и файлов

```sh
# Файлы конфигов не доступны для записи
ls -la /usr/local/etc/xray-core/
ls -la /usr/local/tun2socks/
# config.json: -rw-r----- (0640)
# Директории: drwxr-x--- (0750)

# Исправить права:
chmod 0640 /usr/local/etc/xray-core/config-<uuid>.json
chmod 0750 /usr/local/etc/xray-core/

# Бинарник не исполняемый
chmod 0755 /usr/local/bin/xray-core
chmod 0755 /usr/local/tun2socks/tun2socks

# PHP-ошибки (GUI не грузится, API возвращает 500)
tail -30 /var/lib/php/tmp/PHP_errors.log

# configd не может выполнить скрипты
ls -la /usr/local/opnsense/scripts/Xray/
# Все .php файлы должны быть исполняемыми (-rwxr-xr-x)
chmod 0755 /usr/local/opnsense/scripts/Xray/*.php
service configd restart
```

---

### Зомби-процессы

```sh
# Несколько процессов xray-core или tun2socks
ps aux | grep -E 'xray-core|tun2socks' | grep -v grep

# Если больше одного — убить все и перезапустить:
pkill -f xray-core
pkill -f tun2socks
rm -f /var/run/xray_core_*.pid /var/run/tun2socks_*.pid /var/run/xray_start_*.lock
# Остаточный TUN после нечистого kill (нативный драйвер):
# ifconfig tun100 destroy
sleep 1
configctl xray start

# PID-файл есть, но процесс мёртв (v3.0.0: PID-файлы per-instance)
ls /var/run/xray_core_*.pid
# Проверить каждый PID:
cat /var/run/xray_core_*.pid
# Если "No such process" — устаревший PID-файл
rm -f /var/run/xray_core_*.pid /var/run/tun2socks_*.pid
configctl xray start
```

---

### Проблемы DNS через VPN

```sh
# Симптом: IP работают, домены не резолвятся

# 1. Тест DNS через туннель
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10    # IP — должен работать
curl --socks5 127.0.0.1:10808 -s https://google.com --max-time 10  # домен — не работает?

# 2. DNS может утекать мимо туннеля
# Проверить DNS-серверы:
cat /etc/resolv.conf

# 3. Направить DNS через туннель:
# GUI → System → General → DNS Servers
# Добавить DNS-сервер (например 1.1.1.1) с Gateway = PROXYTUN_GW

# 4. Или пусть DNS отвечает сам Xray:
# Диалог инстанса → DNS Mode → Hijack
# DNS (порт 53) из туннеля резолвится внутри xray
# (dns-модуль + dns-out + правило маршрутизации генерируются автоматически)

# 5. В raw-режиме добавь секцию dns сам:
# {"dns": {"servers": ["1.1.1.1"]}, "outbounds": [{"tag":"dns-out","protocol":"dns"}]}
```

---

### Селективная маршрутизация не работает

```sh
# Трафик идёт напрямую вместо VPN

# 1. Правила файрвола существуют?
pfctl -sr | grep PROXYTUN
# Должны быть правила с gateway = PROXYTUN_GW

# 2. Алиас заполнен?
pfctl -t <alias_name> -T show
# Должен содержать IP/сети

# 3. Шлюз доступен? (селективный режим)
netstat -rn | grep tun
# Должен показать 10.255.0.1 (Far Gateway) через tun100

# 4. Шлюз помечен как down?
# GUI → System → Gateways → Status
# Если "down" — см. раздел Мониторинг шлюза

# 5. Порядок правил важен! VPN-правило должно быть ВЫШЕ default allow
# GUI → Firewall → Rules → LAN
# Перетащить VPN-правило выше "Default allow LAN"

# 6. Маршрутизация на уровне движка: проверь правила вкладки Routing —
#    правило geosite/geoip может отправлять назначение в direct
#    (нужны geoip.dat/geosite.dat: ls /usr/local/etc/xray-core/)
```

---

### Проблемы мониторинга шлюза

```sh
# Шлюз показывает "down" хотя туннель работает

# dpinger (монитор шлюзов OPNsense) использует ICMP
# xray-core не отвечает на ICMP → dpinger видит шлюз как "down"

# Решение 1: Отключить мониторинг для этого шлюза
# GUI → System → Gateways → Edit PROXYTUN_GW
# Disable Gateway Monitoring ✓

# Решение 2: Указать IP для мониторинга
# GUI → System → Gateways → Edit PROXYTUN_GW
# Monitor IP: 1.1.1.1 (или любой IP, отвечающий на ping через VPN)
# Примечание: может не работать если xray-core отбрасывает ICMP
```

---

### Расположение логов

| Лог | Путь | Содержит | Ротация |
|---|---|---|---|
| Xray Core (per instance) | `/var/log/xray-core-<uuid>.log` | stdout/stderr xray-core (и legacy tun2socks) | 600 KB, 3 файла |
| Boot | `/tmp/xray_syshook.log` | Автозапуск, проверка TUN, firewall reload | 50 KB, в скрипте |
| Watchdog | `/var/log/xray-watchdog.log` | Обнаружение падений, перезапуски | 100 KB, 3 файла |
| PHP ошибки | `/var/lib/php/tmp/PHP_errors.log` | Ошибки OPNsense PHP (GUI, API) | Управляется OPNsense |
| Система | `/var/log/system/latest.log` | Ошибки configd | Управляется OPNsense |

---

### Все команды плагина

```sh
# Управление сервисом (добавь <uuid> для конкретного инстанса)
configctl xray start [uuid]            # запустить все включённые инстансы / один
configctl xray stop [uuid]             # остановить
configctl xray restart [uuid]          # остановить + запустить
configctl xray reconfigure [uuid]      # вызывается кнопкой Apply (stop + start)

# Диагностика
configctl xray status [uuid]           # JSON: статус (первого/одного) инстанса
configctl xray statusall               # JSON: статус ВСЕХ инстансов
configctl xray version                 # JSON: версия плагина
configctl xray validate [uuid]         # сухой прогон валидации (xray -test)
configctl xray generate [uuid]         # печать сгенерированного config.json
configctl xray testconnect [uuid]      # curl-тест через SOCKS5
configctl xray ifstats [uuid]          # JSON: статистика TUN, uptime, RTT, окружение
configctl xray validatefile <path>     # валидация черновика /tmp/xray-val-* (кнопка GUI)

# Логи
configctl xray log [uuid]              # последние 150 строк boot-лога
configctl xray xraylog [uuid]          # последние 200 строк лога xray-core

# Прямой запуск скриптов (для отладки)
/usr/local/opnsense/scripts/Xray/xray-service-control.php start
/usr/local/opnsense/scripts/Xray/xray-service-control.php statusall
/usr/local/opnsense/scripts/Xray/xray-service-control.php validate
/usr/local/opnsense/scripts/Xray/xray-ifstats.php
/usr/local/opnsense/scripts/Xray/xray-testconnect.php

# Системные
ps aux | grep -E 'xray|tun2socks'    # запущенные процессы
ifconfig tun100                        # TUN-интерфейс (нативный драйвер)
netstat -rn | grep tun                # таблица маршрутизации
netstat -ibn | grep tun               # статистика трафика интерфейса
sockstat -4l | grep 10808             # кто использует SOCKS5-порт
tail -f /var/log/xray-core-<uuid>.log # мониторинг лога в реальном времени
```

---

### Сброс и переустановка

```sh
# Полная переустановка без потери конфига OPNsense
sh install.sh uninstall
sh install.sh

# Ядерный вариант — очистить всё и начать с нуля
configctl xray stop
rm -f /var/run/xray_*.pid /var/run/tun2socks_*.pid /var/run/xray_*.lock /var/run/xray_*.flag
rm -f /usr/local/etc/xray-core/config-*.json
rm -f /usr/local/tun2socks/config-*.yaml
sh install.sh uninstall
sh install.sh
# Затем заново импортировать VLESS-ссылку и Apply
```

---

## Структура файлов

```
os-xray/
├── install.sh
├── Changelog.md
└── plugin/
    ├── +MANIFEST                               ← FreeBSD pkg метаданные
    ├── etc/
    │   ├── inc/plugins.inc.d/
    │   │   └── xray.inc                        ← регистрация сервиса, boot hook, cron watchdog
    │   ├── newsyslog.conf.d/
    │   │   └── xray.conf                       ← ротация логов xray-core и xray-watchdog
    │   └── rc.syshook.d/start/
    │       └── 50-xray                         ← автозапуск после ребута (учитывает драйвер)
    ├── scripts/Xray/
    │   ├── xray-service-control.php            ← управление сервисом (драйверы, generate, validatefile)
    │   ├── xray-watchdog.php                   ← watchdog: проверка и перезапуск процессов
    │   ├── xray-ifstats.php                    ← статистика TUN + окружение для Diagnostics
    │   └── xray-testconnect.php                ← проверка соединения через SOCKS5
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                   ← configd actions
    ├── web/js/xray/                            ← бандл CodeMirror (редактор Raw JSON)
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php       ← модель: enable, watchdog
        │   ├── Instance.xml / Instance.php     ← модель: инстансы (поля tun/dns/lb/srv)
        │   ├── Group.xml / Group.php           ← модель: группы серверов
        │   ├── RoutingRule.xml / RoutingRule.php ← модель: правила маршрутизации (v4.0.0)
        │   ├── ACL/ACL.xml                      ← права доступа (page-vpn-xray)
        │   └── Menu/Menu.xml                    ← пункт меню VPN → Xray
        ├── library/OPNsense/Xray/
        │   ├── ConfigBuilder.php               ← ГЕНЕРАТОР конфига (v4.0.0)
        │   └── VlessLink.php                   ← парсер vless:// ссылок
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   ├── forms/group.xml / forms/server.xml
        │   ├── forms/rule.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── GroupController.php          ← CRUD групп, импорт подписки, refresh
        │       ├── RuleController.php          ← CRUD правил маршрутизации (v4.0.0)
        │       ├── ServiceController.php       ← + validateConfig (черновик), previewConfig
        │       └── ImportController.php        ← парсинг VLESS-ссылки
        └── views/OPNsense/Xray/
            ├── general.volt                     ← вкладки, диалоги, модалки preview/nginx
            └── partials/
                ├── tab_instances.volt
                ├── tab_groups.volt
                ├── tab_routing.volt             ← грид правил маршрутизации (v4.0.0)
                ├── tab_diagnostics.volt
                ├── tab_logs.volt
                └── scripts.volt                 ← логика страницы (CodeMirror, сниппеты, синхронизация)
```

---

## Если бинарники ещё не установлены

Установщик сам сообщит об отсутствии бинарников. Ниже — команды для ручной установки.

**xray-core** — [github.com/XTLS/Xray-core/releases](https://github.com/XTLS/Xray-core/releases) (26.10+ для нативного TUN-драйвера)
```sh
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip xray.zip xray
install -m 0755 /tmp/xray /usr/local/bin/xray-core
```

**geoip.dat / geosite.dat** — для правил GeoIP/GeoSite (обычно ставятся `install.sh`'ом)
```sh
fetch -o /usr/local/etc/xray-core/geoip.dat https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geoip.dat
fetch -o /usr/local/etc/xray-core/geosite.dat https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geosite.dat
```

**tun2socks** (опционален, только legacy TUN-драйвер) — [github.com/xjasonlyu/tun2socks/releases](https://github.com/xjasonlyu/tun2socks/releases)
```sh
fetch -o /tmp/tun2socks.zip https://github.com/xjasonlyu/tun2socks/releases/latest/download/tun2socks-freebsd-amd64.zip
cd /tmp && unzip tun2socks.zip
mkdir -p /usr/local/tun2socks
install -m 0755 /tmp/tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks
```

> Имя файла tun2socks после распаковки может отличаться в зависимости от версии — проверь командой `ls /tmp/tun2socks*`.

---

## Changelog

Полная история изменений — в файле [CHANGELOG.md](CHANGELOG.md).

| Версия | Что изменилось |
|--------|---------------|
| 4.0.1  | Raw JSON: комментарии в стиле xray (//, #), XRAY_LOCATION_ASSET для geo-данных, runtime-параметры (TUN/SOCKS) выводятся из JSON, противоречивые секции GUI скрыты в raw-режиме |
| 4.0.0  | **Нативные механизмы Xray**: встроенный TUN inbound (`tun_driver`), вкладка Routing (правила domain/ip/port/geoip/geosite), balancer + burstObservatory (leastPing/roundRobin/leastLoad/random), серверный VLESS-inbound (ws/gRPC/h2/TCP, TLS/Reality, nginx-сниппет), режим Raw JSON с CodeMirror + валидацией черновика, ConfigBuilder, DNS hijack, опция полного туннеля (`net.fibs=2`), требование xray-core 26.10+, установка geo-дат, миграция с v3.x |
| 3.2.1  | Фикс ротации логов (daemon -H -o + SIGHUP reopen), tun2socks `--config` |
| 3.2.0  | Форк: группы серверов, импорт подписки, Refresh, переключатель node_source, класс VlessLink |
| 3.0.1  | Исправления ошибок мульти-инстанцов |
| 3.0.0  | Мульти-инстанс (ArrayField), per-instance статус в таблице, Import VLESS и Validate Config внутри диалога, custom config использует SOCKS5 из формы, переименование uuid→vless_uuid, миграция в install.sh |
| 2.0.0  | Custom Config (wizard/custom), Import VLESS с авто-генерацией config.json для любого транспорта, нормализация xhttp↔splithttp, проверка версии xray-core при установке |
| 1.10.0 | Проверка версии при установке, `configctl xray version`, API version endpoint, Outbound NAT в README |
| 1.9.3  | Фиксы P1 (implode, socks5_port, validate tempfile, дедупликация), Bypass Networks, Copy Debug Info, Ping RTT, автообновление Diagnostics |
| 1.9.2  | Фикс fatal error tun2socks при stop, ротация watchdog лога |
| 1.9.1  | Хотфикс validate: синтаксис xray -test, расширение .json для tempnam |
| 1.9.0  | Улучшен hint для поля SOCKS5 Listen Address |
| 1.8.0  | Аудит безопасности: фиксы proc_kill, watchdog stopped flag, validate tempfile |
| 1.7.0  | Фикс блокировки GUI, фикс ifstats bytes, запуск 50-xray из do_start |
| 1.6.0  | BUG-5/9, Watchdog E1, Diagnostics E4, Validate Config E5 |
| 1.5.0  | Ротация лога, кнопки Start/Stop/Restart, вкладка Xray Core Log |
| 1.4.0  | Аудит безопасности P0-P2, xray-testconnect, flock, stderr в лог |
| 1.3.0  | Версионирование моделей, `+MANIFEST`, `Changelog.md` |
| 1.2.0  | Вкладка Log, кнопка Test Connection, интервал статуса 5 с |
| 1.1.0  | Надёжный install.sh: PHP-парсинг конфигов, check_port, ротация лога |
| 1.0.1  | Исправлен loglevel, TUN destroy при stop, flock |
| 1.0.0  | ACL, валидация UUID, санитизация ImportController |
| 0.9.0  | Первоначальный релиз |

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Автор

Меркулов Павел Сергеевич
Февраль — Март 2026

---

## Благодарности

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core и протокол VLESS+Reality
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — открытая архитектура плагинов
- [yukh975](https://github.com/yukh975) - за помощь в тестировании
- [hohner36](https://github.com/hohner36) - за помощь в тестировании и настройке автоматизации
