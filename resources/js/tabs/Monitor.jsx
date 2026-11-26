import { useEffect, useMemo, useState } from 'react';
import { api } from '../api';
import { LatencyChart, Legend, StatusMark, TimelineStrip } from '../charts';
import { SEVERITY, dateTime, duration, num, percent } from '../format';

export function Stat({ label, value, hint, tone }) {
    const toneClass = tone === 'bad' ? 'text-bad' : tone === 'ok' ? 'text-ok' : '';
    return (
        <div className="card">
            <div className="muted text-xs font-medium">{label}</div>
            <div className={`mt-1 text-2xl font-semibold tabular-nums ${toneClass}`}>{value}</div>
            {hint && <div className="muted mt-0.5 text-xs">{hint}</div>}
        </div>
    );
}

export function IncidentTable({ incidents, t, lang }) {
    if (!incidents?.length) return <p className="muted text-sm">{t('none_recorded')}</p>;
    return (
        <div className="overflow-x-auto">
            <table className="tbl w-full min-w-[640px]">
                <thead>
                    <tr>
                        <th>{t('col.no')}</th><th>{t('col.start')}</th><th className="text-right">{t('col.duration')}</th>
                        <th className="text-right">{t('col.min')}</th><th className="text-right">{t('col.max')}</th>
                        <th>{t('col.isolated')}</th><th className="text-right">{t('col.confidence')}</th><th>{t('col.note')}</th>
                    </tr>
                </thead>
                <tbody>
                    {incidents.map((i) => {
                        const notes = [];
                        if (i.ended_by_gap) notes.push(t('note.ended_by_gap'));
                        if (i.started_after_gap) notes.push(t('note.started_after_gap'));
                        if (i.is_open && !i.ended_by_gap) notes.push(t('note.open'));
                        if (i.peak_local_bps >= 1000000) notes.push(t('note.own_traffic'));
                        const upstream = i.worst_state === 'CpeUpstreamUnreachable';
                        return (
                            <tr key={i.id}>
                                <td className="tabular-nums">{i.number}</td>
                                <td className="whitespace-nowrap tabular-nums">{dateTime(i.started_at, lang)}</td>
                                <td className="text-right font-semibold whitespace-nowrap tabular-nums">{duration(i.duration_reported_ms, true, lang)}</td>
                                <td className="text-right whitespace-nowrap tabular-nums">{duration(i.duration_min_ms, true, lang)}</td>
                                <td className="text-right whitespace-nowrap tabular-nums">{duration(i.duration_max_ms, true, lang)}</td>
                                <td>
                                    <span className={upstream ? 'font-medium text-bad' : ''}>{t('domain.' + i.domain)}</span>
                                    <div className="muted text-xs">{t('state.' + i.worst_state)}</div>
                                </td>
                                <td className="text-right whitespace-nowrap tabular-nums">
                                    {i.confidence_support != null ? (<>{i.confidence_support}% / {i.confidence_coverage}%<div className="muted text-xs">{t('band.' + i.confidence_band)}</div></>) : '—'}
                                </td>
                                <td className="muted text-xs">{notes.join('; ')}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function ProbeRow({ name, value, t }) {
    const failed = value === null;
    return (
        <div className="flex items-center justify-between gap-3 border-t border-slate-200 py-1.5 text-sm first:border-t-0 dark:border-slate-800">
            <span className="truncate font-mono text-xs">{name}</span>
            <span className={`tabular-nums ${failed ? 'font-medium text-bad' : ''}`}>
                {value === undefined ? <span className="muted">{t('not_measured')}</span> : failed ? t('no_answer') : `${num(value)} ms`}
            </span>
        </div>
    );
}

export default function Monitor({ status, t, lang }) {
    const live = status?.live;
    const session = status?.session;
    const running = !!status?.running;
    const [series, setSeries] = useState([]);
    const [incidents, setIncidents] = useState([]);

    const sessionId = session?.id;
    const outageCount = session?.outage_count;
    useEffect(() => {
        if (!sessionId) return;
        const load = () => {
            api.get(`sessions/${sessionId}/series?hours=24`).then((rows) => setSeries(rows.map((r) => ({ ...r, t: r.t * 1000 })))).catch(() => {});
            api.get(`sessions/${sessionId}/incidents`).then((rows) => setIncidents(rows.filter((i) => SEVERITY[i.worst_state] === 'outage').slice(0, 8))).catch(() => {});
        };
        load();
        const id = setInterval(load, 60000);
        return () => clearInterval(id);
    }, [sessionId, outageCount]);

    const recent = useMemo(() => (live?.recent ?? []).map(([ts, avg, , state, min, max]) => ({
        t: ts, avg, min: min ?? avg, max: max ?? avg, sev: SEVERITY[state] ?? 'info',
    })), [live]);

    const state = live?.state ?? 'Ok';
    const severity = live?.severity ?? 'info';
    const monitored = live && live.session?.id === session?.id ? live.session.monitored_ms : session?.monitored_ms;
    const sinceMs = live ? (status.now - live.since) * 1000 : 0;
    const deep = live?.deep;
    const borderTone = !running ? 'border-l-idle' : severity === 'outage' ? 'border-l-bad' : severity === 'degraded' ? 'border-l-warn' : 'border-l-ok';

    return (
        <div className="space-y-4">
            <div className={`card flex flex-col gap-4 border-l-8 sm:flex-row sm:items-center ${borderTone}`}>
                <StatusMark severity={severity} running={running} />
                <div className="min-w-0 flex-1">
                    {running ? (
                        <>
                            <div className="text-2xl font-semibold">{t('state.' + state)}</div>
                            <div className="muted text-sm">
                                {t('since')} {duration(sinceMs, false)}
                                {live.domain !== 'none' && <> · {t('isolated_to')}: <b className="text-slate-800 dark:text-slate-100">{t('domain.' + live.domain)}</b></>}
                            </div>
                            {live.domain !== 'none' && <p className="mt-1.5 max-w-3xl text-sm">{t('domainExplain.' + live.domain)}</p>}
                            {severity !== 'ok' && <p className="muted mt-1 font-mono text-xs break-words">{live.detail}</p>}
                        </>
                    ) : (
                        <>
                            <div className="text-2xl font-semibold">{t('offline_title')}</div>
                            <p className="muted text-sm">{t('offline_text')}</p>
                        </>
                    )}
                </div>
                {running && (
                    <div className="muted shrink-0 text-xs sm:text-right">
                        <div>{t('cadence')}: <b>{t('phase.' + live.phase)}</b>, {t('every')} {live.interval_ms} ms</div>
                        <div>{t('interface')}: <span className="font-mono">{live.link?.interface ?? '—'}</span> ({live.link?.wireless ? t('wireless') : t('wired')})</div>
                        <div>{t('router')}: <span className="font-mono">{live.link?.gateway ?? '—'}</span></div>
                        <div>{t('session')}: {live.session?.name}</div>
                    </div>
                )}
            </div>

            {session && (
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <Stat label={t('monitored')} value={duration(monitored, false)} hint={`${t('pauses')}: ${duration(session.gap_ms, false)} — ${t('not_counted')}`} />
                    <Stat label={t('availability')} value={percent(session.upstream_availability, lang)} hint={t('availability_hint')} />
                    <Stat
                        label={t('upstream_outages')} value={session.upstream_count} tone={session.upstream_count ? 'bad' : 'ok'}
                        hint={session.upstream_count ? `${duration(session.upstream_ms, true, lang)} (${t('at_least')} ${duration(session.upstream_min_ms, true, lang)})` : null}
                    />
                    <Stat label={t('all_outages')} value={session.outage_count} hint={session.outage_count ? duration(session.outage_ms, true, lang) : null} />
                    <Stat label={t('latency')} value={session.rtt_avg != null ? `${num(session.rtt_avg, lang)} ms` : '—'} hint={session.rtt_avg != null ? `${num(session.rtt_min, lang)} – ${num(session.rtt_max, lang)} ms` : null} />
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="card lg:col-span-2">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 className="font-semibold">{t('live_latency')}</h2>
                        <span className="muted text-xs">{t('live_hint')}</span>
                    </div>
                    <LatencyChart points={recent} />
                </div>
                <div className="card">
                    <h2 className="mb-1 font-semibold">{t('probes')}</h2>
                    {running ? (
                        <>
                            <ProbeRow name={`${t('router')} ${live.link?.gateway ?? ''}`} value={live.link?.gateway ? live.last?.gw ?? null : undefined} t={t} />
                            {Object.entries(live.last?.icmp ?? {}).map(([k, v]) => <ProbeRow key={'i' + k} name={`ping ${k}`} value={v} t={t} />)}
                            {Object.entries(live.last?.tcp ?? {}).map(([k, v]) => <ProbeRow key={'t' + k} name={`tcp ${k}`} value={v} t={t} />)}
                            {Object.entries(deep?.dns_assigned ?? {}).map(([k, v]) => <ProbeRow key={'da' + k} name={`${t('dns_assigned')} ${k}`} value={deep.fresh ? v : undefined} t={t} />)}
                            {Object.entries(deep?.dns_public ?? {}).map(([k, v]) => <ProbeRow key={'dp' + k} name={`${t('dns_public')} ${k}`} value={deep.fresh ? v : undefined} t={t} />)}
                            {Object.values(deep?.tls ?? {}).map((v, k) => <ProbeRow key={'tls' + k} name="TLS 1.1.1.1" value={deep.fresh ? v : undefined} t={t} />)}
                            {Object.values(deep?.http ?? {}).map((v, k) => <ProbeRow key={'http' + k} name="HTTP 204" value={deep.fresh ? v : undefined} t={t} />)}
                        </>
                    ) : <p className="muted text-sm">—</p>}
                </div>
            </div>

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('last_24h')}</h2>
                <TimelineStrip points={series} />
                <Legend t={t} />
                <div className="mt-2"><LatencyChart points={series} height={150} /></div>
            </div>

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('recent_incidents')}</h2>
                <IncidentTable incidents={incidents} t={t} lang={lang} />
            </div>
        </div>
    );
}
