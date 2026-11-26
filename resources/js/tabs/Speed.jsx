import { useEffect, useState } from 'react';
import { api } from '../api';
import { dateTime, num } from '../format';

export default function Speed({ t, lang, status }) {
    const [tests, setTests] = useState([]);
    const [queued, setQueued] = useState(false);
    const running = !!status?.live?.speed_running;
    const pending = (status?.pending_commands ?? []).includes('speed_test');

    const load = () => api.get('speed').then(setTests).catch(() => {});
    useEffect(() => { load(); }, [running, pending]);
    useEffect(() => { if (!pending && !running) setQueued(false); }, [pending, running]);

    const run = () => {
        setQueued(true);
        api.post('speed/run').catch(() => setQueued(false));
    };
    const share = (measured, contracted) => (measured != null && contracted ? ` (${Math.round((100 * measured) / contracted)}% ${t('of_contract')})` : '');
    const gradeTone = { slight: 'text-ok', noticeable: 'text-warn', severe: 'text-bad' };

    return (
        <div className="space-y-4">
            <div className="card">
                <div className="flex flex-wrap items-center gap-3">
                    <button className="btn btn-primary" onClick={run} disabled={!status?.running || running || queued || pending}>
                        {running ? t('speed_running') : t('run_speed')}
                    </button>
                    {(queued || pending) && !running && <span className="muted text-sm">{t('speed_queued')}</span>}
                    {!status?.running && <span className="muted text-sm">{t('offline_title')}</span>}
                </div>
                <p className="mt-3 max-w-3xl text-sm">{t('speed_intro')}</p>
                <p className="muted mt-1 max-w-3xl text-sm">{t('speed_backends')}</p>
                <p className="muted mt-1 max-w-3xl text-sm">{t('speed_cost')}</p>
            </div>

            <div className="card">
                {tests.length === 0 ? <p className="muted text-sm">{t('no_speed')}</p> : (
                    <div className="overflow-x-auto">
                        <table className="tbl w-full min-w-[720px]">
                            <thead>
                                <tr>
                                    <th>{t('col.time')}</th><th className="text-right">{t('col.download')}</th><th className="text-right">{t('col.upload')}</th>
                                    <th className="text-right">{t('col.idle')}</th><th className="text-right">{t('col.loaded')}</th>
                                    <th className="text-right">{t('col.increase')}</th><th className="text-right">{t('col.data')}</th><th>{t('col.trigger')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tests.map((s) => (
                                    <tr key={s.id}>
                                        <td className="whitespace-nowrap tabular-nums">
                                            {dateTime(s.measured_at, lang)}
                                            {s.status !== 'ok' && <div className="text-xs text-bad">{t('failed')}: {s.note}</div>}
                                            <div className="muted text-xs whitespace-normal">
                                                {t('backend.' + (s.backend ?? 'cloudflare'))}{s.server ? ` — ${s.server}` : ''}{s.status === 'ok' && s.wireless ? ' · Wi-Fi' : ''}
                                                {s.result_url && /^https:\/\/www\.speedtest\.net\//.test(s.result_url) && <> · <a className="underline" href={s.result_url} target="_blank" rel="noreferrer">{t('result_link')}</a></>}
                                            </div>
                                        </td>
                                        <td className="text-right tabular-nums">{s.down_mbps != null ? <><b>{num(s.down_mbps, lang)}</b> Mbit/s<div className="muted text-xs">{share(s.down_mbps, s.contracted_down_mbps)}</div></> : '—'}</td>
                                        <td className="text-right tabular-nums">{s.up_mbps != null ? <><b>{num(s.up_mbps, lang)}</b> Mbit/s<div className="muted text-xs">{share(s.up_mbps, s.contracted_up_mbps)}</div></> : '—'}</td>
                                        <td className="text-right tabular-nums">{s.idle_ms != null ? `${num(s.idle_ms, lang)} ms` : '—'}</td>
                                        <td className="text-right tabular-nums">{s.loaded_down_ms != null ? `${num(Math.max(s.loaded_down_ms, s.loaded_up_ms ?? 0), lang)} ms` : '—'}</td>
                                        <td className="text-right tabular-nums">
                                            {s.latency_increase_ms != null ? <>+{num(s.latency_increase_ms, lang)} ms<div className={`text-xs font-medium ${gradeTone[s.latency_grade]}`}>{t('grade.' + s.latency_grade)}</div></> : '—'}
                                        </td>
                                        <td className="text-right tabular-nums">{s.bytes_used ? `${num(s.bytes_used / 1e6, lang, 0)} MB` : '—'}</td>
                                        <td>{t(s.trigger)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="muted mt-3 max-w-3xl text-xs">{t('speed_limits')}</p>
            </div>
        </div>
    );
}
