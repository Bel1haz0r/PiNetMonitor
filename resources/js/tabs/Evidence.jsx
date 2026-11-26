import { useEffect, useState } from 'react';
import { api } from '../api';
import { dateTime, duration, percent } from '../format';
import { IncidentTable, Stat } from './Monitor';

export default function Evidence({ t, lang, status }) {
    const [sessions, setSessions] = useState([]);
    const [selected, setSelected] = useState(null);
    const [incidents, setIncidents] = useState([]);
    const [gaps, setGaps] = useState([]);
    const [chain, setChain] = useState(null);
    const [busy, setBusy] = useState(false);

    const loadSessions = () => api.get('sessions').then((rows) => {
        setSessions(rows);
        setSelected((current) => current ?? rows[0]?.id ?? null);
    }).catch(() => {});

    useEffect(() => { loadSessions(); }, [status?.session?.id]);

    useEffect(() => {
        if (!selected) return;
        setChain(null);
        api.get(`sessions/${selected}/incidents`).then(setIncidents).catch(() => {});
        api.get(`sessions/${selected}/gaps`).then(setGaps).catch(() => {});
    }, [selected]);

    const session = sessions.find((s) => s.id === selected);
    if (!session) return <div className="card muted text-sm">{t('none_recorded')}</div>;

    const verify = () => {
        setBusy(true);
        api.get(`sessions/${selected}/verify`).then(setChain).finally(() => setBusy(false));
    };
    const startNew = () => {
        if (window.confirm(t('new_session_confirm'))) {
            api.post('sessions').then(() => setTimeout(() => { setSelected(null); loadSessions(); }, 4000));
        }
    };
    const link = (what) => `/api/sessions/${selected}/export/${what}?lang=${lang}`;
    const verdictTone = { upstream: 'border-l-bad', clean: 'border-l-ok', local_only: 'border-l-warn', insufficient: 'border-l-idle' }[session.verdict];

    return (
        <div className="space-y-4">
            <div className="card flex flex-wrap items-end gap-3">
                <div>
                    <label className="label" htmlFor="session">{t('session')}</label>
                    <select id="session" value={selected} onChange={(e) => setSelected(Number(e.target.value))}>
                        {sessions.map((s) => <option key={s.id} value={s.id}>{s.name} ({t(s.status)})</option>)}
                    </select>
                </div>
                <div className="muted text-sm">{dateTime(session.started_at, lang)} — {session.ended_at ? dateTime(session.ended_at, lang) : '…'}</div>
                <button className="btn ml-auto" onClick={startNew} disabled={!status?.running}>{t('new_session')}</button>
            </div>

            <div className={`card border-l-8 ${verdictTone}`}>
                <p>{t('verdict.' + session.verdict)}</p>
                <p className="muted mt-1 text-sm">{t('evidence_quality')}: <b>{t('band.' + session.quality)}</b></p>
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label={t('monitored')} value={duration(session.monitored_ms, false)} hint={`${t('pauses')}: ${duration(session.gap_ms, false)} (${session.gap_count})`} />
                <Stat label={t('availability')} value={percent(session.upstream_availability, lang)} hint={t('availability_hint')} />
                <Stat label={t('upstream_outages')} value={session.upstream_count} tone={session.upstream_count ? 'bad' : 'ok'}
                    hint={session.upstream_count ? `${duration(session.upstream_ms, true, lang)} (${t('at_least')} ${duration(session.upstream_min_ms, true, lang)}, ${t('at_most')} ${duration(session.upstream_max_ms, true, lang)})` : null} />
                <Stat label={t('all_outages')} value={session.outage_count} hint={session.outage_count ? duration(session.outage_ms, true, lang) : null} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="card">
                    <h2 className="mb-2 font-semibold">{t('downloads')}</h2>
                    <div className="flex flex-wrap gap-2">
                        <a className="btn btn-primary" href={link('package.zip')}>{t('package')}</a>
                        <a className="btn" href={link('report.pdf')}>{t('report_pdf')}</a>
                        <a className="btn" href={link('report.html')} target="_blank" rel="noreferrer">{t('report_html')}</a>
                        <a className="btn" href={link('outages.csv')}>{t('outages_csv')}</a>
                        <a className="btn" href={link('measurements.csv')}>{t('measurements_csv')}</a>
                        <a className="btn" href={link('raw.jsonl')}>{t('raw')}</a>
                    </div>
                    <p className="muted mt-2 text-xs">{t('export_lang')}</p>
                </div>
                <div className="card">
                    <h2 className="mb-2 font-semibold">{t('integrity')}</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <button className="btn" onClick={verify} disabled={busy}>{t('verify')}</button>
                        {chain && (chain.valid
                            ? <span className="font-semibold text-ok">✓ {t('chain_valid')} — {chain.entries} {t('entries')}</span>
                            : <span className="font-semibold text-bad">✗ {t('chain_broken')} {t('at_line')} {chain.first_bad_line}: {chain.reason}</span>)}
                    </div>
                    {chain?.valid && <p className="muted mt-2 font-mono text-xs break-all">{t('last_hash')}: {chain.last_hash}</p>}
                    <p className="muted mt-2 text-xs">{t('chain_explain')}</p>
                </div>
            </div>

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('outages')}</h2>
                <IncidentTable incidents={incidents} t={t} lang={lang} />
            </div>

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('pauses')}</h2>
                {gaps.length === 0 ? <p className="muted text-sm">{t('none_recorded')}</p> : (
                    <div className="overflow-x-auto">
                        <table className="tbl w-full min-w-[480px]">
                            <thead><tr><th>{t('col.start')}</th><th>{t('col.end')}</th><th className="text-right">{t('col.duration')}</th><th>{t('col.cause')}</th></tr></thead>
                            <tbody>
                                {gaps.map((g) => (
                                    <tr key={g.id}>
                                        <td className="tabular-nums">{dateTime(g.started_at, lang)}</td>
                                        <td className="tabular-nums">{dateTime(g.ended_at, lang)}</td>
                                        <td className="text-right tabular-nums">{duration(g.duration_ms, false)}</td>
                                        <td>{t('gap_cause.' + g.cause)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
