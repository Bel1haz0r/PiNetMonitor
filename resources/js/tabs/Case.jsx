import { useEffect, useState } from 'react';
import { api } from '../api';
import { dateOnly } from '../format';

const FIELDS = ['operator_name', 'subscriber_name', 'contract_number', 'service_address', 'contact_phone', 'contact_email'];
const EDITABLE = [...FIELDS, 'customer_type', 'monitor_session_id', 'event_date', 'submitted_at', 'responded_at', 'outcome', 'regulator_filed_at'];

function Letter({ kind, title, lang, t, version }) {
    const [letter, setLetter] = useState(null);
    const [copied, setCopied] = useState(false);
    useEffect(() => {
        api.get(`case/letter/${kind}?lang=${lang}`).then(setLetter).catch(() => {});
    }, [kind, lang, version]);

    const copy = () => navigator.clipboard?.writeText(letter.text).then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    });
    const href = letter?.text ? 'data:text/plain;charset=utf-8,' + encodeURIComponent(letter.text) : null;

    return (
        <details className="card">
            <summary className="cursor-pointer font-semibold">{title}{letter && !letter.ready && <span className="muted ml-2 text-sm font-normal">— {t('not_ready')}</span>}</summary>
            {letter && !letter.ready && <p className="mt-2 text-sm">{letter.reason}</p>}
            {letter?.ready && (
                <>
                    <div className="mt-2 flex gap-2">
                        <button className="btn" onClick={copy}>{copied ? t('copied') : t('copy')}</button>
                        <a className="btn" href={href} download={`${kind}-${lang}.txt`}>{t('download')}</a>
                    </div>
                    <pre className="mt-3 overflow-x-auto rounded-md bg-slate-50 p-3 text-xs leading-relaxed whitespace-pre-wrap dark:bg-slate-950">{letter.text}</pre>
                </>
            )}
        </details>
    );
}

