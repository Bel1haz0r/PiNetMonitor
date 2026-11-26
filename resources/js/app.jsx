import { StrictMode, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { api } from './api';
import { COLORS } from './format';
import { translator } from './i18n';
import Case from './tabs/Case';
import Evidence from './tabs/Evidence';
import Monitor from './tabs/Monitor';
import Settings from './tabs/Settings';
import Speed from './tabs/Speed';

const TABS = { monitor: Monitor, evidence: Evidence, speed: Speed, case: Case, settings: Settings };

function stored(key, fallback) {
    try {
        return window.localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function App() {
    const [lang, setLang] = useState(() => stored('lang', navigator.language?.startsWith('sr') ? 'sr' : 'en'));
    const [tab, setTab] = useState(() => (TABS[window.location.hash.slice(1)] ? window.location.hash.slice(1) : 'monitor'));
    const [status, setStatus] = useState(null);
    const [unreachable, setUnreachable] = useState(false);
    const t = useMemo(() => translator(lang), [lang]);

    useEffect(() => {
        let alive = true;
        const poll = () => api.get('status')
            .then((s) => { if (alive) { setStatus(s); setUnreachable(false); } })
            .catch(() => alive && setUnreachable(true));
        poll();
        const id = setInterval(poll, 1000);
        return () => { alive = false; clearInterval(id); };
    }, []);

    useEffect(() => {
        try { window.localStorage.setItem('lang', lang); } catch { /* private mode */ }
        document.documentElement.lang = lang;
    }, [lang]);

    useEffect(() => {
        if (window.location.hash !== '#' + tab) window.history.replaceState(null, '', '#' + tab);
    }, [tab]);

    useEffect(() => {
        const onHash = () => {
            const key = window.location.hash.slice(1);
            if (TABS[key]) setTab(key);
        };
        window.addEventListener('hashchange', onHash);
        return () => window.removeEventListener('hashchange', onHash);
    }, []);

    // The tab title carries the state, so it is visible without opening the page.
    const severity = status?.running ? status.live.severity : 'info';
    useEffect(() => {
        document.title = (status?.running ? t('state.' + status.live.state) : t('offline_title')) + ' — PiNetMonitor';
        const svg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='11' fill='${status?.running ? COLORS[severity] : 'none'}' stroke='${COLORS[severity]}' stroke-width='4'/></svg>`;
        document.querySelector("link[rel='icon']")?.setAttribute('href', 'data:image/svg+xml,' + encodeURIComponent(svg));
    }, [status?.running, status?.live?.state, severity, t]);

    const Active = TABS[tab];

    return (
        <div className="mx-auto max-w-6xl px-4 pb-10">
            <header className="flex flex-wrap items-center gap-x-6 gap-y-2 py-4">
                <div className="flex items-center gap-2 text-lg font-semibold">
                    <span className="inline-block h-3 w-3 rounded-full" style={{ background: status?.running ? COLORS[severity] : 'transparent', boxShadow: `0 0 0 2px ${COLORS[severity]}` }} />
                    PiNetMonitor
                </div>
                <nav className="flex flex-wrap gap-1" aria-label="Sections">
                    {Object.keys(TABS).map((key) => (
                        <button
                            key={key} onClick={() => setTab(key)} aria-current={tab === key ? 'page' : undefined}
                            className={`cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800'}`}
                        >
                            {t('tabs.' + key)}
                        </button>
                    ))}
                </nav>
                <div className="ml-auto flex overflow-hidden rounded-md border border-slate-300 text-xs font-medium dark:border-slate-700" role="group" aria-label="Language">
                    {['en', 'sr'].map((code) => (
                        <button
                            key={code} onClick={() => setLang(code)} aria-pressed={lang === code}
                            className={`cursor-pointer px-2.5 py-1 uppercase ${lang === code ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'}`}
                        >
                            {code}
                        </button>
                    ))}
                </div>
            </header>

            {unreachable && <div className="card mb-4 border-l-8 border-l-bad text-sm">API unreachable — is the web server on the Pi running?</div>}
            <main><Active status={status} t={t} lang={lang} /></main>
        </div>
    );
}

createRoot(document.getElementById('app')).render(<StrictMode><App /></StrictMode>);
