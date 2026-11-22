export const byId = (id) => document.getElementById(id);

export function node(tag, text, className) {
    const element = document.createElement(tag);
    if (text !== undefined && text !== null) element.textContent = text;
    if (className) element.className = className;
    return element;
}

export function feedback(element, message = '', error = false) {
    element.textContent = message;
    element.dataset.error = String(error);
}

export function clock(value) {
    return new Date(value).toLocaleTimeString([], {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

export function dateTime(value) {
    return value ? new Date(value).toLocaleString([], {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    }) : '—';
}

export const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

export async function request(url, { method = 'GET', data, signal } = {}) {
    const response = await fetch(url, {
        method, signal, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = Object.values(body.errors || {}).flat()[0];
        throw new Error(validation || body.message || ('Request failed (' + response.status + ').'));
    }
    return body;
}