export default function Case({ t, lang }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState(null);
    const [sessions, setSessions] = useState([]);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState(null);
    const [note, setNote] = useState('');
    const [version, setVersion] = useState(0);

    const accept = (payload) => {
        setData(payload);
        setForm(Object.fromEntries(EDITABLE.map((k) => [k, payload.case[k] ?? ''])));
        setVersion((v) => v + 1);
    };
    useEffect(() => {
        api.get('case').then(accept).catch((e) => setError(e.message));
        api.get('sessions').then(setSessions).catch(() => {});
    }, []);

    if (!form) return <div className="card muted text-sm">{error ?? '…'}</div>;

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });
    const save = (e) => {
        e.preventDefault();
        setError(null);
        const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
        api.put('case', body).then((payload) => {
            accept(payload);
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
        }).catch((err) => setError(err.message));
    };
    const addNote = (e) => {
        e.preventDefault();
        if (!note.trim()) return;
        api.post('case/journal', { entry_date: new Date().toISOString().slice(0, 10), text: note }).then((payload) => {
            setData(payload);
            setNote('');
        }).catch((err) => setError(err.message));
    };
    const newCase = () => api.post('case/new').then(accept);

    const next = data.next_action;
    const statusTone = { done: 'text-ok', met: 'text-ok', upcoming: 'text-sky-700 dark:text-sky-400', missed: 'text-bad', unsettled: 'text-warn' };
    const daysText = (m) => (m.days_left == null ? '' : m.days_left === 0 ? t('today') : m.days_left > 0 ? `${m.days_left} ${t('days_left')}` : `${-m.days_left} ${t('days_ago')}`);

    return (
        <div className="space-y-4">
            <p className="muted max-w-3xl text-sm">{t('case_intro')}</p>
            {data.upstream_count === 0 && <div className="card border-l-8 border-l-warn text-sm">{t('no_upstream')}</div>}

            <div className="card border-l-8 border-l-sky-600">
                <div className="muted text-xs font-medium">{t('next_action')}</div>
                {next ? (
                    <div className="mt-0.5 text-lg font-semibold">
                        {t('step.' + next.step)} {dateOnly(next.date, lang)} <span className="muted text-sm font-normal">— {daysText(next)}</span>
                    </div>
                ) : <div className="mt-0.5">{t('nothing_outstanding')}</div>}
            </div>

            <form onSubmit={save} className="grid gap-4 lg:grid-cols-2">
                <div className="card space-y-3">
                    <h2 className="font-semibold">{t('subscriber')}</h2>
                    {FIELDS.map((k) => (
                        <div key={k}>
                            <label className="label" htmlFor={k}>{t(k)}</label>
                            <input id={k} className="w-full" type={k === 'contact_email' ? 'email' : 'text'} value={form[k]} onChange={set(k)} />
                        </div>
                    ))}
                    <div>
                        <label className="label" htmlFor="customer_type">{t('customer_type')}</label>
                        <select id="customer_type" className="w-full" value={form.customer_type} onChange={set('customer_type')}>
                            <option value="consumer">{t('consumer')}</option>
                            <option value="non_consumer">{t('non_consumer')}</option>
                        </select>
                    </div>
                </div>

                <div className="card space-y-3">
                    <h2 className="font-semibold">{t('procedure')}</h2>
                    <div>
                        <label className="label" htmlFor="monitor_session_id">{t('case_session')}</label>
                        <select id="monitor_session_id" className="w-full" value={form.monitor_session_id} onChange={set('monitor_session_id')}>
                            <option value="">—</option>
                            {sessions.map((s) => <option key={s.id} value={s.id}>{s.name} — {s.upstream_count} ⨯ {t('domain.upstream_path')}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="label" htmlFor="event_date">{t('event_date')}</label>
                        <input id="event_date" type="date" value={form.event_date} onChange={set('event_date')} />
                        <span className="muted ml-2 text-xs">{t('event_auto')}{data.derived_event_date ? ` (${dateOnly(data.derived_event_date, lang)})` : ''}</span>
                    </div>
                    <div>
                        <label className="label" htmlFor="submitted_at">{t('submitted_at')}</label>
                        <input id="submitted_at" type="date" value={form.submitted_at} onChange={set('submitted_at')} />
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <div>
                            <label className="label" htmlFor="responded_at">{t('responded_at')}</label>
                            <input id="responded_at" type="date" value={form.responded_at} onChange={set('responded_at')} />
                        </div>
                        <div>
                            <label className="label" htmlFor="outcome">{t('outcome')}</label>
                            <select id="outcome" value={form.outcome} onChange={set('outcome')}>
                                <option value="">—</option>
                                <option value="accepted">{t('accepted')}</option>
                                <option value="rejected">{t('rejected')}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="label" htmlFor="regulator_filed_at">{t('regulator_filed_at')}</label>
                        <input id="regulator_filed_at" type="date" value={form.regulator_filed_at} onChange={set('regulator_filed_at')} />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 pt-1">
                        <button className="btn btn-primary" type="submit">{t('save')}</button>
                        <button className="btn" type="button" onClick={newCase}>{t('new_case')}</button>
                        {saved && <span className="text-sm text-ok">✓ {t('saved')}</span>}
                        {error && <span className="text-sm text-bad">{error}</span>}
                    </div>
                </div>
            </form>

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('timetable')}</h2>
                <table className="tbl w-full">
                    <tbody>
                        {data.milestones.map((m, i) => (
                            <tr key={i}>
                                <td className="w-32 whitespace-nowrap tabular-nums">{m.date ? dateOnly(m.date, lang) : '—'}</td>
                                <td>
                                    <div className={m.is_deadline ? 'font-medium' : ''}>{t('step.' + m.step)}</div>
                                    {m.source && <div className="muted text-xs">{m.days} {t('days_rule')} — {m.source}</div>}
                                    {m.provisional && <div className="text-xs text-warn">{t('provisional')}</div>}
                                    {m.status === 'unsettled' && <div className="text-xs text-warn">{t('unsettled_hint')}</div>}
                                </td>
                                <td className={`text-right text-sm whitespace-nowrap ${statusTone[m.status]}`}>
                                    {t('status.' + m.status)}{m.is_deadline && m.status !== 'met' && m.days_left != null ? ` · ${daysText(m)}` : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <h2 className="pt-1 font-semibold">{t('letters')}</h2>
            <Letter kind="operator" title={t('letter_operator')} lang={lang} t={t} version={version} />
            <Letter kind="regulator" title={t('letter_regulator')} lang={lang} t={t} version={version} />

            <div className="card">
                <h2 className="mb-2 font-semibold">{t('journal')}</h2>
                <form onSubmit={addNote} className="mb-3 flex gap-2">
                    <input className="flex-1" aria-label={t('note_text')} placeholder={t('note_text')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <button className="btn" type="submit">{t('add_note')}</button>
                </form>
                {data.journal.length === 0 ? <p className="muted text-sm">—</p> : (
                    <table className="tbl w-full">
                        <tbody>
                            {data.journal.map((j) => (
                                <tr key={j.id}>
                                    <td className="w-32 whitespace-nowrap tabular-nums">{dateOnly(j.entry_date, lang)}</td>
                                    <td>{j.kind === 'note' ? j.text : <><b>{t('journal_kind.' + j.kind)}</b>{j.text ? ` — ${t(j.text)}` : ''}</>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <p className="muted text-xs">{t('disclaimer')}</p>
        </div>
    );
}
