import Pusher from 'pusher-js';
import { byId, clock, csrfToken, dateTime, feedback, node, request } from './ui.js';

export function databasePanel(settings) {
    const rows = byId('database-rows');
    const notice = byId('database-feedback');
    const refresh = byId('database-refresh');
    const status = byId('database-live');
    const previous = byId('database-prev');
    const next = byId('database-next');
    const open = new Set();
    let page = 1;
    let filters = {};
    let visible = false;
    let refreshTimer;
    let controller;
    let live = false;

    function empty(text) {
        const row = node('tr');
        const cell = node('td', text, 'table-empty');
        cell.colSpan = 6;
        row.append(cell);
        rows.replaceChildren(row);
    }

    function render(items, available) {
        if (!items.length) {
            empty(available ? 'No notifications match these filters.' : 'The application notification table is missing.');
            return;
        }

        const fragment = document.createDocumentFragment();
        for (const item of items) {
            const row = node('tr');
            const type = String(item.type || 'Unknown').split('\\').at(-1);
            const typeCell = node('td');
            const code = node('code', type);
            code.title = item.type;
            typeCell.append(code);

            const state = node('td');
            state.append(node('span', item.readAt ? 'Read' : 'Unread', 'pill' + (item.readAt ? '' : ' unread')));

            const actions = node('td');
            const toggle = node('button', 'Details', 'text-button');
            toggle.type = 'button';
            toggle.setAttribute('aria-expanded', String(open.has(item.id)));
            actions.append(toggle);
            row.append(
                typeCell, node('td', item.title || '—'),
                node('td', String(item.recipientType || 'Recipient').split('\\').at(-1) + ' #' + item.recipientId),
                state, node('td', dateTime(item.createdAt), 'timestamp'), actions,
            );

            const detail = node('tr');
            detail.hidden = !open.has(item.id);
            const cell = node('td');
            cell.colSpan = 6;
            const id = node('p', 'Notification ID: ' + item.id, 'muted');
            cell.append(id, node('pre', JSON.stringify(item.data, null, 2)));
            detail.append(cell);
            toggle.addEventListener('click', () => {
                detail.hidden = !detail.hidden;
                toggle.setAttribute('aria-expanded', String(!detail.hidden));
                if (detail.hidden) open.delete(item.id);
                else open.add(item.id);
            });
            fragment.append(row, detail);
        }

        rows.replaceChildren(fragment);
    }

    async function load() {
        controller?.abort();
        const current = new AbortController();
        controller = current;
        refresh.disabled = true;
        const query = new URLSearchParams({ ...filters, page: String(page) });
        try {
            const result = await request(settings.routes.database + '?' + query, { signal: current.signal });
            render(result.items, result.available);
            previous.disabled = page <= 1;
            next.disabled = !result.hasMore;
            byId('database-page').textContent = 'Page ' + page;
            byId('database-updated').textContent = 'Updated ' + clock(Date.now());
            feedback(notice);
        } catch (error) {
            if (error.name !== 'AbortError') feedback(notice, error.message, true);
        } finally {
            if (controller === current) refresh.disabled = false;
        }
    }

    function scheduleRefresh() {
        if (!visible) return;
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(load, 140);
    }

    function connect() {
        const options = settings.broadcast;
        if (!options || live) return;
        live = true;
        status.textContent = 'Connecting…';

        const transport = {
            cluster: options.cluster,
            forceTLS: options.tls,
            channelAuthorization: {
                endpoint: settings.routes.broadcast,
                headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
            },
        };

        if (options.host) {
            Object.assign(transport, {
                wsHost: options.host,
                wsPort: options.port || 80,
                wssPort: options.port || 443,
                enabledTransports: ['ws', 'wss'],
            });
        }

        try {
            const connection = new Pusher(options.key, transport);
            const channel = connection.subscribe('private-' + options.channel);
            channel.bind('notification-lab.database.changed', scheduleRefresh);
            channel.bind('pusher:subscription_succeeded', () => {
                status.textContent = 'Live updates';
                status.classList.add('live');
                scheduleRefresh();
            });
            channel.bind('pusher:subscription_error', () => {
                status.textContent = 'Live updates unavailable';
                status.classList.remove('live');
            });
            connection.connection.bind('state_change', ({ current }) => {
                if (current === 'connected') return;
                status.textContent = current === 'connecting' ? 'Connecting…' : 'Manual refresh';
                status.classList.remove('live');
            });
        } catch {
            status.textContent = 'Live updates unavailable';
        }
    }

    refresh.addEventListener('click', load);
    previous.addEventListener('click', () => { page -= 1; load(); });
    next.addEventListener('click', () => { page += 1; load(); });
    byId('database-filters').addEventListener('submit', (event) => {
        event.preventDefault();
        page = 1;
        filters = {
            search: byId('database-search').value.trim(),
            type: byId('database-type').value.trim(),
            status: byId('database-status').value,
        };
        load();
    });

    return {
        show(active) {
            visible = active;
            if (!active) {
                clearTimeout(refreshTimer);
                return;
            }

            connect();
            load();
        },
    };
}
