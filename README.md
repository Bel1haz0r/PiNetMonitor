# PiNetMonitor

An always-on internet connection monitor for a Raspberry Pi. It records outages and connection
quality, isolates *where* each fault sits (this device, the local network, or beyond the router), and
produces documentation usable for a complaint to the operator and the regulator (RATEL, Serbia).

Laravel 13 + React, SQLite, no external services. Everything runs and stays on the Pi; the dashboard
works while the internet is down — that is its purpose.

Based on [Internet Evidence Monitor](https://github.com/zoxknez/InternetMonitoring) by o0o0o0o
(zoxknez), MIT, with the author's permission. That project is a Windows/desktop application; this one
re-implements its measurement and evidence model as a headless appliance with a web UI.
See [NOTICE.md](NOTICE.md).

## What it does

- **Isolates the fault instead of just saying "no internet".** Every cycle pings the router and three
  independent external targets and opens TCP connections; DNS, TLS and HTTP are checked in the
  background. "Router answers, nothing beyond it does" is the only state that counts towards a
  complaint. The wording is always *isolated beyond the router*, never *the operator's fault* — a
  device inside the house cannot see the router's WAN side.
- **Does not invent outages.** Filtered ping with working TCP/TLS is recorded as "ping is filtered",
  not as an outage. A success measured before trouble began never counts as proof the line works.
- **Does not invent precision.** Each outage carries a lower bound, an upper bound and an estimate
  between them.
- **Does not count unobserved time.** If the monitor was not running (power cut, reboot), that is a
  *pause*, never an outage and never uptime. Availability is computed against time actually watched.
- **Adaptive sampling.** Once a second while healthy, up to four times a second around a fault.
- **Tamper-evident evidence.** Every finding is appended to a hash-chained JSONL log before it is
  written to the database. `php artisan evidence:verify` prints `VALID` / `BROKEN` (exit code 0 / 1).
- **Speed measurement with latency under load** (idle vs. loaded latency exposes bufferbloat),
  on demand or on a daily schedule.
- **Reports**: HTML, PDF, CSV and one ZIP package with checksums.
- **Complaint case**: deadlines under Serbian law, a ready-to-file complaint letter, a RATEL
  submission, and a case diary.
- **English and Serbian** UI, reports and letters.

## Run it on your computer (development)

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build            # or: npm run dev
php artisan monitor:run  # terminal 1: the monitor daemon
php artisan serve        # terminal 2: dashboard at http://localhost:8000
```

Useful commands:

| Command | Purpose |
|---|---|
| `php artisan monitor:run` | The daemon. Resumes the open session after a restart. |
| `php artisan monitor:run --for=30 --simulate=upstream:10:8` | Test run: 30 s, with a simulated outage beyond the router from second 10 for 8 s. **Writes simulated findings into the session** — never use it on a session you intend to file. |
| `php artisan evidence:verify [session]` | Verify a session's hash chain (id, name, or path to a `.jsonl`). |
| `php artisan test` | Unit tests for the classifier, incident bounds, hash chain and deadlines. |

## Install on a Raspberry Pi

Requirements: Raspberry Pi OS based on Debian 13 "trixie" (PHP 8.3 or newer). A **wired Ethernet
connection to the router** is strongly recommended: it removes Wi-Fi as a possible cause of every
outage the Pi records.

1. On your computer, build the frontend so Node.js is not needed on the Pi: `npm install && npm run build`
2. Copy the project to the Pi, for example:
   `rsync -a --exclude node_modules --exclude vendor --exclude .env --exclude 'database/*.sqlite*' --exclude storage/app/monitor ./ pi@raspberrypi.local:pinetmonitor/`
3. On the Pi: `cd pinetmonitor && sudo bash deploy/install.sh`

The script installs PHP and tools, copies the app to `/opt/pinetmonitor`, runs migrations and installs
two systemd services:

| Service | What |
|---|---|
| `pinetmonitor` | the monitor daemon (starts at boot, restarts on failure) |
| `pinetmonitor-web` | the dashboard on port 8080 |

Open `http://<pi-address>:8080`. Logs: `journalctl -u pinetmonitor -f`.

Re-running the script updates the application and keeps the database and the evidence.

### Notes for the Pi

- **SD-card wear.** Healthy samples are aggregated per minute instead of being stored one by one;
  every sample around and during a fault is recorded individually. The live status file is written
  to tmpfs (`/run/pinetmonitor`). A healthy line produces about 0.5 MB of raw evidence per day.
- **Power cuts.** The chain is flushed to disk on every state change. A line torn by a power cut is
  removed on restart and the chain continues; the time without power is recorded as a pause.
- **No real-time clock.** A Pi's clock can jump when NTP syncs after boot. Durations are measured
  with the monotonic counter, never the wall clock, and clock jumps are recorded.
- **Access control.** The dashboard has no login by default — it is meant for your home network.
  Set `MONITOR_PASSWORD` in `/opt/pinetmonitor/.env` (then `php artisan config:cache` and restart
  both services) to require HTTP Basic authentication. Do not expose it to the internet.
- **Timezone.** `MONITOR_TIMEZONE` (default `Europe/Belgrade`) is used for reports, letters,
  session names and the speed-test schedule. Storage is UTC.
- **Speed tests** use Cloudflare's public endpoint and as much data as the line can move in the
  window (about 1 GB on a gigabit line with the default 10 s per direction). The endpoint rate-limits
  heavy use (HTTP 429); a few scheduled measurements a day are fine. Older Pi models with a
  100 Mbit/s Ethernet port cannot measure a faster line.
- **Alternative: the `speedtest` command.** Settings → Speed measurement → *Installed speedtest
  command* measures against a nearby Speedtest server instead. Either program works; the app
  detects which one is installed:
  - Debian's open-source `speedtest-cli` — `sudo apt install speedtest-cli`, or run the installer
    with `WITH_SPEEDTEST=1`. Throughput and idle ping only, no latency under load.
  - Ookla's official CLI — install it from Ookla's repository
    (<https://www.speedtest.net/apps/cli>). Also reports latency under load. It is proprietary:
    you must tick the licence box in Settings before the app will run it, and every result is
    uploaded to speedtest.net.

  Each stored measurement records which method and server produced it. Figures from the two
  methods are not directly comparable — do not mix them in one argument.

## How the evidence is stored

```
storage/app/monitor/
├── sessions/Session_20261125_132757/RawEvidence.jsonl   the evidence: append-only, hash-chained
└── packages/Evidence_Session_…zip                        exported packages
database/database.sqlite                                  derived index for the UI and reports
```

Each line of `RawEvidence.jsonl` is one JSON object ending in `,"h":"<sha256>"}`. Remove that suffix,
close the object with `}`, and the SHA-256 of the result must equal the hash; the `prev` field must
equal the hash of the previous line (64 zeros for the first). Anyone can check this without this
program.

**What this shows and what it does not.** It shows that the chain is internally consistent. It is not
proof of origin: the chain sits on the same disk as whoever could rewrite it. Independent proof would
need a third-party signature and timestamp, which this version does not do.

## Differences from Internet Evidence Monitor

| | Internet Evidence Monitor | PiNetMonitor |
|---|---|---|
| Platform | Windows desktop/service (.NET) | Raspberry Pi appliance (Laravel + React) |
| Sampling | 1 s healthy, 100 ms in a fault | 1 s healthy, 250 ms in a fault (configurable) |
| Raw log | every sample | every sample around a fault + per-minute aggregates |
| Session | fixed duration (e.g. 48 h) | continuous; start a new one from the UI |
| Wi-Fi radio-down detection (SSID scan) | yes | no — an adapter going down is reported as a local fault |
| Router UPnP status, traceroute, IPv6 | yes | no (IPv4 only) |
| Signed manifest, RFC 3161 timestamp, redacted package | yes (v3) | no |
| Waiting for an idle line before a speed test | yes | no (the Pi only sees its own traffic) |

## Limits

This is a technical record, not a measurement by an authorised third party. For proving contracted
*speed*, the regulator requires a measurement over Ethernet directly at the modem (RATEL NetTest
procedure); the speed figures here supplement that, they do not replace it. Deadlines and letters are
prepared from commonly applicable values and are **not legal advice** — check your contract and the
regulations in force.

## Licence

MIT. See [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md).
