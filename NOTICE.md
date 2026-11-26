# Notice

PiNetMonitor is derived from **Internet Evidence Monitor** by o0o0o0o (zoxknez):
https://github.com/zoxknez/InternetMonitoring — MIT License, Copyright (c) 2026 o0o0o0o.

No source code was copied; the following were re-implemented in PHP/JavaScript from that project's
design and source: the network state model and fault-domain wording, the classification order, the
incident detector with lower/upper duration bounds, adaptive sampling cadence, two-dimensional
confidence scoring, the hash-chain line format, latency-under-load speed measurement, and the Serbian
complaint letters and deadline rules (rule values as verified by that project's author on 2026-08-17).

Third-party components:

- Laravel framework — MIT
- React — MIT
- Tailwind CSS — MIT
- Dompdf (PDF rendering, bundles DejaVu fonts) — LGPL-2.1
