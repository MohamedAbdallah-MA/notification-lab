import { databasePanel } from './database.js';
import { browserMessaging } from './fcm.js';
import { inboxStorage } from './storage.js';
import { byId, clock, feedback, node, request } from './ui.js';

const settings = JSON.parse(byId('lab').dataset.settings);
const database = databasePanel(settings);
const inboxTab = byId('inbox-tab');
const databaseTab = byId('database-tab');

function selectTab(tab) {
    const showDatabase = tab === databaseTab;
    for (const button of [inboxTab, databaseTab]) {
        button.setAttribute('aria-selected', String(button === tab));
        button.tabIndex = button === tab ? 0 : -1;
    }
    byId('inbox-panel').hidden = showDatabase;
    byId('database-panel').hidden = !showDatabase;
    database.show(showDatabase);
}

for (const tab of [inboxTab, databaseTab]) {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const target = event.key === 'Home' ? inboxTab
            : event.key === 'End' ? databaseTab : tab === inboxTab ? databaseTab : inboxTab;
        selectTab(target);
        target.focus();
    });
}

const feed = byId('feed');
const enable = byId('enable-browser');
const browserFeedback = byId('browser-feedback');
const topicFeedback = byId('topic-feedback');
const browserStorage = settings.firebase && 'indexedDB' in window ? inboxStorage(settings.firebase) : null;
const relay = settings.firebase && 'BroadcastChannel' in window
    ? new BroadcastChannel('notification-lab:' + settings.firebase.appId) : null;

const messages = new Map();
const open = new Set();
const fresh = new Set();
let currentFilter = 'all';
let registration;
let pendingTest;

function renderFeed() {
    const focused = document.activeElement?.dataset?.messageId;
    feed.replaceChildren();
    const shown = [...messages.values()]
        .filter((item) => currentFilter === 'all' || item.source === currentFilter)
        .sort((left, right) => right.receivedAt - left.receivedAt);
    byId('inbox-count').textContent = String(messages.size);
    byId('clear-inbox').disabled = messages.size === 0;

    if (!shown.length) {
        const empty = node('div', null, 'empty');
        empty.append(node('strong', registration ? 'Waiting for notifications' : 'No notifications yet'));
        empty.append(node('span', currentFilter !== 'all' && messages.size
            ? 'No messages match this filter.'
            : registration ? 'Send a test or trigger a notification from your application.'
                : 'Enable notifications to listen from this browser.'));
        feed.append(empty);
        return;
    }

    for (const item of shown) {
        const article = node('article', null, 'message' + (fresh.has(item.id) ? ' is-new' : ''));
        const toggle = node('button', null, 'message-toggle');
        toggle.type = 'button';
        toggle.dataset.messageId = item.id;
        toggle.setAttribute('aria-expanded', String(open.has(item.id)));
        const text = node('span');
        text.append(node('strong', item.title, 'message-title'));
        if (item.body) text.append(node('span', item.body, 'muted'));

        const meta = node('span', null, 'message-meta');
        if (item.testId) meta.append(node('span', 'Test', 'pill count'));
        meta.append(node('span', item.topic ? '#' + item.topic : item.source === 'direct' ? 'Direct' : 'Unknown',
            'pill' + (item.topic ? ' topic' : '')));
        meta.append(node('time', clock(item.receivedAt), 'timestamp'));
        toggle.append(text, meta);

        const details = node('dl', null, 'message-details');
        details.hidden = !open.has(item.id);
        const detail = (label, value) => {
            const cell = node('dd');
            cell.append(value);
            details.append(node('dt', label), cell);
        };
        detail('Received', item.mode);
        detail('Message ID', node('code', item.id));
        detail('Data', node('pre', JSON.stringify(item.data, null, 2)));

        toggle.addEventListener('click', () => {
            details.hidden = !details.hidden;
            toggle.setAttribute('aria-expanded', String(!details.hidden));
            if (details.hidden) open.delete(item.id);
            else open.add(item.id);
            fresh.delete(item.id);
            article.classList.remove('is-new');
        });
        article.append(toggle, details);
        feed.append(article);
        if (item.id === focused) toggle.focus({ preventScroll: true });
    }
}

