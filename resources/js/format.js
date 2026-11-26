export function duration(ms, subSecond = true, lang = 'en') {
    if (ms == null) return '—';
    ms = Math.max(0, ms);
    if (ms < 60000) {
        if (subSecond && ms < 10000) return (ms / 1000).toFixed(1).replace('.', lang === 'sr' ? ',' : '.') + ' s';
        return Math.round(ms / 1000) + ' s';
    }
    let s = Math.round(ms / 1000);
    const parts = [];
    for (const [unit, size] of [['d', 86400], ['h', 3600], ['min', 60], ['s', 1]]) {
        if (s >= size) {
            parts.push(Math.floor(s / size) + ' ' + unit);
            s %= size;
        }
    }
    return parts.slice(0, 3).join(' ');
}

export function percent(value, lang = 'en') {
    if (value == null) return '—';
    // Never round up to a clean 100 when something was lost.
    const shown = value < 100 && value > 99.999 ? 99.999 : value;
    return shown.toFixed(3).replace('.', lang === 'sr' ? ',' : '.') + ' %';
}

export function num(value, lang = 'en', decimals = 1) {
    if (value == null) return '—';
    return Number(value).toFixed(decimals).replace('.', lang === 'sr' ? ',' : '.');
}

const pad = (n) => String(n).padStart(2, '0');

export function dateTime(value, lang = 'en') {
    if (!value) return '—';
    const d = new Date(typeof value === 'number' ? value * 1000 : value);
    const time = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    return lang === 'sr'
        ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}. ${time}`
        : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${time}`;
}

export function dateOnly(value, lang = 'en') {
    if (!value) return '—';
    const [y, m, d] = String(value).slice(0, 10).split('-');
    return lang === 'sr' ? `${d}.${m}.${y}.` : `${y}-${m}-${d}`;
}

export function clock(ms) {
    const d = new Date(ms);
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export const SEVERITY = {
    Ok: 'ok', MonitoringGap: 'info', SelfTest: 'info', WifiRoaming: 'info',
    IcmpFiltered: 'degraded', CaptivePortalSuspected: 'degraded', PacketLoss: 'degraded', HighLatency: 'degraded',
    HighJitter: 'degraded', DnsIspFailure: 'degraded', DnsGlobalFailure: 'degraded',
    AdapterDown: 'outage', GatewayDown: 'outage', CpeUpstreamUnreachable: 'outage', InternetDown: 'outage',
};

export const COLORS = { ok: '#2e9e6b', info: '#8a94a6', degraded: '#d99a1c', outage: '#d64545', none: '#cbd2dc' };
