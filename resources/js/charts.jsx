import { COLORS, clock } from './format';

/**
 * Charts are drawn directly as SVG, no library, in the same visual language as
 * the report. Latency is the range from fastest to slowest response with the
 * average through it — the average alone would smooth away the spikes.
 *
 * points: [{ t: ms, min, avg, max, sev }]
 */
export function LatencyChart({ points, height = 170 }) {
    const W = 800;
    const pl = 46, pr = 8, pt = 8, pb = 20;
    const iw = W - pl - pr, ih = height - pt - pb;
    if (!points || points.length < 2) {
        return <div className="muted flex items-center justify-center text-sm" style={{ height }}>—</div>;
    }
    const peak = Math.max(10, ...points.map((p) => p.max ?? 0));
    const top = [10, 20, 50, 100, 200, 500, 1000, 2000, 5000].find((s) => peak <= s) ?? Math.ceil(peak / 1000) * 1000;
    const n = points.length - 1;
    // Positioned by time, not by index: sampling speeds up during an outage, and
    // spacing points evenly would stretch every outage on the axis.
    const t0 = points[0].t;
    const span = Math.max(1, points[n].t - t0);
    const x = (i) => pl + (iw * (points[i].t - t0)) / span;
    const y = (v) => pt + ih - (ih * Math.min(v, top)) / top;

    // Contiguous measured runs; a hole in the data is drawn as a hole.
    const runs = [];
    let run = [];
    points.forEach((p, i) => {
        if (p.avg == null) {
            if (run.length) runs.push(run);
            run = [];
        } else run.push({ ...p, i });
    });
    if (run.length) runs.push(run);

    // One band per stretch of the same non-healthy severity.
    const bands = [];
    points.forEach((p, i) => {
        const last = bands[bands.length - 1];
        if (p.sev === 'ok' || p.sev === 'none') return;
        if (last && last.sev === p.sev && last.to === i - 1) last.to = i;
        else bands.push({ sev: p.sev, from: i, to: i });
    });
    const ticks = [0, Math.floor(n / 2), n];

    return (
        <svg viewBox={`0 0 ${W} ${height}`} className="w-full" role="img" aria-label="latency chart">
            {bands.map((b, k) => {
                const from = x(b.from);
                const to = b.to < n ? x(b.to + 1) : x(b.to) + 2;
                return <rect key={k} x={from} y={pt} width={Math.max(2, to - from)} height={ih} fill={COLORS[b.sev]} opacity={b.sev === 'outage' ? 0.3 : 0.18} />;
            })}
            {[0, 0.5, 1].map((f) => (
                <g key={f}>
                    <line x1={pl} x2={W - pr} y1={y(top * f)} y2={y(top * f)} className="stroke-slate-200 dark:stroke-slate-700" strokeWidth="1" />
                    <text x={pl - 6} y={y(top * f) + 4} textAnchor="end" fontSize="11" className="fill-slate-500">{Math.round(top * f)} ms</text>
                </g>
            ))}
            {runs.map((r, k) => (
                <g key={k}>
                    {r.length > 1 && (
                        <polygon
                            points={[...r.map((p) => `${x(p.i)},${y(p.max)}`), ...[...r].reverse().map((p) => `${x(p.i)},${y(p.min)}`)].join(' ')}
                            fill="#3b82c4" fillOpacity="0.22"
                        />
                    )}
                    <polyline points={r.map((p) => `${x(p.i)},${y(p.avg)}`).join(' ')} fill="none" stroke="#2a78bd" strokeWidth="1.6" />
                </g>
            ))}
            {ticks.map((i) => (
                <text key={i} x={x(i)} y={height - 5} fontSize="11" textAnchor={i === 0 ? 'start' : i === n ? 'end' : 'middle'} className="fill-slate-500">
                    {clock(points[i].t)}
                </text>
            ))}
        </svg>
    );
}

/** One coloured slice per bucket: where the period was healthy, degraded or down. */
export function TimelineStrip({ points, height = 22 }) {
    const W = 800;
    const n = Math.max(1, points?.length ?? 0);
    const sw = W / n;
    return (
        <svg viewBox={`0 0 ${W} ${height}`} className="w-full rounded" preserveAspectRatio="none" role="img" aria-label="timeline">
            <rect x="0" y="0" width={W} height={height} fill={COLORS.none} opacity="0.5" />
            {(points ?? []).map((p, i) => (
                <rect key={i} x={i * sw} y="0" width={sw + 0.4} height={height} fill={COLORS[p.sev] ?? COLORS.none} />
            ))}
        </svg>
    );
}

export function Legend({ t }) {
    return (
        <div className="muted mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            {['ok', 'degraded', 'outage', 'none'].map((sev) => (
                <span key={sev} className="inline-flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: COLORS[sev] }} />
                    {t('legend.' + sev)}
                </span>
            ))}
        </div>
    );
}

/**
 * Status mark. Colour carries the verdict and SHAPE carries whether monitoring
 * is running — filled while it runs, a hollow ring when it does not — because
 * colour alone is useless to someone who cannot tell red from green.
 */
export function StatusMark({ severity, running, size = 56 }) {
    const color = running ? COLORS[severity] ?? COLORS.info : COLORS.info;
    return (
        <svg width={size} height={size} viewBox="0 0 56 56" aria-hidden="true" className="shrink-0">
            {running ? (
                <>
                    <circle cx="28" cy="28" r="26" fill={color} opacity="0.18" />
                    <circle cx="28" cy="28" r="17" fill={color} />
                    {severity === 'outage' && <path d="M21 21 L35 35 M35 21 L21 35" stroke="#fff" strokeWidth="4" strokeLinecap="round" />}
                    {severity === 'degraded' && <path d="M28 19 V30 M28 36 V37" stroke="#fff" strokeWidth="4" strokeLinecap="round" />}
                    {severity === 'ok' && <path d="M20 28.5 L26 34 L36.5 22.5" stroke="#fff" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" fill="none" />}
                </>
            ) : (
                <circle cx="28" cy="28" r="19" fill="none" stroke={color} strokeWidth="5" />
            )}
        </svg>
    );
}
