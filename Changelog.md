# Changelog — os-xray

All notable changes to this project will be documented in this file.
Format: [Semantic Versioning](https://semver.org/).

---

## [4.1.0] — 2026-11-05 (fork)

### Added
- **Серверный inbound: все протоколы, а не только VLESS.** `srv_protocol`: VLESS / VMess / Trojan / Shadowsocks (включая 2022-blake3 с серверным ключом и ivCheck). Кнопка **Add user** генерирует креденшал по протоколу (UUID / пароль / base64-ключ SS-2022), тег inbound'а сохранён как `vless-in` (легаси, на него ссылаются правила маршрутизации)
- **Транспорты XHTTP и HTTPUpgrade** (в дополнение к ws/grpc/h2/tcp) с host-header; REALITY разрешён для tcp/grpc/xhttp. nginx-сниппет: вкладка XHTTP, активная вкладка выбирается по текущему транспорту
- **QR + share-ссылка на каждого клиента.** `ConfigBuilder::clientShareLink()` строит `vless://` / `vmess://` (base64 JSON v2) / `trojan://` / `ss://` (SIP002, base64url); REALITY-ссылки получают `pbk=`, выведенный из приватного ключа на сервере (libsodium x25519, `sodium_crypto_scalarmult_base`). Эвристика TLS: security=none за nginx (listen 127.0.0.1) → в ссылке security=tls. Поля **Public Host / Public Port** задают адрес клиента. API `POST /api/xray/service/clientLink` строит ссылку из ПОЛЕЙ ДИАЛОГА (несохранённое состояние работает), рендер QR — библиотекой qrcode-generator 1.4.4 (MIT, плоский ассет `/js/xray-qrcode.min.js`)
- GUI: список клиентов под полем users (email / креденшал / QR-кнопка), поля Shadowsocks видны только для shadowsocks, Host — только для ws/h2/xhttp/httpupgrade

---

## [4.0.7] — 2026-11-04 (fork)

### Fixed
- **TypeError на загрузке страницы: `$(...).ajaxPrefilter is not a function`.** Правильный API — глобальная `$.ajaxPrefilter(...)`, а не метод jQuery-объекта. Исключение на загрузке убивало регистрацию ВСЕХ обработчиков ниже по коду: Preview не реагировал на клик, вкладки Diagnostics (все поля «-») и Logs не загружались (найдено по консоли браузера; `node --check` такую ошибку не ловит — она рантаймовая)
- **Web-ассеты CodeMirror не загружались: `/js/xray/*` → HTML (MIME mismatch).** nginx OPNsense не отдаёт подкаталоги в `/js/` (location-регекс допускает одно звено пути — плоские `/js/*.js` ядра работают). Ассеты ставятся плоско с префиксом: `/js/xray-codemirror.min.js`, `/js/xray-javascript.min.js`, `/js/xray-codemirror.min.css`; установщик вычищает старый вариант с подкаталогом. Редактор Raw JSON до этого молча деградировал в обычную textarea

---

## [4.0.6] — 2026-11-04 (fork)

### Fixed
- **Validate Config: «core: Failed to get format of /tmp/xray-val-*».** xray определяет формат конфига по РАСШИРЕНИЮ файла, а черновик писался в безымянный tempnam — валидация не работала вовсе (воспроизведено: без расширения — Failed to get format, с .json — Configuration OK). Теперь файл создаётся как `xray-val-*.json` (regex путей в действии validatefile расширение уже допускал)
- **Preview: «Save the instance first» оставался и после v4.0.5.** Перехват клика по `.command-edit` ненадёжен (dropdown-меню команд рендерится вне грида, диалог открывается двойным кликом и т.п.). UUID теперь захватывается из ajax bootgrid'а (`ajaxPrefilter`: getItem/<uuid> при открытии редактирования — этот запрос выполняется ВСЕГДА; addItem — сброс). Защита от катастрофы: в режиме «add» тихое сохранение перезаписало бы ДРУГОЙ инстанс дефолтами — добавлены guard'ы (триггер `opnsense_bootgrid_mapped` + иконка fa-plus в заголовке диалога)
- **Diagnostics: «Listening Ports 10808: no» при настроенном и слушающем порту.** sockstat печатает строку как `... LOCAL FOREIGN` — слушающий порт стоит в LOCAL (например `192.168.88.6:10808`), а строка ЗАКАНЧИВАЕТСЯ foreign-адресом (`*:*`). Привязка regex к концу строки не матчила ничего — когда-либо. Теперь `[:.]PORT(пробел|конец строки)`; префикс отсекает ложные срабатывания по PID

---

## [4.0.5] — 2026-11-04 (fork)

### Fixed
- **«No response from configd» вместо текста ошибки от Validate Config (и start/restart/reconfigure/validate/generate/testconnect).** configd исполняет действия script_output с `check=True`: ненулевой exit код скрипта → `CalledProcessError` → configd возвращает `Execute error`, который Backend превращает в пустой ответ. Скрипт же при невалидном черновике печатал `ERROR: ...` И выходил с кодом 1 — текст проглатывался. Добавлен `errors:no` в 7 действий actions_xray.conf (`check=False`: вывод доезжает до GUI, exit-коды сохраняются для CLI и watchdog)
- **Preview Generated Config: «Save the instance first» на каждую правку.** Кнопка требовала предварительно сохранённый инстанс, а Save закрывал диалог — превью несохранённых правок было невозможно. Теперь Preview молча сохраняет форму штатным `saveFormToEndpoint` (диалог остаётся открытым, валидация полей работает как у Save) и строит превью из только что сохранённого состояния
- **Встроенный (wizard) TUN отвергал имя не-tunN.** `buildInbounds()` требовал `^tun\d+$`, хотя xray на FreeBSD создаёт интерфейс переименованием клона /dev/tun и принимает любые валидные имена (tun2socks годами создавал `proxytun2socks0`). Хуже: имена tunN OPNsense скрывает из Interfaces → Assignments — то есть строгое правило делало встроенный TUN принципиально неназначаемым в GUI. Теперь проверяется только валидность имени (строчная буква/цифры, последняя — цифра, ≤15 символов); help-тексты формы обновлены

---

## [4.0.4] — 2026-11-04 (fork)

### Added
- **`ConfigBuilder::rawConfigIssues()`: предупреждения о ловушках raw-конфига, которые `xray -test` не ловит** (он не стартует inbound'ы, а xray умирает на первом же):
  - **listen-адрес inbound'а не назначен ни одному интерфейсу роутера** — xray падает на старте с `bind: can't assign requested address` (EADDRNOTAVAIL). Типовой случай: конфиг, скопированный с другой машины, где этот IP был локальным (найдено на живом кейсе: `in_mixed` слушал 192.168.88.2 — IP старого роутера; xray умирал до tun-inbound, syshook 15 секунд ждал интерфейс, watchdog ретраил каждую минуту)
  - имя tun-интерфейса не по документированной для FreeBSD схеме `tunN` (utunN — macOS-only)
  - про отсутствие `"mtu"` предупреждения нет: xray сам подставляет 1500 (infra/conf/tun.go, проверено в v26.10.31)
  - предупреждение о схеме имени tunN снабжено подсказкой: OPNsense скрывает имена вида tunN/tapX из Interfaces → Assignments (`interfaces_virtual_patterns` в interfaces.lib.inc — потому старый `proxytun2socks0` в GUI был виден, а `tun5` — нет); для назначения в GUI имя должно не попадать под виртуальные паттерны (например, `xraytun0`), либо пусть маршрутизацией управляет сам xray (`autoSystemRoutingTable`)
  - адреса берутся из `ifconfig -a` (белый список: `0.0.0.0`/`::`, loopback — плагин сам вешает 127.x на lo0, hostname не проверяется)
- Предупреждения выводятся в **Validate Config** («xray -test passed, but the config will fail at start»), в вывод **start**-действия и в лог syshook (`TUN_WARN`, escapeshellarg — значение исполняется через sh eval)

---

## [4.0.3] — 2026-11-04 (fork)

### Fixed
- **Syshook ждал несуществующий `proxytun2socks0` 15 секунд на raw-инстансе без `"name"` у tun-inbound.** `extractRuntimeFields` возвращает пустой `tun_iface`, но `${TUN_IFACE:-proxytun2socks0}` в sh подставляет дефолт и для ПУСТОГО значения — осознанный «нет TUN» превращался в ожидание фантомного интерфейса. Дефолты заменены на форму без двоеточия (`${VAR-default}`): подстановка только если переменная вовсе не установлена (eval не удался). Пустое имя → syshook сразу пропускает TUN-этап с подсказкой добавить `"name": "tunN"` в settings tun-inbound

---

## [4.0.2] — 2026-11-04 (fork)

### Fixed
- **Установка падала на web-ассетах и оставляла систему в нерабочем виде.** `install_tree` не создавал целевой каталог для ПЛОСКОГО источника (`web/js/xray` — только файлы, без сабдиректорий): `install: /usr/local/opnsense/www/js/xray: No such file or directory`, после чего `set -e` ронял скрипт — version.txt не записывался, configd не перезапускался (симптом: «Action not allowed or missing» на всех действиях UI, «Invalid JSON from ifstats»). Теперь `install -d "$_dst"` выполняется всегда. Воспроизведено и проверено в песочнице: без фикса — exit 71 на web-ассетах, с фиксом — установка проходит целиком

---

## [4.0.1] — 2026-11-04 (fork) — 2026-11-04 (fork)

### Fixed
- **Raw-конфиги с комментариями отвергались валидацией.** xray-core принимает комментарии в config.json (`//`, `#`, блочные — `infra/conf/json/reader.go`), а `json_decode` нет: валидный для xray конфиг падал с «Invalid JSON» и в кнопке Validate Config, и при старте (`buildRaw`). Добавлен `ConfigBuilder::stripJsonComments()` — порт xray-ридера (с учётом кавычек и escape: `https://…` не режется); strip выполняется перед decode, в `xray -test` уходит оригинальный черновик
- **Geosite/geoip правила роняли валидацию и старт.** xray ищет dat-файлы в CWD (у daemon(8)/configd это `/`), а не рядом с конфигом. Теперь процессу xray и `xray -test` передаётся `XRAY_LOCATION_ASSET=/usr/local/etc/xray-core` (проверено: без env — «failed to open geosite.dat», с env — Configuration OK)
- **Противоречивые GUI-поля в raw-режиме.** Поля TUN/SOCKS больше не конфликтуют с JSON: для raw-инстансов runtime-параметры (имя TUN/MTU из tun-inbound, адрес/порт SOCKS из socks/mixed-inbound) выводятся из самого config.json (`ConfigBuilder::extractRuntimeFields`) — их используют status, syshook, testconnect, ifstats, lo0-alias и legacy-tun2socks. В диалоге соответствующие секции (TUN-детали, SOCKS5, DNS, LB, Server Inbound, Misc) в raw-режиме скрыты целиком
- **Статус raw-инстанса без tun-inbound** показывал ложный «tun: down» — теперь серый «n/a» (per-instance бейдж и агрегированный)
- **`'VAR'=value` в sh не является присваиванием** — env-префикс в `proc_start` собирался с закавыченными именами; теперь ключи валидируются регуляркой, экранируется только значение
- syshook: raw-конфиг без tun-inbound/имени больше не ждёт интерфейс 15 секунд — пропуск с пометкой в логе

---

## [4.0.0] — 2026-11-04 (fork)

Крупный релиз: нативные механизмы Xray-core вместо внешних демонов.

### Added
- **Нативный TUN inbound** — новый per-instance переключатель `tun_driver`: `xray` (интерфейс создаёт сам xray-core, `"protocol": "tun"`, схема v26.x: `name`/`mtu`/`gateway`; на FreeBSD имя обязано быть `tunN`) или `tun2socks` (legacy). Схемы сверены с upstream: `tun_freebsd.go`, PR #6691/#7123/#7130
- **Полный туннель** — `tun_auto_routing = full`: `autoSystemRoutingTable: ["0.0.0.0/0"]` + `autoOutboundsInterface: "auto"` (escape FIB, требует `net.fibs=2`) + опциональный `autoSystemDnsToGateway` (resolvconf); в Diagnostics — значение `net.fibs`
- **Правила маршрутизации движка** — новая модель `OPNsense\Xray\RoutingRule` (`//OPNsense/xray/routing_rules`), вкладка **Routing**: условия domain / ip / port / sourcePort / network / inboundTag / **geoip** / **geosite** → действия proxy / balancer / direct / block; install.sh ставит `geoip.dat`/`geosite.dat`; старый `bypass_networks` мигрирует в правило
- **Балансировка и observatory** — `lb_enabled` + `lb_strategy` (leastPing / roundRobin / leastLoad / random — подтверждено по `app/router/config.go`), члены — все серверы выбранной группы либо список `lb_custom`; генерируются `routing.balancers` + `burstObservatory` (pingConfig) + catch-all правило; `fallbackTag` = первый узел
- **Серверный inbound (VLESS)** — движок как входящий прокси: ws (за nginx) / grpc / h2 / tcp, security none (TLS на nginx) / tls / reality; пользователи JSON + кнопка «Add user» с генерацией UUID; произвольные inbound'ы через `srv_custom_inbounds`; `srv_default_outbound` (правило inboundTag после пользовательских); генератор nginx-сниппетов (WS/gRPC/stream)
- **Raw JSON режим** — `node_source = raw`: полный `config.json` в CodeMirror-редакторе (бандл в `/js/xray/`), кнопка **Validate Config** валидирует черновик через `xray -test` (endpoint `validateConfig` + configd-действие `validatefile` с белым списком `/tmp/xray-val-*`), кнопка **Format**
- **Preview Generated Config** — `POST api/xray/service/previewConfig/<uuid>` собирает конфиг в процессе (ConfigBuilder) и показывает в модалке; configd-действие `generate` — для CLI
- **DNS hijack** — `dns_mode = hijack`: dns-модуль + `dns-out` outbound + правило `inboundTag(tun-in) port 53 → dns-out` (подтверждено: FakeIP в Xray-core отсутствует — dns.go)
- **ConfigBuilder** (`mvc/app/library/OPNsense/Xray/ConfigBuilder.php`) — единая точка генерации конфига: сборка inbounds (socks5 опционален через `socks5_enabled`, tun, vless-in, custom), outbounds (proxy-N/direct/block/dns-out), routing (порядок: dns-hijack → пользовательские → private→direct → vless-in default → catch-all), dns, burstObservatory; строгие ошибки конфигурации (несовместимость портов, `tunN` для xray-драйвера, action=balancer без LB и т.п.); `node_source` расширен до `config|group|none|raw`
- **Диагностика окружения** — версия xray (< 26.10 → предупреждение), наличие geo-данных, `net.fibs`, слушающие порты инстанса (sockstat), статус TUN-интерфейса вместо tun2socks для xray-драйвера

### Changed
- `xray-service-control.php`: конфиг собирается через ConfigBuilder (двойная логика `xray_write_config`/`validate` устранена); `do_start` не трогает tun2socks при `tun_driver=xray`; `do_stop` принудительно уничтожает TUN после SIGKILL; статус отдаёт `tun`/`driver` ключи; watchdog проверяет t2s только у legacy-инстансов
- `xray_configure_do()` (boot) делегирует скрипту `xray-service-control.php start` вместо дублирования логики daemon(8)
- syshook `50-xray`: для xray-драйвера ждёт интерфейс и адрес (IP назначает xray, gw+1), для tun2socks — прежнее назначение IP из OPNsense
- `install.sh`: минимальная версия xray-core **26.10** (автообновление из latest release), tun2socks опционален, установка web-ассетов и geo-дат, миграция v3.x → v4.0.0 (`bypass_networks` → правило RoutingRule, `tun_driver=tun2socks` для существующих, дефолты новых полей)
- Экземпляр по умолчанию: `tun_interface = tun100`, `tun_gateway = 10.255.0.1/30` (static IP OPNsense = gw+1 = `10.255.0.2/30`)

### Compatibility
- Существующие инстансы после миграции работают как раньше (tun2socks-драйвер, прежнее имя интерфейса); переключение на нативный TUN — вручную в диалоге (интерфейс переименовать в `tunN`)
- `node_source` пустой со выбранным сервером трактуется как `group` (наследие v3.1.0), как и раньше
- Удаление вручную: `//OPNsense/xray/routing_rules` из config.xml при откате

### Fixed
- **Ложное предупреждение о версии xray-core**: BSD sed на FreeBSD не понимает `\+` в BRE, версия не извлекалась (`XRAY_MAJOR=0`) — даже 26.3.27 считался «< 26.x». Парсинг переведён на portablе `awk`/`cut`, проверка учитывает major и minor (требуется ≥ 26.10)
- **install.sh «устанавливал» без файлов плагина**: при неполной копии репозитория (нет `plugin/`) `find` печатал ошибки, но установка рапортовала успех. Добавлена проверка наличия дерева исходников (fail-fast с подсказкой) и guard в `install_tree`
- **geoip.dat/geosite.dat не скачивались**: в релизах Xray-core этих ассетов больше нет (проверено на v26.10.31) — источник сменён на `Loyalsoldier/v2ray-rules-dat`
- Финальная подсказка установщика обновлена под v4: `tun100`, static IP = шлюз+1 (`10.255.0.2/30`), примечание про полный туннель (`net.fibs=2`) и отсутствие geo-данных

---

## [3.2.1] — 2026-09-23 (fork)

### Fixed
- **Логи xray-core и tun2socks пропадали после первой ротации.** `proc_start()` и путь загрузки в `plugins.inc.d/xray.inc` запускали `daemon(8)` через shell-редирект `>> log`: файл открывался один раз, newsyslog (`JG`, без сигнала) переименовывал и сжимал его, и вывод уходил в удалённый inode. Замерено 2026-09-23: у демонов обоих инстансов fd 1/2 указывали на удалённые файлы, лог общего инстанса не обновлялся с 2026-09-22 15:24. Теперь `daemon -H -o <log> -P /var/run/xray-daemon-<pidfile>`; newsyslog с флагом `R` вызывает новый `xray-log-reopen.sh`, который шлёт SIGHUP только супервизорам с таким pidfile. Путь загрузки пишет в лог своего инстанса, а не в общий `/var/log/xray-core.log`.
- **tun2socks 2.7 не запускался с `-config`** — нужен `--config`, в `proc_start` и в `plugins.inc.d/xray.inc`. На роутере это жило локальным патчем с 2026-09-16.

---

## [3.2.0] — 2026-09-16 (fork)

Форк `elleqt/opnsense-xray`. Отличия от апстрима собраны в README, раздел
«Форк: чем отличается от апстрима».

### Added
- **Группы серверов** — модель `OPNsense\Xray\Group` (`//OPNsense/xray/groups`) с двумя ArrayField (`group`, `server`), вкладка `Groups` с гридами групп и серверов
- **Импорт подписки** — `POST api/xray/group/importSubscription`: список `vless://` ссылок в base64 или plain, либо тело, вставленное руками, когда URL с роутера недоступен; нераспознанные строки уходят в `skipped` с номером строки и причиной, а не роняют импорт
- **Кнопка Refresh** — `POST api/xray/group/refresh/<uuid>`: сверка по ключу узла (added/updated/removed), узел, исчезнувший из подписки, но выбранный инстансом, помечается `stale` и не удаляется. Сервис не перезапускается, активный узел инстанса не меняется. Автообновления (cron/таймер/Apply) и автовыбора узла (balancer/observatory/leastPing/fallback) нет сознательно
- **Переключатель `node_source`** — узел берётся либо из `outbound_config` инстанса, либо из сервера группы; в диалоге показаны только поля выбранного режима
- **Класс `OPNsense\Xray\VlessLink`** — парсер `vless://` вынесен из `ImportController`, общий для импорта ссылки и импорта подписки
- **ACL** — `api/xray/group/*`

### Changed
- `xray-service-control.php`: единый резолвер `xray_active_outbound()` — одна точка, решающая, откуда инстанс берёт узел (`group` / `config` / пусто = поведение 3.1.0)
- `InstanceController::searchItem` и `xray-ifstats.php`: адрес узла и ping RTT резолвятся так же, поэтому в режиме группы колонка `Server` и Diagnostics показывают активный узел, а не содержимое JSON-поля

### Fixed
- **Дубль инстанса при обновлении** — проба «конфиг уже настроен» в `install.sh` искала поля v1/v2 `server_address`/`vless_uuid`, которых в 3.x нет, поэтому установка поверх рабочей 3.x уходила в импорт и заводила второй инстанс с тем же SOCKS-портом
- **Кнопка Refresh не рендерилась** — на OPNsense 26.7 колонку с id `commands` шим грида рендерит своим форматтером, если свой назван иначе (`groupCommands` молча игнорировался)
- **Фильтр серверов по группе не работал** — `requestHandler` читается только из `options`, на верхнем уровне вызова `UIBootgrid` он игнорируется
- **Фильтр мог обнулить выбранный сервер** — выбор снимался при любом несовпадении группы, включая отказ запроса и пустую группу, и следующий Save записывал пустой `server`, возвращая инстанс на JSON

## [3.0.1] - TBD
### Added
- **Enabled field** — добавлено поле 'enable' которое позволяет включить/выключить инстанц из автозапуска/перезагрузки
- **Multi-instance debug** — добавлена возможность просмотра логов, диагностики, тестирования отдельных инстанцов
- **Per instance log** — Доступ к логам xray доступен из UI для каждой сущности

### Changed
- `general.volt`: Разбит на несколько файлов, а так же небольшие улучшения интерфейса
- `Instance.xml`: Теперь содержит единое поле outbound_config, а итоговый конфиг генерируется автоматически.
Это делает конфиг инстанца не чувствительным к изменению версии xray, новым протоколам, методам обфускации.
- `install.sh`: Рекурсивная установка файлов и скриптов плагина

### Fixed
- **No startup on reboot** — xray_configure использовал неверный формат для передачи функции запускаемой при bootup
- **Actions xray argument passing** — исправлен неверный формат передачи опциональных аргументов
- **Multi-instance interface assigment** — ip выдавался только proxytun2socks0 из-за того что всегда происходил fallback на имя по умолчанию

## [3.0.0] — 2026-03-27

### Added
- **Multi-instance support** — GUI переведён на UIBootgrid CRUD: добавление, редактирование, удаление инстансов из таблицы
- **Per-instance service control** — каждый инстанс запускается с отдельным PID-файлом, конфигом, lock-файлом (по UUID)
- **Import VLESS внутри диалога** — кнопка Import VLESS и кнопка Validate Config перемещены из тулбара вкладки Instances внутрь модального диалога добавления/редактирования инстанса; Import — сворачиваемая панель в верхней части модала, Validate — в футере; отдельный import-модал удалён
- **Per-instance статус в таблице** — каждая строка инстанса отображает badges xray/tun2socks (up/down) через bootgrid formatter; статус получается из `/api/xray/service/statusall` и автообновляется каждые 5 секунд
- **Миграция v1.x → v3.0.0** в install.sh: автоматическая конвертация single `<instance>` → ArrayField `<instances><instance uuid="...">`
- **Миграция v2.x → v3.0.0**: автоматическое переименование `<uuid>` → `<vless_uuid>` (избежание конфликта с UUID ArrayField)
- **Cleanup старых PID-файлов** в install.sh (шаг 4.7): убивает процессы со старыми путями `/var/run/xray_core.pid`, `/var/run/tun2socks.pid`

### Changed
- `Instance.xml`: модель v3.0.0, ArrayField, поле `uuid` → `vless_uuid`, wizard-only поля Required=N
- `InstanceController.php`: полные CRUD-методы с правильными 3-параметровыми вызовами getBase/addBase/setBase
- `general.volt`: UIBootgrid таблица, per-instance status badges, toggleConfigMode с polling, Import flow — коллапс-панель внутри instance-диалога, Validate — в футере диалога
- `xray-service-control.php`: per-instance paths, UUID validation (strlen >= 36 для защиты от configd `%1`); custom config использует `socks5_listen`/`socks5_port` из полей формы вместо хардкода `127.0.0.1:10808`
- `ServiceController.php`: все actions принимают опциональный `$uuid` инстанса
- `actions_xray.conf`: `%1` для per-instance UUID во всех actions
- `install.sh`: версия 3.0.0, шаги 4.5/4.6/4.7 для миграции и cleanup
- `forms/instance.xml`: добавлено поле `instance.name`
- `ImportController.php`: `uuid` → `vless_uuid` в ответе парсера; server_address заполняется и в custom mode; `extractLink()` переименован в `extractRequestData()` — возвращает структуру с опциональными `socks5_listen`/`socks5_port` из тела запроса

### Fixed
- **Custom config SOCKS5 settings** — при импорте VLESS-ссылки в режиме custom config, генерируемый config.json теперь использует SOCKS5 Listen Address и Port из полей формы вместо хардкода `127.0.0.1:10808` (issue от yukh975)

### Tests
- Все 460 тестов обновлены для vless_uuid и ArrayField-структуры
- ModelValidationTest: version >= 3.0.0, wizard-only поля в optional списке

---

## [2.0.0] — 2026-03-19

### Added
- **Custom Config Mode** — новый режим `config_mode` (wizard/custom) в Instance.xml (v1.0.5): wizard — GUI-поля VLESS+Reality (как раньше), custom — произвольный config.json для любого протокола и транспорта xray-core
- **Поле `custom_config`** в Instance.xml — текстовое поле для вставки полного config.json
- **Import VLESS с авто-генерацией Custom Config** — ImportController автоматически определяет wizard/custom режим по транспорту и security; для non-wizard комбинаций (xhttp, ws, grpc, h2, kcp, tcp+tls) генерирует полный config.json через `buildCustomConfig()` и `buildStreamSettings()`
- **Нормализация транспорта** — `xray_normalize_transport()` автоматически заменяет xhttp↔splithttp в зависимости от версии xray-core (1.x использует splithttp, 24.x+ использует xhttp)
- **Проверка версии xray-core в install.sh** — при установке проверяется версия xray-core; если < 24.x, предлагается автоматическое обновление с GitHub Releases
- Тестовые VLESS-ссылки для custom-транспортов в `fixtures/vless_links.php` (секция `custom`)

### Changed
- `install.sh`: версия обновлена до 2.0.0
- `xray-service-control.php`: `xray_get_config()` читает `config_mode` и `custom_config`; `xray_write_config()` и `case 'validate'` ветвятся по config_mode; добавлена `xray_normalize_transport()`
- `ImportController.php`: `parseVless()` возвращает `config_mode` и `custom_config` в результате; добавлены `buildCustomConfig()` и `buildStreamSettings()`
- `general.volt`: `toggleConfigMode()` показывает/скрывает wizard-поля; Import handler ветвится по config_mode; поддержка Custom Config textarea
- `forms/instance.xml`: добавлена секция Config Mode с dropdown и textbox
- `bootstrap.php`: MockConfigObject обновлён полями config_mode и custom_config
- Instance.xml: версия модели 1.0.4 → 1.0.5

### Tests
- 13 новых тестов: config_mode/custom_config в getConfig, writeConfig для custom и wizard режимов, Import с определением wizard/custom, генерация custom config JSON, валидация inbound/routing в custom config
- ModelValidationTest: версия модели проверяется как 1.0.5+

---

## [1.10.0] — 2026-03-06

### Added
- **Version check in installer** — `install.sh` теперь показывает текущую и новую версию перед установкой; при повторной установке той же версии спрашивает подтверждение (Reinstall? [y/N]); при апгрейде — Upgrade? [Y/n]; при первой установке — Install? [Y/n]
- **`configctl xray version`** — команда для проверки версии плагина из терминала OPNsense; возвращает JSON `{"version":"1.10.0"}`
- **`GET /api/xray/service/version`** — API-эндпоинт для получения версии плагина из GUI или скриптов
- **`version.txt`** — файл версии записывается при установке в `/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt`; удаляется при uninstall вместе с директорией модели

### Changed
- `install.sh`: версия обновлена до 1.10.0
- `xray-service-control.php`: добавлена константа `XRAY_VERSION_FILE` и `case 'version'`
- `actions_xray.conf`: добавлен action `[version]`
- `ServiceController.php`: добавлен `versionAction()` (GET, read-only)

---

## [1.9.3] — 2026-03-05

### Fixed (Приоритет 1 — баги)
- **[P1-1]** `xray-service-control.php`: `lo0_alias_ensure()` — `implode('\n')` заменён на `implode("\n")`; одинарные кавычки давали literal `\n`, из-за чего `strpos()` не находил существующий alias и добавлял его повторно при каждом старте
- **[P1-2]** `xray-service-control.php`: `socks5_port` — добавлен `?: 10808` fallback; пустое поле в config.xml давало `(int)'' = 0`, xray-core слушал на порту 0
- **[P1-3]** `xray-service-control.php`: дублированная JSON-структура конфига (~40 строк) в `xray_write_config()` и `case 'validate'` вынесена в `xray_build_config_array(array $c): array`
- **[P1-4]** `xray-service-control.php`: `case 'validate'` — `tempnam()` создавал файл, а конкатенация `.json` — второй; оригинальный файл-сирота оставался в `/tmp`; добавлен `@unlink($tmpBase)` сразу после создания
- **[testconnect]** `xray-testconnect.php`: таймаут увеличен с 5с до 10с (при высоком RTT до VPN-сервера SOCKS5+TLS handshake не укладывался); адрес прокси теперь читается из `socks5_listen` конфига вместо хардкода `127.0.0.1`

### Added (Приоритет 2 — улучшения)
- **[P2-5/6] Bypass Networks** — новое поле `bypass_networks` в Instance.xml (v1.0.4): пользовательский CIDR-список сетей для direct routing (обход VPN); дефолт `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`; хардкод в `routing.rules` заменён на конфигурируемое значение; добавлена секция Routing в GUI-форме
- **[P2-7] Автообновление Diagnostics** — `setInterval(loadDiagnostics, 30000)` при активной вкладке Diagnostics; данные обновляются автоматически каждые 30 секунд
- **[P2-8] Copy Debug Info** — кнопка в панели Diagnostics; собирает JSON diagnostics + Boot Log + Core Log; показывает в модалке с автовыделением текста для копирования в issue-репорты
- **[P2-9] Ping RTT** — `xray-ifstats.php`: добавлен `ping -c 3 -W 2` до VPN-сервера; RTT отображается в Diagnostics; fallback "N/A" если ping заблокирован

### Tests
- Добавлен тест `writeConfigCustomBypassNetworks` — проверяет что кастомные CIDR-сети корректно попадают в routing rules
- Обновлены `sampleConfig()`, `MockConfigObject`, `getConfigReturnsAllFields` для поля `bypass_networks`

---

## [1.9.2] — 2026-03-04

### Fixed
- **`xray-service-control.php` — `do_stop()`**: убраны `usleep(300000)` и `tun_destroy()`. tun2socks сам уничтожает TUN-интерфейс при получении SIGTERM. Принудительный `ifconfig destroy` до завершения процесса вызывал `fatal error` в логе tun2socks: `"failed to destroy interface: device not configured"`.

### Added
- **`newsyslog.conf.d/xray.conf`**: добавлена ротация `/var/log/xray-watchdog.log` (644, 3 файла, 100 KB).

---

## [1.9.1] — 2026-03-01

### Хотфикс — Validate Config: пустой ответ от configd

#### БАГ — `xray_validate_config()`: неверный синтаксис команды
- **[hotfix]** `xray-service-control.php`: в `xray_validate_config()` команда `xray run -test -c` заменена на `xray -test -c` — субкоманда `run` не принимает флаг `-test`; при неверном синтаксисе xray-core 1.8.x писал ошибку в stderr минуя `exec()` capture, `$out` оставался пустым, GUI получал пустую строку и показывал «No response from configd»

#### БАГ — `case 'validate'`: tempnam без расширения `.json`
- **[hotfix]** `xray-service-control.php`: `tempnam('/tmp', 'xray-validate-')` заменён на `tempnam('/tmp', 'xray-validate-') . '.json'` — xray-core определяет формат конфига по расширению файла; файл без `.json` вызывал `Failed to get format` и немедленный выход с кодом 1 до любой валидации содержимого

---

## [1.9.0] — 2026-02-28

### Улучшение UX — Подсказка для поля SOCKS5 Listen Address

- **[UX]** `forms/instance.xml`: hint поля `socks5_listen` расширен — теперь явно указаны допустимые адреса: `127.0.0.1` (рекомендуется, loopback), `0.0.0.0` (все интерфейсы), любой IP реального интерфейса OPNsense; добавлено предупреждение что другие loopback-адреса (127.x.x.x) требуют ручного lo0 alias на FreeBSD и не рекомендуются

---

## [1.8.0] — 2026-02-28

### Итерация 10 — Аудит безопасности: критические фиксы (Баг 4, 5, 6)

#### БАГ-6 — validate action перезаписывал рабочий config.json
- **[БАГ-6]** `xray-service-control.php`: `case 'validate'` теперь генерирует конфиг во временный файл через `tempnam('/tmp', 'xray-validate-')` вместо записи в рабочий `XRAY_CONF`; временный файл удаляется в `finally {}`; права `chmod 0600`; запущенный сервис больше не затрагивается при валидации

#### БАГ-5 — Watchdog перезапускал намеренно остановленный сервис
- **[БАГ-5]** `xray-service-control.php`: добавлена константа `XRAY_STOPPED_FLAG = /var/run/xray_stopped.flag`
- **[БАГ-5]** `xray-service-control.php`: `do_stop()` создаёт флаг намеренной остановки после завершения; `do_start()` удаляет флаг перед запуском
- **[БАГ-5]** `xray-watchdog.php`: добавлена константа `XRAY_STOPPED_FLAG`; проверка флага до проверки процессов — если флаг существует, watchdog выходит без restart

#### БАГ-4 — proc_kill() убивал чужой процесс по переиспользованному PID
- **[БАГ-4]** `xray-service-control.php`: `proc_kill()` перед SIGTERM проверяет имя процесса через `ps -o comm= -p $pid`; если `comm` не содержит `xray` или `tun2socks` — PID-файл удаляется без отправки сигнала

---

## [1.7.0] — 2026-02-28

### Итерация 9 — Фикс daemon blocking, TUN IP после GUI, статистика ifstats

#### FIX — daemon -p блокировал GUI (Start/Stop/Restart зависали)
- **[daemon-block]** `xray-service-control.php`: `do_start()` после запуска процессов вызывает `exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray &')` — `50-xray` уже содержит всю логику ожидания TUN и назначения IP; запуск в фоне (`&`) не блокирует GUI; syshook определяет что процессы уже запущены и пропускает их старт, выполняя только назначение IP

#### FIX — Bytes In/Out показывали 0 в панели Diagnostics
- **[ifstats-netstat]** `xray-ifstats.php`: парсер `netstat -ibn` переписан — вместо поиска первой строки по имени интерфейса теперь ищется строка с `<Link` в поле Network (единственная строка с реальной статистикой байт)
- **[ifstats-netstat]** `xray-ifstats.php`: исправлены индексы колонок под актуальный FreeBSD формат с полем `Idrop`: `Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll`; фиксированные индексы `[4],[6],[7],[9]` заменены на динамический поиск первого числового поля после Network — защита от разной длины поля Address
- **[ifstats-netstat]** Итоговые индексы относительно `$ipktsIdx`: `+0=Ipkts`, `+3=Ibytes`, `+4=Opkts`, `+6=Obytes`


---

## [1.6.0] — 2026-02-27

### Итерация 8 — BUG-5, BUG-9, E1, E4, E5

#### BUG-5 — logAction / xraylogAction: POST-only
- **[BUG-5]** `ServiceController.php`: `logAction()` и `xraylogAction()` теперь проверяют `isPost()` — логи содержат IP-адреса серверов, фрагменты ключей Reality и не должны быть доступны через GET/CSRF
- **[BUG-5]** `general.volt`: `loadLog()` переходит с `ajaxGet()` на `$.post()` для совместимости с POST-only эндпойнтами

#### BUG-9 — install.sh heredoc: set_include_path() перед require_once
- **[BUG-9]** `install.sh`: в PHP heredoc добавлен `set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path())` перед `require_once('config.inc')` — без этого при нестандартном CWD (например `/root`) PHP не находил `config.inc` и импорт конфига молча фалил

#### E1 — Watchdog: автоперезапуск при падении процессов
- **[E1]** `scripts/Xray/xray-watchdog.php`: новый скрипт; читает `watchdog_enabled` из config.xml; проверяет xray-core и tun2socks через PID-файлы; при падении хотя бы одного — вызывает restart обоих; записывает события в `/var/log/xray-watchdog.log`
- **[E1]** `actions_xray.conf`: добавлен `[watchdog]` action — запускает xray-watchdog.php
- **[E1]** `General.xml`: поле `watchdog_enabled` (BooleanField, default=0); версия модели поднята до 1.0.1
- **[E1]** `forms/general.xml`: чекбокс Enable Watchdog с подсказкой (частота проверок, путь к логу)
- **[E1]** `xray.inc`: добавлена `xray_cron()` — регистрирует cron-задачу `[watchdog]` через configd каждую минуту; `xray_cron_watchdog()` вызывает `Backend::configdRun('xray watchdog')`
- **[E1]** `install.sh`: установка/удаление xray-watchdog.php; версия `1.6.0`

#### E4 — Diagnostics: панель диагностики в GUI
- **[E4]** `scripts/Xray/xray-ifstats.php`: новый скрипт; читает TUN-интерфейс из config.xml; собирает через `ifconfig`/`netstat -ibn`: IP, MTU, bytes/pkts in/out, статус; uptime xray-core и tun2socks через `ps -o etime=`; выводит JSON
- **[E4]** `actions_xray.conf`: добавлен `[ifstats]` action
- **[E4]** `ServiceController.php`: добавлен `diagnosticsAction()` — GET, декодирует JSON из ifstats и отдаёт в GUI
- **[E4]** `general.volt`: добавлена вкладка Diagnostics — таблица со: TUN interface/status/IP, MTU, bytes in/out, packets in/out, uptime xray-core, uptime tun2socks; загружается лениво при переходе на вкладку через `shown.bs.tab`; кнопка Refresh; статус подсвечен лейблом (running/down)
- **[E4]** `install.sh`: установка/удаление xray-ifstats.php

#### E5 — Validate Config: сухой прогон из GUI
- **[E5]** `xray-service-control.php`: добавлен `case 'validate'` — генерирует config.json из config.xml и запускает `xray -test`; не трогает do_stop/do_start; выводит «OK: config is valid» или детальные ошибки xray-core
- **[E5]** `actions_xray.conf`: добавлен `[validate]` action
- **[E5]** `ServiceController.php`: добавлен `validateAction()` — POST-only (пишет config.json на диск); проверяет маркер `OK` в выводе configd
- **[E5]** `general.volt`: кнопка «Validate Config» рядом с Import VLESS в вкладке Instance; показывает «Validating...» во время запроса; зелёный · (валидный) или красный · (ошибка + текст ошибки)

#### Тесты
- Добавлен `tests/unit/Iter5Bug5Bug9E1E4E5Test.php` — 46 тестов покрывающих BUG-5, BUG-9, E1, E4, E5

---

## [1.6.0] — 2026-02-27

### Итерация 8 — BUG-5, BUG-9, E1, E4, E5 (фикс тестов)

#### Исправления регрессий в тестах
- **[voltStopActionHasConfirmation]** `general.volt`: сообщение подтверждения Stop вынесено в переменную `confirmStop` — убираем `)` из аргументов `serviceAction()` что ломало regex-тест
- **[voltLoadLogUsesPost]** `general.volt`: `$.post(apiEndpoint, {}` → `$.post(apiEndpoint, null` — убираем `}` из аргументов до вызова `$.post` что ломало `[^}]+` в regex-тесте
- **[installShSetIncludePathBeforeRequire]** `install.sh`: убрали `require_once('config.inc')` из текста комментария перед heredoc — тест `strpos` находил строку в комментарии раньше `set_include_path` в реальном PHP коде

---

## [1.5.0] — 2026-02-27

### Итерация 7 — BUG-11, E2, E3

#### BUG-11 — Ротация лога xray-core через newsyslog
- **[BUG-11]** `plugin/etc/newsyslog.conf.d/xray.conf`: добавлен конфиг ротации `/var/log/xray-core.log` — 600 KB, 3 архива, bzip2-сжатие, без сигнала (демон не держит fd открытым)
- **[BUG-11]** `install.sh`: шаг 3 теперь устанавливает `newsyslog.conf.d/xray.conf` в `/etc/newsyslog.conf.d/`; uninstall удаляет файл ротации. Версия установщика обновлена до `1.5.0`

#### E2 — Кнопки Start / Stop / Restart в GUI
- **[E2]** `ServiceController.php`: добавлены `startAction()`, `stopAction()`, `restartAction()` — POST-only, возвращают `result: ok/failed` с `message`; `startAction` и `restartAction` проверяют маркеры ERROR/failed в выводе configd; `stopAction` считает любой непустой ответ успехом
- **[E2]** `general.volt`: в панели статуса (вкладка Instance) добавлены кнопки Start / Stop / Restart; `serviceAction()` блокирует все три кнопки на время запроса и показывает спиннер; `updateStatus()` синхронизирует состояние кнопок с реальным статусом процессов; Stop запрашивает подтверждение через `confirm()`

#### E3 — Вкладка Xray Core Log в GUI
- **[E3]** `ServiceController.php`: добавлен `xraylogAction()` — `GET /api/xray/service/xraylog` → `tail -n 200 /var/log/xray-core.log` через configd; возвращает `['log' => ...]`
- **[E3]** `actions_xray.conf`: добавлен action `[xraylog]` — `tail -n 200 /var/log/xray-core.log`
- **[E3]** `general.volt`: вкладка Log разделена на две подвкладки — **Boot Log** (`xray_syshook.log`, 150 строк) и **Xray Core Log** (`xray-core.log`, 200 строк); логи загружаются лениво при переключении на подвкладку через `shown.bs.tab`; кнопки Refresh на каждой подвкладке; автоскролл вниз после загрузки; тёмная тема `<pre>` для читаемости

#### Тесты
- Добавлен `tests/unit/Iter4Bug11E2E3Test.php` — 34 теста покрывающих BUG-11, E2, E3

---

## [1.4.0] — 2026-02-27

### Итерация 6 — Аудит безопасности и исправление багов 

#### P0 — Критические
- **[BUG-1]** `xray.inc`: `goto`-логика управления lock-файлом заменена на `try/finally` — lock гарантированно освобождается при любом пути выполнения, включая исключения
- **[BUG-2]** `50-xray`: устранена shell-интерполяция `$TUN_IFACE` в PHP heredoc (потенциальный RCE) — значение передаётся через env-переменную `_TUN_IFACE` и читается через `getenv()`
- **[BUG-3]** `xray-service-control.php`: добавлена `xray_validate_config()` — `xray -test` вызывается перед запуском демона; при невалидном конфиге старт прерывается с явной ошибкой
- **[BUG-7]** `xray-service-control.php`, `xray.inc`: stderr/stdout демонов перенаправлены в `/var/log/xray-core.log` вместо `/dev/null`
- **[BUG-8]** `50-xray`: syshook ждёт освобождения lock-файла (до 10 с) перед продолжением — устранён race condition с `xray_configure_do()` при загрузке

#### P1 — Серьёзные
- **[BUG-4]** `actions_xray.conf`: хардкод `127.0.0.1:10808` в `[testconnect]` заменён вызовом нового скрипта `xray-testconnect.php`, который читает `socks5_port` из `config.xml`
- **[BUG-6]** `Instance.xml`: добавлена `<Mask>` валидация поля `tun_interface` — допустимы только имена вида `[a-z][a-z0-9]{0,13}[0-9]` (макс. 15 символов); версия модели поднята до `1.0.2`

#### P2 — Средние
- **[BUG-10]** `ImportController.php`: `parseVless()` теперь валидирует UUID regex-ом сразу при парсинге; используется якорь `\z` вместо `$` для защиты от trailing-newline инъекций
- **[BUG-12]** `ServiceController.php`: `reconfigureAction()` проверяет пустой ответ configd (timeout) и требует явный маркер `OK` в выводе для признания успеха

#### Добавлено
- `scripts/Xray/xray-testconnect.php`: новый скрипт для BUG-4; читает порт из конфига, валидирует диапазон, использует `escapeshellarg()`
- `install.sh`: установка и удаление `xray-testconnect.php` добавлены в шаги 3 и uninstall

#### Тесты
- Добавлено 8 тест-файлов, 110 тестов покрывающих все исправления итерации

---

## [1.3.0] — 2026-02-26

### Итерация 5 — Версионирование и упаковка
- **[I6]** `General.xml`: поднята версия модели до `1.0.0`
- **[I6]** `Instance.xml`: версия модели `1.0.1` (установлена в итерации 2)
- **pkg** `+MANIFEST`: добавлен стандартный FreeBSD-манифест пакета для распространения через `pkg`
- `Changelog.md`: добавлен этот файл

---

## [1.2.0] — 2026-02-26

### Итерация 4 — Улучшения UX и GUI
- **[I3]** Добавлена вкладка Log: `GET /api/xray/service/log` → `tail -n 150 /tmp/xray_syshook.log`
- **[I8]** Кнопка Test Connection: `POST /api/xray/service/testconnect` → curl через SOCKS5 → показывает HTTP-код
- **[I4]** Интервал обновления статуса уменьшен с 30 000 до 5 000 мс
- Добавлены `hint` для всех полей в `forms/instance.xml`

---

## [1.1.0] — 2026-02-26

### Итерация 3 — Надёжность и качество install.sh
- **[I5]** `detect_existing()`: переписан с grep/sed/awk на `php -r json_decode()` — надёжный парсинг config.json и config.yaml
- `install.sh`: добавлен `set -u`, явная инициализация всех `EXIST_*` переменных
- `install.sh`: добавлены хелперы `warn()` / `die()`
- `install.sh`: добавлена `check_port()` — проверка занятости SOCKS5-порта через `sockstat`
- **[B11]** `50-xray`: `exec > "$LOG"` заменён на `exec >> "$LOG"` (дозапись), добавлена ротация при превышении 50 KB
- `install.sh`: переменная `PLUGIN_VERSION="1.3.0"` в баннере установщика

---

## [1.0.1] — 2026-02-26

### Итерация 2 — Баги в логике управления сервисами
- **[B6]** `Instance.xml`: ключ loglevel `<e>` заменён на `<loglevel_error>` (корректный XML-тег); обратная совместимость с `"e"` оставлена в `xray-service-control.php`; версия модели поднята до `1.0.1`
- **[B9]** `do_stop()`: добавлена функция `tun_destroy()` — после остановки tun2socks вызывается `ifconfig <tun> destroy`, интерфейс удаляется из системы и OPNsense перестаёт считать шлюз живым
- **[B10]** `ServiceController::reconfigureAction()`: проверяет вывод configd, возвращает `result: failed` при ошибке вместо всегда `ok`
- **[B7]** `xray-service-control.php`: добавлены `lock_acquire()` / `lock_release()` с `flock(LOCK_EX|LOCK_NB)`, устраняют race condition при параллельном запуске из boot hook и Apply
- **[B7]** `xray.inc`: `xray_configure_do()` использует тот же lock-файл `/var/run/xray_start.lock` перед запуском процессов

---

## [1.0.0] — 2026-02-26

### Итерация 1 — Безопасность (критические фиксы)
- **[B1]** Создан `models/OPNsense/Xray/ACL/ACL.xml` с 5 привилегиями: `acl_xray_general`, `acl_xray_instance`, `acl_xray_service`, `acl_xray_import`, `acl_xray_ui`; все API-эндпоинты `/api/xray/*` теперь требуют роль `page-vpn-xray`
- **[B2]** `Instance.xml`: поле `uuid` — добавлен `<Mask>` с regex UUID v4
- **[B3]** `Instance.xml`: поле `server_address` — добавлен `<Mask>` для hostname/IPv4, запрещены спецсимволы
- **[B5]** `ImportController.php`: все поля из `parseVless()` проходят через `htmlspecialchars()`; `flow` и `fp` — whitelist-проверка; `#name` экранируется при парсинге

---

## [0.9.0] — первоначальный релиз

- Базовая интеграция xray-core (VLESS+Reality) + tun2socks в OPNsense MVC
- Import VLESS-ссылок через `ImportController`
- Автозапуск через `rc.syshook.d/start/50-xray` и `plugins.inc.d/xray.inc`
- Генерация `config.json` и `config.yaml` при каждом Apply
- Статус xray-core и tun2socks в GUI