function setReceipt(state, text) {
    const receipt = byId('receipt-feedback');
    receipt.dataset.state = state;
    receipt.textContent = text;
    receipt.hidden = !text;
}

function remember(item, isNew = false) {
    if (messages.has(item.id)) return;
    messages.set(item.id, item);
    if (isNew) fresh.add(item.id);
    if (messages.size > 100) {
        const oldest = [...messages.values()].reduce((previous, current) => current.receivedAt < previous.receivedAt ? current : previous);
        messages.delete(oldest.id);
        fresh.delete(oldest.id);
        open.delete(oldest.id);
    }
}

function received(item, persist = true) {
    remember(item, true);
    if (pendingTest && item.testId === pendingTest.id) {
        pendingTest.received = true;
        setReceipt('received', 'Received in browser at ' + clock(item.receivedAt) + ' · ' + item.mode);
        open.add(item.id);
    }

    renderFeed();
    if (persist) {
        browserStorage?.save(item).catch(storageUnavailable);
        relay?.postMessage({ type: 'received', item });
    }
}

function storageUnavailable() {
    byId('storage-caption').textContent = 'Stored until this page reloads';
}

function clear() {
    messages.clear();
    open.clear();
    fresh.clear();
    renderFeed();
}

byId('clear-inbox').addEventListener('click', async () => {
    await browserStorage?.clear().catch(storageUnavailable);
    clear();
    relay?.postMessage({ type: 'clear' });
});
relay?.addEventListener('message', ({ data }) => {
    if (data.type === 'received') received(data.item, false);
    if (data.type === 'clear') clear();
});
navigator.serviceWorker?.addEventListener('message', ({ data, source }) => {
    if (data?.type === 'notification-lab:received'
        && source?.scriptURL === new URL(settings.routes.worker, location.href).href) {
        received(data.message, false);
    }
});

if (browserStorage) {
    browserStorage.load().then((items) => {
        for (const item of items) remember(item);
        renderFeed();
    }).catch(storageUnavailable);
} else {
    storageUnavailable();
}
renderFeed();

byId('filters').addEventListener('click', (event) => {
    const button = event.target.closest('[data-filter]');
    if (!button) return;
    currentFilter = button.dataset.filter;
    for (const filter of byId('filters').querySelectorAll('button')) {
        filter.setAttribute('aria-pressed', String(filter === button));
    }
    renderFeed();
});

function setPermission(permission) {
    byId('permission-check').dataset.state = permission === 'granted' ? 'ready' : permission === 'denied' ? 'error' : 'pending';
    byId('permission-label').textContent = permission === 'denied' ? 'Notifications blocked' : 'Notification permission';
}

async function changeTopic(method, topic) {
    feedback(topicFeedback);
    try {
        const response = await request(settings.routes.topics, {
            method, data: topic === undefined ? undefined : { topic },
        });
        renderTopics(response.topics);
        return true;
    } catch (error) {
        feedback(topicFeedback, error.message, true);
        return false;
    }
}

function renderTopics(topics) {
    const list = byId('topic-list');
    list.replaceChildren();
    byId('topic-count').textContent = topics.length + ' subscribed';

    for (const topic of topics) {
        const entry = node('li', '#' + topic, 'pill topic');
        const remove = node('button', '×', 'topic-remove');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Unsubscribe from ' + topic);
        remove.addEventListener('click', async () => {
            remove.disabled = true;
            await changeTopic('DELETE', topic);
            remove.disabled = false;
        });
        entry.append(remove);
        list.append(entry);
    }

    const select = byId('send-target');
    const selected = select.value;
    select.replaceChildren(new Option('This browser', 'browser'));
    for (const topic of topics.filter((name) => settings.testTopics.includes(name))) {
        select.append(new Option('Topic #' + topic, 'topic:' + topic));
    }
    if ([...select.options].some((option) => option.value === selected)) select.value = selected;
}

