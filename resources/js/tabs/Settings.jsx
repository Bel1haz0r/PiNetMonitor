import { useEffect, useState } from 'react';
import { api } from '../api';

const LISTS = ['icmp_targets', 'tcp_targets', 'dns_public', 'speed_schedule'];
const TIMING = ['interval_stable_ms', 'interval_suspect_ms', 'interval_burst_ms', 'interval_incident_ms', 'interval_recovery_ms',
    'probe_timeout_ms', 'probe_timeout_fast_ms', 'deep_interval_s', 'gap_threshold_s'];

export default function Settings({ t }) {
    const [form, setForm] = useState(null);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);
    const [tool, setTool] = useState(undefined);

    const accept = (s) => setForm({ ...s, ...Object.fromEntries(LISTS.map((k) => [k, (s[k] ?? []).join('\n')])) });
    useEffect(() => {
        api.get('settings').then(accept).catch((e) => setError(e.message));
        api.get('speed/tool').then((r) => setTool(r.tool)).catch(() => setTool(null));
    }, []);
    if (!form) return <div className="card muted text-sm">{error ?? '…'}</div>;

    const set = (key) => (e) => setForm({ ...form, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
    const number = (v) => (v === '' || v == null ? null : Number(v));
    const save = (e) => {
        e.preventDefault();
        setError(null);
        setMessage(null);
        const body = { ...form };
        delete body.locale;
        LISTS.forEach((k) => { body[k] = String(form[k]).split('\n').map((x) => x.trim()).filter(Boolean); });
        [...TIMING, 'high_latency_ms', 'high_jitter_ms', 'speed_duration_s'].forEach((k) => { body[k] = Number(form[k]); });
        body.contracted_down_mbps = number(form.contracted_down_mbps);
        body.contracted_up_mbps = number(form.contracted_up_mbps);
        body.interface = form.interface ? form.interface : null;
        body.speedtest_server_id = number(form.speedtest_server_id);
        body.speedtest_accept_license = !!form.speedtest_accept_license;
        api.put('settings', body).then((s) => { accept(s); setMessage(t('settings_saved')); }).catch((err) => setError(err.message));
    };

    const Num = ({ k, step }) => (
        <div>
            <label className="label" htmlFor={k}>{t('s.' + k)}</label>
            <input id={k} className="w-full" type="number" step={step ?? 1} value={form[k] ?? ''} onChange={set(k)} />
        </div>
    );
    const List = ({ k, hint }) => (
        <div>
            <label className="label" htmlFor={k}>{t('s.' + k)}</label>
            <textarea id={k} className="w-full font-mono" rows="3" value={form[k]} onChange={set(k)} />
            <div className="muted text-xs">{t('s.' + hint)}</div>
        </div>
    );

    return (
        <form onSubmit={save} className="space-y-4">
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="card space-y-3">
                    <h2 className="font-semibold">{t('s.network')}</h2>
                    <div>
                        <label className="label" htmlFor="interface">{t('s.interface')}</label>
                        <input id="interface" className="w-full font-mono" value={form.interface ?? ''} onChange={set('interface')} />
                        <div className="muted text-xs">{t('s.interface_hint')}</div>
                    </div>
                    {List({ k: 'icmp_targets', hint: 'list_hint' })}
                    {List({ k: 'tcp_targets', hint: 'list_hint' })}
                    {List({ k: 'dns_public', hint: 'list_hint' })}
                </div>
                <div className="space-y-4">
                    <div className="card">
                        <h2 className="mb-3 font-semibold">{t('s.timing')}</h2>
                        <div className="grid grid-cols-2 gap-3">{TIMING.map((k) => <div key={k}>{Num({ k })}</div>)}</div>
                    </div>
                    <div className="card">
                        <h2 className="mb-3 font-semibold">{t('s.thresholds')}</h2>
                        <div className="grid grid-cols-2 gap-3">{Num({ k: 'high_latency_ms' })}{Num({ k: 'high_jitter_ms' })}</div>
                    </div>
                </div>
            </div>
            <div className="card">
                <h2 className="mb-3 font-semibold">{t('s.speed')}</h2>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {Num({ k: 'contracted_down_mbps', step: 'any' })}
                    {Num({ k: 'contracted_up_mbps', step: 'any' })}
                    {Num({ k: 'speed_duration_s' })}
                    <label className="flex items-center gap-2 self-end pb-1.5 text-sm">
                        <input type="checkbox" checked={!!form.speed_upload} onChange={set('speed_upload')} /> {t('s.speed_upload')}
                    </label>
                </div>
                <div className="mt-3 max-w-xs">{List({ k: 'speed_schedule', hint: 'schedule_hint' })}</div>
                <fieldset className="mt-4 space-y-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                    <legend className="label">{t('s.speed_backend')}</legend>
                    {['cloudflare', 'speedtest'].map((b) => (
                        <label key={b} className="flex items-start gap-2 text-sm">
                            <input className="mt-1" type="radio" name="speed_backend" value={b} checked={(form.speed_backend ?? 'cloudflare') === b} onChange={set('speed_backend')} />
                            <span>
                                <b>{t('s.backend_' + b)}</b>
                                <span className="muted block text-xs">{t('s.backend_' + b + '_hint')}</span>
                            </span>
                        </label>
                    ))}
                    {form.speed_backend === 'speedtest' && (
                        <div className="space-y-3 pl-6">
                            <div className={`text-sm ${tool === null ? 'text-bad' : 'muted'}`}>
                                {tool === undefined ? '…' : tool === null ? t('s.tool_missing') : <>{t('s.tool_found')}: <span className="font-mono text-xs">{tool.version}</span> ({t('backend.' + tool.kind)})</>}
                            </div>
                            {tool?.kind === 'sivel' && <div className="muted text-xs">{t('s.tool_sivel_note')}</div>}
                            {tool?.kind !== 'sivel' && (
                                <label className="flex items-start gap-2 text-sm">
                                    <input className="mt-1" type="checkbox" checked={!!form.speedtest_accept_license} onChange={set('speedtest_accept_license')} />
                                    <span>
                                        {t('s.speedtest_accept_license')}
                                        <span className="muted block text-xs">{t('s.license_hint')}</span>
                                    </span>
                                </label>
                            )}
                            <div className="max-w-xs">
                                {Num({ k: 'speedtest_server_id' })}
                                <div className="muted text-xs">{t('s.server_hint')}</div>
                            </div>
                        </div>
                    )}
                </fieldset>
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <button className="btn btn-primary" type="submit">{t('save')}</button>
                {message && <span className="text-sm text-ok">✓ {message}</span>}
                {error && <span className="text-sm text-bad">{error}</span>}
            </div>
        </form>
    );
}
