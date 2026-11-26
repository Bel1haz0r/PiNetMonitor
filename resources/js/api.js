async function request(method, path, body) {
    const res = await fetch('/api/' + path, {
        method,
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) {
        let message = res.status + ' ' + res.statusText;
        try {
            const data = await res.json();
            if (data.errors) message = Object.values(data.errors).flat().join(' ');
            else if (data.message) message = data.message;
        } catch { /* not JSON */ }
        throw new Error(message);
    }
    return res.json();
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body ?? {}),
    put: (path, body) => request('PUT', path, body),
};