byId('topic-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = byId('topic-name');
    const topic = input.value.trim();
    if (!topic) return;
    const submit = byId('topic-add');
    submit.disabled = true;
    if (await changeTopic('POST', topic)) input.value = '';
    submit.disabled = false;
});

const connect = browserMessaging(settings, {
    permission: setPermission,
    workerReady() { byId('worker-check').dataset.state = 'ready'; },
    received,
});

async function prepareBrowser(prompt) {
    enable.disabled = true;
    byId('refresh-registration').disabled = true;
    feedback(browserFeedback, 'Registering this browser…');

    try {
        const id = await connect(prompt);
        await request(settings.routes.browser, { method: 'POST', data: { registration: id } });
        registration = id;
        byId('registration-check').dataset.state = 'ready';
        byId('registration-value').textContent = id.length > 20 ? id.slice(0, 8) + '…' + id.slice(-6) : id;
        byId('browser-state').textContent = 'Listening';
        byId('browser-state').classList.add('live');
        for (const control of ['copy-registration', 'topic-name', 'topic-add', 'open-send']) byId(control).disabled = false;
        enable.hidden = true;
        feedback(browserFeedback);
        renderFeed();
        await changeTopic('GET');
    } catch (error) {
        const blocked = error.code?.startsWith('messaging/');
        feedback(browserFeedback, blocked ? 'Firebase registration failed. Check the web configuration and VAPID key.' : error.message, true);
        if (!registration) enable.disabled = false;
    } finally {
        byId('refresh-registration').disabled = !registration;
    }
}

enable.addEventListener('click', () => prepareBrowser(true));
byId('refresh-registration').addEventListener('click', () => prepareBrowser(false));
byId('copy-registration').addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(registration);
        feedback(browserFeedback, 'Copied.');
    } catch {
        feedback(browserFeedback, 'Clipboard access was denied.', true);
    }
});

if (settings.firebase && 'Notification' in window) {
    setPermission(Notification.permission);
    if (Notification.permission === 'granted') prepareBrowser(false);
}

const sendForm = byId('send-form');
const sendToggle = byId('open-send');

sendToggle.addEventListener('click', () => {
    sendForm.hidden = !sendForm.hidden;
    sendToggle.setAttribute('aria-expanded', String(!sendForm.hidden));
    if (!sendForm.hidden) byId('send-target').focus();
});

sendForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const notice = byId('send-feedback');
    const raw = byId('send-data').value.trim();
    let data;

    try {
        data = raw ? JSON.parse(raw) : {};
        if (!data || Array.isArray(data) || typeof data !== 'object'
            || Object.values(data).some((value) => typeof value !== 'string')) {
            throw new Error();
        }
    } catch {
        feedback(notice, 'Data must be a JSON object with string values.', true);
        return;
    }

    const target = byId('send-target').value;
    const test = { id: crypto.randomUUID(), received: false };
    pendingTest = test;
    const send = byId('send-button');
    send.disabled = true;
    feedback(notice);
    setReceipt('sending', 'Sending…');

    try {
        const result = await request(settings.routes.send, {
            method: 'POST',
            data: {
                target: target === 'browser' ? 'browser' : 'topic',
                topic: target === 'browser' ? null : target.slice(6),
                title: byId('send-title').value.trim(),
                body: byId('send-body').value.trim(),
                data,
                test_id: test.id,
            },
        });

        if (!test.received) {
            setReceipt('accepted', 'FCM accepted · Waiting for receipt in this browser');
            byId('receipt-feedback').title = result.messageId || '';
        }
    } catch (error) {
        if (!test.received) setReceipt('error', error.message);
    } finally {
        send.disabled = false;
    }
});
