**English** | [Русский](README.ru.md)

# os-xray

[![Release](https://img.shields.io/github/v/release/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/releases)
[![License](https://img.shields.io/github/license/MrTheory/os-xray)](https://github.com/MrTheory/os-xray/blob/main/LICENSE)
[![Downloads](https://img.shields.io/github/downloads/MrTheory/os-xray/total)](https://github.com/MrTheory/os-xray/releases)
[![OPNsense](https://img.shields.io/badge/OPNsense-25.x%20%2F%2026.x-blue)](https://opnsense.org)
[![FreeBSD](https://img.shields.io/badge/FreeBSD-14.x%20amd64-red)](https://freebsd.org)

**Xray-core VPN plugin for OPNsense** — v4.0.0 (fork of [MrTheory/os-xray](https://github.com/MrTheory/os-xray), see [what differs](#fork-what-differs-from-upstream))

Native Xray-core VPN for OPNsense: the **built-in TUN inbound** intercepts traffic (no tun2socks needed), the **xray routing engine** decides what goes where (domain/IP/port/GeoIP/GeoSite rules), **balancer + observatory** (leastPing / roundRobin / leastLoad / random) pick a healthy exit node, and a **server-side inbound** — VLESS / VMess / Trojan / Shadowsocks (fronted by nginx if you like) — turns the box into an entry point, with per-client QR share links. The full `config.json` can be edited as Raw JSON with syntax highlighting and `xray -test` validation. VLESS+Reality via wizard or custom config — any protocol/transport. Bypasses DPI blocking by disguising traffic as legitimate TLS.

---

## Fork: what differs from upstream

This is a fork of [MrTheory/os-xray](https://github.com/MrTheory/os-xray) (branch `develop`). Upstream functionality keeps working; the fork has grown in two big steps.

**v4.0.0 — native Xray mechanisms (no external daemons).** The whole config is now produced by a single builder and drives xray-core features directly:

- **Native TUN inbound** (`"protocol": "tun"`) — xray-core creates the interface itself (verified against `proxy/tun/tun_freebsd.go` in Xray-core 26.10+); tun2socks remains as a per-instance legacy fallback (`tun_driver`). New instance default: interface `tun100`, gateway `10.255.0.1/30`.
- **xray routing engine** — new model `OPNsense\Xray\RoutingRule` (`//OPNsense/xray/routing_rules`), a **Routing** tab and `api/xray/rule/*`: rules by domain / ip / port / sourcePort / network / inboundTag / **geoip** / **geosite** → proxy / balancer / direct / block. `install.sh` downloads `geoip.dat`/`geosite.dat`; the old `bypass_networks` migrates into a rule.
- **Balancer + observatory** — `lb_enabled` with leastPing / roundRobin / leastLoad / random strategies (all confirmed in `app/router/config.go`), members = all servers of the selected group or a custom outbound list; the builder emits `routing.balancers` + `burstObservatory` + a catch-all rule; `fallbackTag` = first node, so a dead node fails the connection instead of leaking traffic directly.
- **Server-side inbound** — VLESS/VMess/Trojan/Shadowsocks (incl. SS-2022), transports WebSocket/XHTTP/gRPC/H2/HTTPUpgrade/TCP, security none (nginx TLS)/TLS/REALITY; protocol-aware user generator and a **per-client QR + share link** modal; nginx snippet generator (auto-selects the tab for your transport)
- **Raw JSON mode** — `node_source` extended to `config | group | none | raw`: the full `config.json` in a CodeMirror editor with **Validate Config** (draft, via `xray -test`), **Format** and **Preview Generated Config** buttons.
- **ConfigBuilder** (`mvc/app/library/OPNsense/Xray/ConfigBuilder.php`) — the single source of config generation used by start/reconfigure/validate/preview; config assembly is no longer duplicated in the service script. Strict config errors (port clashes, `tunN` naming, balancer action without LB, …).
- **Full tunnel option** — `tun_auto_routing = full` makes xray manage system routes itself (`autoSystemRoutingTable`, escape FIB via `autoOutboundsInterface`, optional `autoSystemDnsToGateway`); requires `net.fibs=2`, shown in Diagnostics.
- **DNS hijack** — `dns_mode = hijack` answers port-53 traffic from the tunnel with xray's built-in DNS (`dns-out` outbound + routing rule). Note: Xray-core has **no FakeIP** (verified in `infra/conf/dns.go`) — domains for TUN clients come from sniffing instead.

**v3.2 — server groups with subscription import.** An instance can point at a **group** and one **active server** in it; changing the exit node is a dropdown plus Apply. A group created from a subscription URL gets a **Refresh** button that re-fetches the list on demand. The `vless://` parser lives in a shared `OPNsense\Xray\VlessLink` class.

**Fixed along the way** (selected): duplicate instance created when installing on top of a working 3.x; custom row-command formatter ignored on OPNsense 26.7 unless named `commands`; `requestHandler` only read from `options`; xray-core/tun2socks logs vanishing after the first rotation (daemons now run under `daemon -H -o` with SIGHUP reopen); tun2socks 2.7 needing `--config`.

**Compatibility.** Existing configurations keep working untouched: migrated instances keep the `tun2socks` driver and their interface name, an empty `node_source` with a selected server still means `group`, and `outbound_config` stays a first-class way to define a node. Switching an instance to the native TUN driver is a deliberate action in the dialog (the interface must be renamed to `tunN`). Going back to upstream means removing `//OPNsense/xray/groups`, `//OPNsense/xray/routing_rules` and the new instance fields from `config.xml` by hand.


## Features

- **Native Xray TUN inbound** — the TUN interface is created by xray-core itself (`"protocol": "tun"`), no external daemons; tun2socks remains as a per-instance legacy fallback
- **xray routing engine** — per-instance rules by domain / IP / port / sourcePort / network / inboundTag / **GeoIP** / **GeoSite** (dat files installed automatically), with `Preview Generated Config` showing the result
- **Balancing + health checks** — balancer with leastPing / roundRobin / leastLoad / random strategies and a background `burstObservatory` probing every member; all servers of a group or a custom outbound list become members
- **Server-side inbound** — accept incoming proxy connections: VLESS/VMess/Trojan/SS over WebSocket/XHTTP/gRPC/H2/HTTPUpgrade/TCP with none/TLS/REALITY; QR + share links per client; extra inbounds via a JSON list
- **Raw JSON mode** — the full `config.json` in a CodeMirror editor with syntax highlighting, **Validate Config** (draft, via `xray -test`) and **Format** buttons; wizard mode generates everything from GUI fields
- **Config Source switch** — group / own JSON / server-only / raw full config; the dialog shows only the fields of the chosen mode
- **Server groups + subscription import** — import a list of `vless://` links as a group, then pick the active node from a dropdown; **Refresh** re-fetches the list on demand
- **Full tunnel or selective** — xray can manage the system routes itself (`autoSystemRoutingTable`, needs `net.fibs=2`) or leave the choice to OPNsense firewall rules and the TUN gateway
- **DNS hijack** — DNS from the tunnel answered by Xray's built-in DNS (leak-free full tunnel); optional `autoSystemDnsToGateway` for the router's own resolver
- **Multi-instance support** — add, edit and delete multiple VPN instances from a bootgrid table
- **Per-instance status badges** — each instance row shows xray/tun status, auto-refreshed every 5 seconds
- **Import VLESS link inside dialog** — collapsible Import panel at the top of the instance edit dialog
- Full VLESS+Reality parameter support (UUID, flow, SNI, PublicKey, ShortID, Fingerprint)
- Tunnel management via GUI: **VPN → Xray**
- Auto-detects and imports existing xray-core and tun2socks configs during installation
- **Start / Stop / Restart buttons** — manage the service directly from GUI without page reload
- **Validate Config button** — dry-run config through `xray -test` without stopping the service
- **Test Connection button** — verifies that xray-core is actually proxying traffic
- **Log tab** — Boot Log and Xray Core Log directly in GUI
- **Diagnostics tab** — TUN interface stats: IP, MTU, bytes, packets, uptime, Ping RTT; plus environment: xray version, geo data files, `net.fibs`, listening ports
- **Copy Debug Info button** — collects diagnostics + logs into a modal for easy copy to issue reports
- **Watchdog** — automatic restart on xray-core (or legacy tun2socks) crash (configurable)
- **Auto-start after reboot** — interface comes up automatically, no need to press Apply manually
- ACL permissions — GUI and API access only for authorized users with `page-vpn-xray` role

---

## Stack

```
                    ┌────────────────────────── xray-core (single process) ──────────────────────────┐
                    │ inbounds: tun-in (protocol "tun") · socks-in · vless-in (server mode, optional)│
                    │ routing:  rules (domain/ip/port/geoip/geosite) → balancer lb / direct / block    │
                    │ outbounds: proxy-1..N (group servers) · balancer+observatory · freedom · dns     │
                    └────────────────────────────────────────────────────────────────────────────────┘
                                           ↓ TUN interface (tun100, tunN on FreeBSD)
OPNsense Gateway PROXYTUN_GW (selective)  ·  or xray-managed system routes (full tunnel)
                                           ↓
Firewall Rules (selective routing)
```

Legacy fallback per instance: `tun2socks → TUN → SOCKS5 → xray-core` (v3.x behaviour).

---

## System Requirements

| Component  | Version                 |
|------------|-------------------------|
| OPNsense   | 25.x / 26.x            |
| FreeBSD    | 14.x amd64             |
| xray-core  | **26.10+** (native TUN; installer upgrades automatically) |
| tun2socks  | Optional — legacy TUN driver only |
| net.fibs   | ≥ 2 — only for the Full tunnel mode (`/boot/loader.conf`, reboot) |

---

## Installation

**Option 1 — via git clone (recommended)**

```sh
cd /tmp
git clone https://github.com/MrTheory/os-xray.git
cd os-xray
sh install.sh
```

**Option 2 — via archive**

```sh
fetch -o /tmp/os-xray-v5.tar https://raw.githubusercontent.com/MrTheory/os-xray/refs/heads/main/os-xray-v5.tar
cd /tmp && tar xf os-xray-v5.tar && cd os-xray-v5
sh install.sh
```

The installer automatically:

- Shows current and new plugin version and asks for confirmation
- Checks xray-core version — if below **26.10** (native TUN inbound on FreeBSD), offers automatic upgrade to the latest release
- Downloads **geoip.dat** and **geosite.dat** into `/usr/local/etc/xray-core/` (for GeoIP/GeoSite routing rules)
- Installs web assets (CodeMirror editor) into `/usr/local/opnsense/www/js/xray/`
- Migrates v3.x instances: legacy `bypass_networks` becomes a routing rule, TUN driver preserved as `tun2socks`
- Checks if the SOCKS5 port (default 10808) is already in use
- Finds existing configs and imports them into OPNsense (GUI fields are pre-filled)
- Copies all plugin files, restarts configd, clears caches
- Installs boot script for auto-start after reboot

Check installed version:
```sh
configctl xray version
```

---

## GUI Setup

Refresh browser (`Ctrl+F5`) → **VPN → Xray**

1. **Instances** tab → click **+** to open the instance dialog
   - **Config Source** — where the config comes from:
     - *Xray config (JSON)* — expand the **Import VLESS link** panel, paste link → **Parse & Fill** (or paste an outbound JSON yourself); the backend wraps it with TUN/SOCKS/routing sections
     - *Server group* — pick a group and the active server (or enable Load Balancing to use all of them)
     - *Server only* — no upstream: pure incoming proxy (see Server Inbound below)
     - *Custom full config (Raw JSON)* — the entire `config.json` in a highlighted editor; **Validate Config** checks the draft via `xray -test`, **Format** pretty-prints it
   - **TUN Driver** — `Xray TUN inbound (native)` (default for new instances; interface must be `tunN` on FreeBSD), `tun2socks (legacy)`, or `Off (no TUN)` — no interface and no traffic capture: the instance only serves its inbounds (SOCKS5 / server inbound for incoming clients / custom inbounds), e.g. a pure entry-point box. At least one inbound must stay enabled
   - **Preview Generated Config** (footer, wizard modes) — shows exactly what will be written to `/usr/local/etc/xray-core/`
2. **Routing** tab → add rules (per instance): match by domain / ip / port / sourcePort / network / inboundTag / **geoip** / **geosite** → action proxy / balancer / direct / block
3. **General** tab → check **Enable Xray** (and **Enable Watchdog** if desired)
4. Press **Apply**
5. **Test Connection** button — verify the tunnel is working (shows HTTP 200)

---

## Interface and Gateway (selective routing)

With the native TUN driver xray creates the interface itself and assigns the address **right after the gateway** (`10.255.0.1/30` → interface gets `10.255.0.2/30`); the gateway belongs to nothing and is routed into the TUN.

| Step | GUI Path | Value |
|------|----------|-------|
| Assign interface | Interfaces → Assignments | + Add: **tun100** (must be `tunN` on FreeBSD) |
| Enable and configure | Interfaces → \<name\> | Enable ✓, IPv4: Static, IP: `10.255.0.2/30` (= gateway + 1) |
| **Prevent removal** | Interfaces → \<name\> | **Prevent interface removal ✓** |
| Create gateway | System → Gateways → Add | Gateway IP: `10.255.0.1`, Far Gateway ✓, Monitoring off ✓ |

> **Important:** the **Prevent interface removal** checkbox is mandatory — without it, OPNsense may remove the interface from config on reboot if xray hasn't created it yet. The static IP must match what xray assigns (gateway + 1 with the same mask), otherwise OPNsense re-applies a different address.

> **Full tunnel mode:** with `System Routing = Full tunnel` none of this is needed — xray installs the system routes itself. It requires `net.fibs=2` in `/boot/loader.conf` and a reboot (OPNsense keeps xray's own connections out of the TUN via a second routing table). Check the value on the **Diagnostics** tab.

---

## Selective Routing (OPNsense level)

- **Firewall → Aliases** — create a list of IPs/networks/domains for VPN routing
- **Firewall → Rules → LAN** — add rule: Source = LAN net, Destination = alias, Gateway = PROXYTUN_GW

MSS Clamping is not required for Xray (unlike WireGuard).

---

## Routing Rules (xray engine)

The **Routing** tab holds rules compiled into `routing.rules` of the generated config, applied **top to bottom** — drag the ⋮⋮ handle to reorder (the order in the grid is the order in the config; after a drop, Apply rebuilds the running configs). Leave *Instance* empty to make a **common rule** that applies to every instance — filter the grid by an instance to see its own rules plus the common ones. The DNS hijack rule is prepended automatically, private networks → direct appended after them, so local traffic never loops into the proxy:

| Match | Value examples | Notes |
|-------|----------------|-------|
| domain | `google.com, full:example.org, keyword:cdn, regexp:.*\.foo\..*` | v2ray domain patterns |
| ip | `203.0.113.0/24, 10.8.0.1` | CIDR / hosts |
| port | `80, 443, 1000-2000` | destination port |
| sourcePort | `60000-61000` | source port |
| network | `tcp`, `udp`, `tcp,udp` | |
| inboundTag | `tun-in`, `socks-in`, `vless-in` | e.g. route incoming clients differently |
| geoip | `private, cn, ru` | `geoip:` prefix added automatically; geoip.dat |
| geosite | `category-ads-all, google` | `geosite:` prefix added automatically; geosite.dat |
| protocol | `quic, bittorrent, http, tls` | matches the sniffed protocol |

Actions: `proxy` (upstream or balancer), `balancer`, `direct` (freedom), `block` (blackhole).

**Reordering (v4.3.0)** — the drag handle in the first column moves a rule anywhere in the list; the new order is saved immediately (splice semantics: the visible block lands where its earliest rule was, hidden rules keep their relative order). Works while an instance filter is active too.

**Common rules (v4.3.0)** — a rule with an empty *Instance* applies to **all** instances (shown as `(all)`); instance-specific and common rules interleave in one shared order, so you decide whether a common block fires before or after an instance rule.

**AND conditions (v4.2.0)** — every rule can additionally carry *Network* (tcp / udp / tcp,udp) and *Source CIDR* (comma-separated); they are embedded into the SAME xray rule, e.g. `geosite: category-ru` + `network: tcp,udp` + `direct` compiles one-to-one into a hand-written `{"domain":["geosite:category-ru"],"network":"tcp,udp","outboundTag":"direct"}` rule.

**Routing Domain Strategy (v4.2.0)** — per instance in the dialog (Routing & Load Balancing section): `AsIs`, `IPIfNonMatch` (default — same as before), `IPOnDemand`.

Example: block ads everywhere and let clients reach corporate subnets directly:

| Match | Value | Action |
|-------|-------|--------|
| geosite | category-ads-all | block |
| geoip | private | direct |

### UI walkthrough — recreating a hand-written config

This raw JSON (a real working setup: two upstream VLESS nodes, leastLoad balancer, RU split, ad/quic blocking):

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

is built through the UI like this:

| JSON piece | UI |
|------------|----|
| the two upstream nodes | Groups tab: create a group, add both servers (or paste each link with **Import** in the instance dialog) |
| observatory: probeUrl / probeInterval | instance dialog → Routing & Load Balancing: **Enable Balancer** ✓, strategy `leastLoad`, Probe URL / Probe Interval. `subjectSelector`/`enableConcurrency` are generated automatically (all group outbounds; burstObservatory probes concurrently) |
| balancers: selector / fallbackTag | generated automatically: selector = all group outbounds, fallbackTag = first node (or block when dead) |
| domainStrategy | instance dialog → Routing & Load Balancing → **Routing Domain Strategy** |
| rule `{"protocol":["quick"],"outboundTag":"block"}` | Routing → + → Match **protocol**, Value `quic`, Action **block** |
| ads block | Match **geosite**, Value `category-ads-all`, Action **block** |
| "Direct domains" | Match **geosite**, Value `category-ru`, **Network (AND)** `tcp,udp`, Action **direct** |
| "Direct IPs" | Match **geoip**, Value `ru`, **Network (AND)** `tcp,udp`, Action **direct** |
| "Balanced out via tunnel" | Match **network**, Value `tcp,udp`, Action **balancer** (also generated automatically as the catch-all when LB is on) |
| TUN inbound | instance dialog → TUN Driver `Xray TUN inbound (native)`, interface `tun5` (or `xraytun0` to assign it in OPNsense), gateway, MTU |

Verify with **Preview Generated Config** — the result matches the hand-written JSON (plus the automatic DNS-hijack and private-networks rules).

---

## Load Balancing

Enable **Load Balancing** in the instance dialog (Config Source = Server group uses **all servers of the group**; with own JSON use the *Custom Outbound List* field). The generated config gets:

- `outbounds`: `proxy-1..N` (one per server) + balancer `lb` with the chosen strategy — **leastPing**, **roundRobin**, **leastLoad** or **random**
- `burstObservatory`: background health checks of every member (`probeURL`, `probeInterval`)
- a catch-all rule sending unmatched traffic through the balancer; `fallbackTag` = first node (a dead node fails the connection instead of leaking traffic directly)

A node that disappears from the subscription stays in the group flagged `stale`; the balancer drops it once probes fail.

---

## Raw JSON mode

**Config Source = Custom full config (Raw JSON)** writes your `config.json` verbatim:

- CodeMirror editor with JSON syntax highlighting
- **Comments are allowed** — xray style: `//`, `#` and block comments (the plugin strips them the same way xray's own reader does, so a config accepted by `xray -test` imports as-is)
- **Validate Config** — the draft is checked by `xray -test` on the router (not the saved config!); GeoIP/GeoSite rules are checked against the dat files installed by `install.sh`
- **Format** — pretty-print
- **No contradictory GUI fields**: in this mode the TUN/SOCKS/DNS/LB/server sections of the dialog are hidden, and the runtime parameters are derived from the JSON itself — the TUN interface name and MTU from the `tun` inbound, the SOCKS address/port (Test button, legacy tun2socks driver) from the `socks`/`mixed` inbound
- **Validate Config** also warns about traps that `xray -test` cannot catch (it does not start inbounds): a `listen` address that is not assigned to any router interface (xray would die at start with "bind: can't assign requested address" — typical for configs copied from another machine), or a tun interface name that is not `tunN` (macOS-style `utunN`)
- Notes: with the tun2socks legacy driver the config must contain a SOCKS/mixed inbound; the plugin's routing rules / balancer sections do not apply (they are wizard-only). On FreeBSD a native `tun` inbound needs `"name": "tunN"` (utunN is macOS-only) and the current `gateway` schema — the old `address` field is ignored

---

## Server Inbound (incoming proxy behind nginx)

The engine can also accept connections — the box becomes a proxy entry point. Enable **Server Inbound** in the instance dialog:

1. **Protocol** — `VLESS` (recommended: flow/REALITY support), `VMess`, `Trojan` or `Shadowsocks` (incl. 2022-blake3 with a server key)
2. **Users** — click **Add user**: it generates the right credential for the selected protocol (UUID for VLESS/VMess, random password for Trojan/Shadowsocks, base64 key for SS-2022). Each user gets a **QR** button: a modal with the import link (`vless://`, `vmess://`, `trojan://`, `ss://`) and a QR code to scan with v2rayNG/Nekoray/Streisand — built from the current dialog state, no save needed
3. **Transport** — `WebSocket`, `XHTTP`, `gRPC`, `HTTP/2`, `HTTPUpgrade` (all behind nginx) or `TCP` (direct); set path/service name and Host header
4. **Security** — `None` (TLS terminates on nginx), `TLS` (own certificate files) or `Reality` (direct exposure; TCP/gRPC/XHTTP)
5. **Public Host / Public Port (links/QR)** — what clients connect to (your domain + 443 behind nginx; WAN IP + Listen Port when direct). Used by the QR/link generator
6. Click **nginx snippet** — a ready configuration for the current settings (WS / XHTTP / gRPC / stream tabs, auto-selected for your transport) to paste into the nginx plugin (Services → Nginx) or a custom include
7. Open the public port in **Firewall → Rules** (WAN) if exposing directly
8. **Incoming Traffic Default** — where clients' traffic goes when no rule matches: `direct`, `proxy` (chain through the upstream) or `balancer`; route per-domain with a rule on `inboundTag: vless-in` (the tag is historical — it is used for every protocol)

Example client link (VLESS behind nginx, nginx terminates TLS on 443):
`vless://<uuid>@<your-host>:443?security=tls&sni=<your-host>&fp=chrome&type=xhttp&path=/vless#my-opnsense`

The QR generator derives the Reality public key from the private one server-side (libsodium x25519), so Reality links carry a ready `pbk=`.

---

## Outbound NAT (required!)

Without this, traffic through the tunnel won't be NATed and won't get past the VPN server.

**Firewall → NAT → Outbound**

1. Switch mode to **Hybrid outbound NAT rule generation** (if not already)
2. Add rule **+**:

| Field | Value |
|-------|-------|
| Interface | PROXYTUN (tun100 / your TUN name) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

> **Why:** OPNsense only NATs traffic through WAN by default. Traffic going through the TUN interface doesn't match automatic NAT rules. Without a manual rule, packets leave with the original LAN address (e.g. 192.168.1.x) and the VPN server drops them.

---

## Auto-start After Reboot

After reboot, the TUN interface comes up automatically — xray starts (creating the interface with the native driver, or together with tun2socks on the legacy driver), the interface gets an IP, firewall rules are reloaded. No need to press Apply manually.

Works through two mechanisms with double-start protection (flock):
- **`xray_configure_do()`** — boot hook (priority 10), delegates to `xray-service-control.php start` (config build + `xray -test` + driver-aware start)
- **`/usr/local/etc/rc.syshook.d/start/50-xray`** — final script, waits for the interface and applies routing/firewall when OPNsense is fully loaded

Log saved to `/tmp/xray_syshook.log` (append mode, rotated at 50 KB).

---

## Watchdog

When **Enable Watchdog** is on, cron checks xray-core every minute (and tun2socks for legacy-driver instances). If a process crashes, the instance is restarted automatically. Events are logged to `/var/log/xray-watchdog.log` (rotation: 3 files, 100 KB each).

Watchdog does not restart the service if it was stopped manually via the **Stop** button or **Apply** with Enable unchecked.

---

## Stopping the Service

When stopping (`Stop` in GUI or `Apply` with Enable unchecked):
1. Stops tun2socks (legacy driver) — it destroys the TUN interface on exit
2. Stops xray-core — the native TUN driver destroys its interface on shutdown (and the plugin force-destroys it if the process was killed uncleanly)
3. Sets intentional stop flag — watchdog won't restart the service

---

## Uninstall

```sh
cd /tmp/os-xray-v5
sh install.sh uninstall
```

---

## Troubleshooting

### Step-by-Step Diagnostics

Run these commands in order. Each step narrows down the problem. `<uuid>` below is the instance UUID (see `configctl xray statusall`, or the Diagnostics tab).

```sh
# Step 1 — Plugin and binaries
configctl xray version
ls -la /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks   # tun2socks optional (legacy driver)
/usr/local/bin/xray-core version          # xray-core version (26.10+ for the native TUN driver)

# Step 2 — Service status
configctl xray statusall                   # JSON per instance: xray_core + tun (or tun2socks) + driver
ps aux | grep -E 'xray|tun2socks'          # actual processes

# Step 3 — Config validation / preview
configctl xray validate <uuid>             # dry-run without restart
configctl xray generate <uuid>             # print the generated config.json
# or directly:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json

# Step 4 — Network
ifconfig tun100                            # TUN interface (xray driver): UP + inet 10.255.0.2?
netstat -rn | grep tun                     # routing table entries?
curl --socks5 127.0.0.1:10808 -s -o /dev/null -w "%{http_code}" https://1.1.1.1 --max-time 10
# 200 = tunnel works, 000 = no connection

# Step 5 — Firewall (selective mode)
pfctl -sr | grep tun                       # firewall rules for TUN?
pfctl -sn | grep tun                       # NAT rules for TUN?

# Step 6 — Logs (most recent errors)
tail -30 /var/log/xray-core-<uuid>.log
cat /tmp/xray_syshook.log
tail -20 /var/log/xray-watchdog.log

# Step 7 — Configs and geo data
ls -la /usr/local/etc/xray-core/           # config-<uuid>.json, geoip.dat, geosite.dat
cat /usr/local/tun2socks/config-<uuid>.yaml # legacy driver only
```

---

### Error Reference — All Plugin Error Messages

#### Start/Stop Errors

| Error Message | Cause | Solution |
|---|---|---|
| `xray-core not found at /usr/local/bin/xray-core` | Binary missing | Install xray-core (see Manual Binary Installation below) |
| `tun2socks not found … switch the instance TUN driver to "Xray TUN inbound (native)"` | Legacy driver selected but tun2socks missing | Install tun2socks **or** switch the instance TUN driver to the native one |
| `Xray is disabled or instance not found` | Enable not checked | GUI → General → Enable Xray ✓ → Apply |
| `Another start is already in progress (lock held)` | Parallel start, per-instance lock held | Wait 30s. If persists: `rm -f /var/run/xray_start_<uuid>.lock` |
| `ERROR: Failed to start Xray services` | xray-core (or legacy tun2socks) crashed on start | Check `/var/log/xray-core-<uuid>.log` for details |
| `No instances configured` | No instance created | Instances tab → **+** → fill fields → Apply |

#### Config Validation Errors (ConfigBuilder)

| Error Message | Cause | Solution |
|---|---|---|
| `ERROR: config validation failed` + xray output | Invalid xray-core config | See xray output below the error for specifics |
| `full config is empty` (raw mode) | Raw JSON mode with an empty editor | Paste the config.json, press Validate Config |
| `full config is not valid JSON` | Malformed JSON in the raw editor | Press **Format** or check commas/brackets/quotes |
| `outbound_config is empty` | Config Source = JSON without outbound | Import VLESS link or paste an outbound JSON |
| `the selected server group has no servers` / `no server is selected` | Group mode misconfigured | Pick a group with servers (or the active server) |
| `FreeBSD requires the TUN interface name to match "tunN"` | xray TUN driver with a non-`tunN` name | Rename the interface, e.g. `tun100` |
| `SOCKS5 port and server inbound port must differ` | Port clash | Change one of the ports |
| `action "balancer" requires Load Balancing enabled…` | Rule uses the balancer action without LB | Enable Load Balancing or change the rule action |
| `no upstream configured … but a rule uses action "proxy"` | Server-only instance with a proxy rule | Change the rule to direct/block or set a Config Source with a node |
| `Cannot create temp file for validation` | /tmp full or permission issue | `df /tmp` to check disk space |

#### xray-core Validation Errors (from `xray -test`)

| xray-core Output | Cause | Solution |
|---|---|---|
| `invalid user id` | Malformed UUID | Check UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |
| `unknown transport protocol: xhttp` | Very old xray-core build | Upgrade xray-core to 26.10+ (v4.0.0 no longer normalizes xhttp→splithttp) |
| `REALITY only supports TCP, H2, gRPC and DomainSocket` | Unsupported transport+security combo | Change transport/security (Reality server inbound in the wizard requires TCP) |
| `failed to dial` | VPN server unreachable | Check server address and port, test with `ping` or `nc -zv host port` |
| `address already in use` | SOCKS5/server port occupied | `sockstat -4l \| grep 10808` — change port in GUI or stop conflicting process |
| `interface already exists` | TUN name taken (xray driver) | Pick another free `tunN` for this instance |
| `automatic outbound interface on FreeBSD needs a second routing table…` | Full tunnel without `net.fibs=2` | Add `net.fibs=2` to `/boot/loader.conf` and reboot |

#### Network Warnings

| Warning | Cause | Impact |
|---|---|---|
| `WARNING: Cannot read lo0 interface` | lo0 interface issue (very rare) | SOCKS5 listen on 127.0.0.1 may not work |
| `WARNING: Failed to add lo0 alias` | lo0 alias already exists or permission issue | Usually harmless — alias may already be set |

---

### VPN → Xray Menu Not Showing

```sh
# Clear OPNsense menu cache
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Then Ctrl+F5 in browser

# If still missing, verify plugin files exist:
ls /usr/local/opnsense/mvc/app/models/OPNsense/Xray/Menu/Menu.xml
ls /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray/IndexController.php
```

---

### Tunnel Won't Start

```sh
# Run start manually to see full output:
/usr/local/opnsense/scripts/Xray/xray-service-control.php start

# If no output, check if enabled:
configctl xray status

# If "disabled in config":
# GUI → General → Enable Xray ✓ → Apply

# Validate config separately:
configctl xray validate
# or directly:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json
```

---

### Service "stopped" After Reboot

Xray auto-starts on boot if `General → Enable Xray` is checked. If it doesn't:

```sh
# 1. Check boot log
cat /tmp/xray_syshook.log

# 2. Check boot scripts exist and are executable
ls -la /usr/local/etc/rc.syshook.d/start/50-xray
ls -la /usr/local/etc/inc/plugins.inc.d/xray.inc

# 3. Check if stopped flag is set (prevents auto-start)
ls -la /var/run/xray_stopped_*.flag
# If present, remove it:
rm -f /var/run/xray_stopped_<uuid>.flag

# 4. Start manually
configctl xray start
```

---

### Apply / Start / Stop Not Working ("No response from configd")

```sh
# 1. Check configd is running
service configd status

# 2. If not running, start it
service configd start

# 3. Check for stuck lock file
ls -la /var/run/xray_start_*.lock
# If older than 1 minute:
rm -f /var/run/xray_start_<uuid>.lock

# 4. Check for stuck PHP process
ps aux | grep xray-service-control
# Kill if older than 2 minutes:
kill <PID>

# 5. Restart configd and retry
service configd restart
# Wait 3 seconds, then try GUI again

# 6. Check configd log for errors
tail -20 /var/log/system/latest.log
```

---

### Sites Won't Load Through VPN

**Symptom:** `curl --socks5` returns 200, but browser/LAN clients can't reach sites through VPN.

**Cause:** Missing Outbound NAT rule on TUN interface.

```sh
# Verify NAT rules exist for the TUN interface:
pfctl -sn | grep tun

# If empty — add Outbound NAT rule (see Outbound NAT section above)
```

**Other causes:**
```sh
# DNS not resolving through tunnel?
# Try IP directly:
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10

# If IP works but domain doesn't — DNS issue
# Check DNS settings in GUI → System → General → DNS

# MTU too high? Try lowering:
# GUI → Instance → MTU → 1400 (default is 1500)
```

---

### TUN Interface Issues

```sh
# Which driver does the instance use? (see Diagnostics tab or:)
configctl xray statusall

# — Native xray driver —
# Interface doesn't exist:
ifconfig tun100
# "does not exist" → xray not running or failed to create the TUN
ps aux | grep xray-core
configctl xray start <uuid>

# Interface exists but no IP:
# xray assigns gateway+1 itself (e.g. 10.255.0.2/30 for 10.255.0.1/30)
tail -50 /var/log/xray-core-<uuid>.log    # look for TUN errors
# Make sure the OPNsense interface static IP matches gateway+1 —
# a mismatched address gets re-applied by OPNsense and breaks routing

# Stale interface after an unclean kill:
ifconfig tun100 destroy && configctl xray start <uuid>

# — Legacy tun2socks driver —
ps aux | grep tun2socks                    # not running → configctl xray start
ifconfig proxytun2socks0                   # no "inet" line → run syshook manually:
/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray <uuid>

# Interface UP but no traffic (either driver)
netstat -ibn | grep tun100                 # Ibytes/Obytes = 0?
netstat -rn | grep tun                     # routing entry? gateway/firewall rules (selective mode)
# Full tunnel mode routes are managed by xray — check with: netstat -rn

# Interface removed after reboot
# GUI → Interfaces → <TUN interface> → Prevent interface removal ✓
# This is mandatory! Without it, OPNsense removes the interface on boot
```

---

### Raw JSON Mode Issues

```sh
# Config not applying in raw mode
# 1. The editor content is used verbatim — check it with the Validate Config
#    button (it runs xray -test on the DRAFT, before saving) or:
/usr/local/bin/xray-core -test -c /usr/local/etc/xray-core/config-<uuid>.json

# 2. Common JSON mistakes:
# - Trailing comma after last item in array/object
# - Single quotes instead of double quotes
# - Missing quotes around keys
# - Comments (// or /* */) — not allowed in JSON

# 3. With the tun2socks legacy driver the raw config MUST contain a SOCKS
#    inbound listening at the instance's SOCKS5 address/port — tun2socks
#    forwards the TUN traffic into it

# 4. Wizard-only features (routing rules, balancer, TUN/SOCKS sections)
#    do not apply in raw mode — the config is yours entirely
```

---

### Import VLESS Link Issues

```sh
# Import button does nothing / returns error
# 1. Check browser console (F12 → Console) for JS errors

# 2. Link must start with "vless://"
# Other protocols (vmess://, ss://) are not supported for import
# Use Custom Config mode and paste config.json manually

# 3. Link must have valid format:
# vless://UUID@HOST:PORT?params#name
# Required: UUID, HOST, PORT
# Optional: type, security, sni, fp, pbk, sid, flow, path, host, etc.

# 4. Port must be 1-65535, HOST must not be empty, UUID must not be empty

# 5. After import, press Apply to start the service!
```

---

### VLESS+Reality Connection Issues

```sh
# "failed to dial" in xray-core-<uuid>.log
# 1. Server reachable?
nc -zv <server_host> <server_port>
# or:
ping <server_host>

# 2. Wrong port?
# Check: GUI → Instance → Server Port

# 3. Reality handshake failure in log:
tail -50 /var/log/xray-core-<uuid>.log | grep -i reality
# Common causes:
# - Wrong PublicKey (pbk) — must match server's private key
# - Wrong ShortID (sid) — must match server config
# - Wrong SNI — must match server's allowed SNI list
# - Wrong fingerprint — try "chrome" (most compatible)
# - Clock skew — Reality is time-sensitive
#   ntpdate pool.ntp.org    # sync time
#   date                     # check current time

# 4. Server blocking your IP?
# Try from another network or check server logs
```

---

### Watchdog Issues

```sh
# Watchdog not restarting after crash
# 1. Is watchdog enabled?
# GUI → General → Enable Watchdog ✓

# 2. Is intentional stop flag set? (per instance)
ls -la /var/run/xray_stopped_*.flag
# If present — watchdog intentionally ignores. Remove to allow restart:
rm -f /var/run/xray_stopped_<uuid>.flag

# 3. Is cron running watchdog?
crontab -l | grep xray
# Should show xray-watchdog.php entry

# 4. Check watchdog log
tail -30 /var/log/xray-watchdog.log
# "restart FAILED" → check xray-core-<uuid>.log for root cause

# Watchdog keeps restarting (restart loop)
# Root cause: xray-core crashes immediately after start
# Fix the underlying issue first:
configctl xray generate <uuid> > /tmp/cfg.json && /usr/local/bin/xray-core -test -c /tmp/cfg.json
# Fix config, then watchdog will succeed
```

---

### xray-core Version Issues

```sh
# Check installed version
/usr/local/bin/xray-core version

# v4.0.0 targets xray-core 26.10+ (native FreeBSD TUN inbound with
# autoSystemRoutingTable / autoOutboundsInterface / autoSystemDnsToGateway).
# On older builds only the legacy tun2socks driver works.

# Upgrade to latest:
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip -o xray.zip xray
# Stop running instances first:
configctl xray stop
install -m 0755 /tmp/xray /usr/local/bin/xray-core
configctl xray start
/usr/local/bin/xray-core version    # verify
```

---

### Performance Issues

```sh
# High latency through tunnel
# 1. Check RTT to VPN server
ping -c 5 <server_host>
# GUI → Diagnostics tab shows Ping RTT

# 2. Check if tunnel is overloaded
netstat -ibn | grep tun100
# Compare Ibytes/Obytes over time

# 3. Try lowering MTU
# GUI → Instance → MTU → 1400 (or 1300 for double encapsulation)

# 4. Check server load — the VPN server itself may be slow
#    With Load Balancing on, leastPing picks the node with the lowest RTT

# CPU/memory usage
ps aux | grep -E 'xray|tun2socks' | awk '{print $3, $4, $11}'
# Column 1 = %CPU, Column 2 = %MEM, Column 3 = process
# xray-core typically uses <5% CPU, ~30MB RAM (a bit more with the native TUN stack)
# tun2socks (legacy driver) typically uses <3% CPU, ~15MB RAM
```

---

### Permission and File Issues

```sh
# Config files not writable
ls -la /usr/local/etc/xray-core/
ls -la /usr/local/tun2socks/
# config.json should be -rw-r----- (0640)
# Directories should be drwxr-x--- (0750)

# Fix permissions:
chmod 0640 /usr/local/etc/xray-core/config-<uuid>.json
chmod 0750 /usr/local/etc/xray-core/

# Binary not executable
chmod 0755 /usr/local/bin/xray-core
chmod 0755 /usr/local/tun2socks/tun2socks

# PHP errors (GUI not loading, API returning 500)
tail -30 /var/lib/php/tmp/PHP_errors.log

# configd can't execute scripts
ls -la /usr/local/opnsense/scripts/Xray/
# All .php files should be executable (-rwxr-xr-x)
chmod 0755 /usr/local/opnsense/scripts/Xray/*.php
service configd restart
```

---

### Zombie Processes

```sh
# Multiple xray-core or tun2socks processes
ps aux | grep -E 'xray-core|tun2socks' | grep -v grep

# If more than one per instance — kill all and restart cleanly:
pkill -f xray-core
pkill -f tun2socks
rm -f /var/run/xray_core_*.pid /var/run/tun2socks_*.pid /var/run/xray_start_*.lock
sleep 1
configctl xray start

# Stale TUN after an unclean kill (native driver):
ifconfig tun100 destroy

# PID file exists but process is dead (per instance)
cat /var/run/xray_core_<uuid>.pid
ps -p <PID>
# If "No such process" — stale PID file
rm -f /var/run/xray_core_<uuid>.pid
configctl xray start
```

---

### DNS Issues Through VPN

```sh
# Symptom: IPs work, domains don't resolve

# 1. Test DNS through tunnel
curl --socks5 127.0.0.1:10808 -s https://1.1.1.1 --max-time 10    # IP — should work
curl --socks5 127.0.0.1:10808 -s https://google.com --max-time 10  # domain — fails?

# 2. DNS may be leaking outside the tunnel
# Check which DNS servers are configured:
cat /etc/resolv.conf

# 3. Force DNS through tunnel:
# OPNsense GUI → System → General → DNS Servers
# Add DNS server (e.g. 1.1.1.1) with Gateway = PROXYTUN_GW
# This routes DNS queries through the VPN tunnel

# 4. Or let Xray answer DNS itself:
# Instance dialog → DNS Mode → Hijack
# Port-53 traffic entering the tunnel is resolved inside xray
# (dns module + dns-out outbound + routing rule are generated automatically)

# 5. In raw mode, add the dns section yourself:
# {"dns": {"servers": ["1.1.1.1"]}, "outbounds": [{"tag":"dns-out","protocol":"dns"}]}
```

---

### Selective Routing Not Working

```sh
# Traffic goes direct instead of through VPN

# 1. Check firewall rules exist
pfctl -sr | grep PROXYTUN
# Should see rules with gateway = PROXYTUN_GW

# 2. Check alias is populated
pfctl -t <alias_name> -T show
# Should list IPs/networks

# 3. Check gateway is up (selective mode)
netstat -rn | grep tun
# Should show 10.255.0.1 (Far Gateway) reachable through tun100

# 4. Gateway marked as down?
# GUI → System → Gateways → Status
# If "down" — see Gateway Monitoring section

# 5. Rules order matters! VPN rule must be ABOVE the default allow rule
# GUI → Firewall → Rules → LAN
# Drag VPN rule above the "Default allow LAN" rule

# 6. Engine-level routing: check the Routing tab rules —
#    a geosite/geoip rule may send the destination direct
#    (geoip.dat/geosite.dat must exist: ls /usr/local/etc/xray-core/)
```

---

### Gateway Monitoring Issues

```sh
# Gateway shows "down" even though tunnel works

# dpinger (OPNsense gateway monitor) uses ICMP
# xray-core doesn't respond to ICMP → dpinger always sees it as "down"

# Solution 1: Disable monitoring for this gateway
# GUI → System → Gateways → Edit PROXYTUN_GW
# Disable Gateway Monitoring ✓ (or set Monitor IP to empty)

# Solution 2: Set monitoring IP to a public IP reachable through tunnel
# GUI → System → Gateways → Edit PROXYTUN_GW
# Monitor IP: 1.1.1.1 (or any IP that responds to ping through VPN)
# Note: this may not work if xray-core drops ICMP
```

---

### Log Locations

| Log | Path | Contains | Rotation |
|---|---|---|---|
| Xray Core (per instance) | `/var/log/xray-core-<uuid>.log` | xray-core stdout/stderr (and legacy tun2socks) | 600 KB, 3 files |
| Boot | `/tmp/xray_syshook.log` | Auto-start, TUN checks, firewall reload | 50 KB, in-script |
| Watchdog | `/var/log/xray-watchdog.log` | Crash detection, restarts | 100 KB, 3 files |
| PHP errors | `/var/lib/php/tmp/PHP_errors.log` | OPNsense PHP (GUI, API) | OPNsense managed |
| System | `/var/log/system/latest.log` | configd errors | OPNsense managed |

---

### All Plugin Commands

```sh
# Service management (add <uuid> to target one instance)
configctl xray start [uuid]            # start all enabled instances / one
configctl xray stop [uuid]             # stop
configctl xray restart [uuid]          # stop + start
configctl xray reconfigure [uuid]       # called by Apply button (stop + start)

# Diagnostics
configctl xray status [uuid]           # JSON: status of the (first/one) instance
configctl xray statusall               # JSON: status of ALL instances
configctl xray version                 # JSON: plugin version
configctl xray validate [uuid]          # dry-run config validation (xray -test)
configctl xray generate [uuid]          # print the generated config.json
configctl xray testconnect [uuid]      # curl test through SOCKS5
configctl xray ifstats [uuid]          # JSON: TUN stats, uptime, RTT, environment
configctl xray validatefile <path>     # validate a /tmp/xray-val-* draft (GUI button)

# Logs
configctl xray log [uuid]              # last 150 lines of boot log
configctl xray xraylog [uuid]          # last 200 lines of xray-core log

# Manual script execution (for debugging)
/usr/local/opnsense/scripts/Xray/xray-service-control.php start
/usr/local/opnsense/scripts/Xray/xray-service-control.php statusall
/usr/local/opnsense/scripts/Xray/xray-service-control.php validate
/usr/local/opnsense/scripts/Xray/xray-ifstats.php
/usr/local/opnsense/scripts/Xray/xray-testconnect.php

# System
ps aux | grep -E 'xray|tun2socks'     # running processes
ifconfig tun100                        # TUN interface (native driver)
netstat -rn | grep tun                 # routing table
netstat -ibn | grep tun               # interface traffic stats
sockstat -4l | grep 10808              # who's using SOCKS5 port
tail -f /var/log/xray-core-<uuid>.log # live log monitoring
```

---

### Reset and Reinstall

```sh
# Full reinstall without losing OPNsense config
sh install.sh uninstall
sh install.sh

# Nuclear option — clean everything and start fresh
configctl xray stop
rm -f /var/run/xray_*.pid /var/run/tun2socks_*.pid /var/run/xray_*.lock /var/run/xray_*.flag
rm -f /usr/local/etc/xray-core/config-*.json
rm -f /usr/local/tun2socks/config-*.yaml
sh install.sh uninstall
sh install.sh
# Then re-import VLESS link and Apply
```

---

## File Structure

```
os-xray/
├── install.sh
├── Changelog.md
└── plugin/
    ├── +MANIFEST                               ← FreeBSD pkg metadata
    ├── etc/
    │   ├── inc/plugins.inc.d/
    │   │   └── xray.inc                        ← service registration, boot hook, cron watchdog
    │   ├── newsyslog.conf.d/
    │   │   └── xray.conf                       ← xray-core and xray-watchdog log rotation
    │   └── rc.syshook.d/start/
    │       └── 50-xray                         ← auto-start after reboot (driver-aware)
    ├── scripts/Xray/
    │   ├── xray-service-control.php            ← service control (drivers, generate, validatefile)
    │   ├── xray-watchdog.php                   ← watchdog: process check and restart
    │   ├── xray-ifstats.php                    ← TUN stats + environment for Diagnostics
    │   └── xray-testconnect.php                ← SOCKS5 connection test
    ├── service/conf/actions.d/
    │   └── actions_xray.conf                   ← configd actions
    ├── web/js/xray/                            ← CodeMirror bundle (Raw JSON editor)
    └── mvc/app/
        ├── models/OPNsense/Xray/
        │   ├── General.xml / General.php       ← model: enable, watchdog
        │   ├── Instance.xml / Instance.php     ← model: instances (tun/dns/lb/srv fields)
        │   ├── Group.xml / Group.php           ← model: server groups + servers
        │   ├── RoutingRule.xml / RoutingRule.php ← model: routing rules (v4.0.0)
        │   ├── ACL/ACL.xml                      ← access control (page-vpn-xray)
        │   └── Menu/Menu.xml                    ← VPN → Xray menu item
        ├── library/OPNsense/Xray/
        │   ├── ConfigBuilder.php               ← THE config generator (v4.0.0)
        │   └── VlessLink.php                   ← vless:// link parser
        ├── controllers/OPNsense/Xray/
        │   ├── IndexController.php
        │   ├── forms/general.xml
        │   ├── forms/instance.xml
        │   ├── forms/group.xml / forms/server.xml
        │   ├── forms/rule.xml
        │   └── Api/
        │       ├── GeneralController.php
        │       ├── InstanceController.php
        │       ├── GroupController.php          ← groups CRUD, subscription import, refresh
        │       ├── RuleController.php          ← routing rules CRUD (v4.0.0)
        │       ├── ServiceController.php       ← + validateConfig (draft), previewConfig
        │       └── ImportController.php        ← VLESS link parse
        └── views/OPNsense/Xray/
            ├── general.volt                     ← tabs, dialogs, preview/nginx modals
            └── partials/
                ├── tab_instances.volt
                ├── tab_groups.volt
                ├── tab_routing.volt             ← routing rules grid (v4.0.0)
                ├── tab_diagnostics.volt
                ├── tab_logs.volt
                └── scripts.volt                 ← page logic (CodeMirror, snippets, sync)
```

---

## Manual Binary Installation

The installer will notify you if binaries are missing. Below are commands for manual installation.

**xray-core** — [github.com/XTLS/Xray-core/releases](https://github.com/XTLS/Xray-core/releases) (26.10+ required for the native TUN driver)
```sh
fetch -o /tmp/xray.zip https://github.com/XTLS/Xray-core/releases/latest/download/Xray-freebsd-64.zip
cd /tmp && unzip xray.zip xray
install -m 0755 /tmp/xray /usr/local/bin/xray-core
```

**geoip.dat / geosite.dat** — for GeoIP/GeoSite routing rules (normally installed by `install.sh`)
```sh
fetch -o /usr/local/etc/xray-core/geoip.dat https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geoip.dat
fetch -o /usr/local/etc/xray-core/geosite.dat https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geosite.dat
```

**tun2socks** (optional, legacy TUN driver only) — [github.com/xjasonlyu/tun2socks/releases](https://github.com/xjasonlyu/tun2socks/releases)
```sh
fetch -o /tmp/tun2socks.zip https://github.com/xjasonlyu/tun2socks/releases/latest/download/tun2socks-freebsd-amd64.zip
cd /tmp && unzip tun2socks.zip
mkdir -p /usr/local/tun2socks
install -m 0755 /tmp/tun2socks-freebsd-amd64 /usr/local/tun2socks/tun2socks
```

> The tun2socks filename after extraction may vary by version — check with `ls /tmp/tun2socks*`.

---

## Changelog

Full changelog in [CHANGELOG.md](CHANGELOG.md).

| Version | Changes |
|---------|---------|
| 4.0.1   | Raw JSON: xray-style comments (//, #), XRAY_LOCATION_ASSET for geo data, runtime params (TUN/SOCKS) derived from the JSON, contradictory GUI sections hidden in raw mode |
| 4.0.0   | **Native Xray mechanisms**: built-in TUN inbound (`tun_driver`), Routing tab (domain/ip/port/geoip/geosite rules), balancer + burstObservatory (leastPing/roundRobin/leastLoad/random), server-side VLESS inbound (ws/gRPC/h2/TCP, TLS/Reality, nginx snippet), Raw JSON mode with CodeMirror + draft validation, ConfigBuilder, DNS hijack, full-tunnel option (`net.fibs=2`), xray-core 26.10+ required, geo dat files installed, v3.x migration |
| 3.2.1   | Log rotation fix (daemon -H -o + SIGHUP reopen), tun2socks `--config` |
| 3.2.0   | Fork: server groups, subscription import, Refresh, node_source switch, VlessLink class |
| 3.0.1   | Fix multiple multi-instance bugs |
| 3.0.0   | Multi-instance (ArrayField), per-instance state in table, Import VLESS and Validate Config inside dialog, custom config use SOCKS5 from the form, rename uuid→vless_uuid, migrate into install.sh |
| 2.0.0   | Custom Config (wizard/custom), VLESS import with auto-generated config.json for any transport, xhttp↔splithttp normalization, xray-core version check on install |
| 1.10.0  | Version check on install, `configctl xray version`, API version endpoint, Outbound NAT in README |
| 1.9.3   | P1 bugfixes (implode, socks5_port, validate tempfile, dedup), Bypass Networks, Copy Debug Info, Ping RTT, Diagnostics auto-refresh |
| 1.9.2   | Fix tun2socks fatal error on stop, watchdog log rotation |
| 1.9.1   | Hotfix validate: xray -test syntax, .json extension for tempnam |
| 1.9.0   | Improved SOCKS5 Listen Address hint |
| 1.8.0   | Security audit: proc_kill, watchdog stopped flag, validate tempfile |
| 1.7.0   | Fix GUI blocking, ifstats bytes fix, 50-xray from do_start |
| 1.6.0   | BUG-5/9, Watchdog E1, Diagnostics E4, Validate Config E5 |
| 1.5.0   | Log rotation, Start/Stop/Restart buttons, Xray Core Log tab |
| 1.4.0   | Security audit P0-P2, xray-testconnect, flock, stderr to log |
| 1.3.0   | Model versioning, `+MANIFEST`, `Changelog.md` |
| 1.2.0   | Log tab, Test Connection button, 5s status interval |
| 1.1.0   | Robust install.sh: PHP config parsing, check_port, log rotation |
| 1.0.1   | Fixed loglevel, TUN destroy on stop, flock |
| 1.0.0   | ACL, UUID validation, ImportController sanitization |
| 0.9.0   | Initial release |

---

## License

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Author

Merkulov Pavel Sergeevich
February — March 2026

---

## Acknowledgements

- [XTLS/Xray-core](https://github.com/XTLS/Xray-core) — Xray-core and VLESS+Reality protocol
- [xjasonlyu/tun2socks](https://github.com/xjasonlyu/tun2socks) — tun2socks
- [OPNsense](https://opnsense.org) — open plugin architecture
- [yukh975](https://github.com/yukh975) — testing help
- [hohner36](https://github.com/hohner36) — testing and automation help
