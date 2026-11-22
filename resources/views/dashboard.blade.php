<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Notification Lab</title>
    <link rel="stylesheet" href="{{ route('notification-lab.asset', ['asset' => 'lab.css'], false) }}">
    <script type="module" src="{{ route('notification-lab.asset', ['asset' => 'app.js'], false) }}"></script>
</head>
<body>
<main class="lab" id="lab" data-settings="{{ json_encode($settings, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) }}">
    <header class="header">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            <div>
                <h1>Notification Lab</h1>
                <p class="muted brand-detail">
                    {{ config('app.name') }} · {{ $settings['firebase']['projectId'] ?? 'Firebase setup pending' }}
                </p>
            </div>
        </div>
        <div class="status-group">
            <span class="pill environment">{{ app()->environment() }}</span>
            <span class="pill" id="browser-state">Not listening</span>
        </div>
    </header>

    <nav class="tabs" role="tablist" aria-label="Notifications">
        <button id="inbox-tab" class="tab" type="button" role="tab" aria-controls="inbox-panel" aria-selected="true">
            FCM inbox <span id="inbox-count" class="pill count">0</span>
        </button>
        <button id="database-tab" class="tab" type="button" role="tab" aria-controls="database-panel" aria-selected="false" tabindex="-1">
            Database notifications
        </button>
    </nav>

    <section class="inbox-layout" id="inbox-panel" role="tabpanel" aria-labelledby="inbox-tab">
        <aside class="sidebar" aria-label="Browser setup and subscriptions">
            <section class="surface side-card">
                <h2>This browser</h2>
                <ul class="checks">
                    <li><span class="check" id="permission-check" data-state="pending" aria-hidden="true"></span><span id="permission-label">Notification permission</span></li>
                    <li><span class="check" id="worker-check" data-state="pending" aria-hidden="true"></span>Service worker registered</li>
                    <li><span class="check" id="registration-check" data-state="pending" aria-hidden="true"></span>{{ $settings['mode'] === 'fid' ? 'Installation registered' : 'Token issued' }}</li>
                </ul>

                <p class="muted field-caption">{{ $settings['mode'] === 'fid' ? 'Firebase installation ID' : 'FCM token' }}</p>
                <div class="registration">
                    <code id="registration-value">Awaiting registration</code>
                    <button id="copy-registration" class="square" type="button" aria-label="Copy registration" title="Copy" disabled>▣</button>
                    <button id="refresh-registration" class="square" type="button" aria-label="Refresh registration" title="Refresh" disabled>↻</button>
                </div>
                <button id="enable-browser" class="button-soft enable" type="button" @disabled(!$settings['firebase'])>Enable notifications</button>
                <p id="browser-feedback" class="feedback" role="status">{{ $settings['firebaseError'] }}</p>
            </section>

            <section class="surface side-card">
                <div class="row between">
                    <h2>Topics</h2>
                    <span id="topic-count" class="muted">0 subscribed</span>
                </div>
                <form id="topic-form" class="topic-form">
                    <input id="topic-name" aria-label="Topic name" placeholder="order-updates" maxlength="900" required disabled>
                    <button id="topic-add" class="square" type="submit" aria-label="Subscribe" title="Subscribe" disabled>+</button>
                </form>
                <p id="topic-feedback" class="feedback" role="status"></p>
                <ul id="topic-list" class="topic-list" aria-label="Subscribed topics"></ul>
            </section>
        </aside>

        <section class="surface incoming" aria-labelledby="incoming-title">
            <header class="panel-header">
                <h2 id="incoming-title">Incoming</h2>
                <div class="panel-actions">
                    <div id="filters" class="segment" role="group" aria-label="Filter incoming">
                        <button type="button" data-filter="all" aria-pressed="true">All</button>
                        <button type="button" data-filter="direct" aria-pressed="false">Direct</button>
                        <button type="button" data-filter="topic" aria-pressed="false">Topic</button>
                    </div>
                    <button id="open-send" class="button-soft" type="button" aria-expanded="false" aria-controls="send-form" disabled>➤ <span>Send test</span></button>
                </div>
            </header>

            <form id="send-form" class="composer" hidden>
                <div class="form-fields">
                    <label class="target-field">To
                        <select id="send-target">
                            <option value="browser">This browser</option>
                        </select>
                    </label>
                    <label>Title <input id="send-title" placeholder="Order shipped" maxlength="160" required></label>
                    <label>Body <input id="send-body" placeholder="Order #1042 is on its way" maxlength="1024"></label>
                </div>
                <details>
                    <summary>Advanced</summary>
                    <label>Data (JSON)
                        <textarea id="send-data" rows="3" placeholder='{"order_id":"1042"}'></textarea>
                    </label>
                </details>
                <div class="row between">
                    <p id="send-feedback" class="feedback" role="status"></p>
                    <button id="send-button" class="button-primary" type="submit">Send</button>
                </div>
            </form>

            <p id="receipt-feedback" class="receipt-feedback" role="status" hidden></p>
            <div id="feed" class="feed"></div>
            <footer class="panel-footer row between">
                <span class="muted" id="storage-caption">Stored in this browser</span>
                <button id="clear-inbox" class="text-button" type="button" disabled>Clear inbox</button>
            </footer>
        </section>
    </section>

    <section class="surface database" id="database-panel" role="tabpanel" aria-labelledby="database-tab" hidden>
        <header class="panel-header database-header">
            <h2>Database notifications</h2>
            <div class="row">
                <span class="pill" id="database-live">Manual refresh</span>
                <button id="database-refresh" type="button">↻ <span>Refresh</span></button>
            </div>
        </header>
        <form id="database-filters" class="database-filters">
            <input id="database-search" aria-label="Notification or recipient ID" placeholder="Search by ID">
            <input id="database-type" aria-label="Notification type" placeholder="Filter type">
            <select id="database-status" aria-label="Read status">
                <option value="">Any status</option>
                <option value="unread">Unread</option>
                <option value="read">Read</option>
            </select>
            <button type="submit">Apply</button>
        </form>
        <p id="database-feedback" class="feedback database-feedback" role="status"></p>
        <div class="table-wrap" role="region" aria-label="Notification records" tabindex="0">
            <table>
                <thead><tr><th scope="col">Type</th><th scope="col">Title</th><th scope="col">Recipient</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col"><span class="sr-only">Details</span></th></tr></thead>
                <tbody id="database-rows"></tbody>
            </table>
        </div>
        <footer class="panel-footer row between">
            <span id="database-updated" class="muted" role="status"></span>
            <div class="row pagination">
                <button id="database-prev" type="button" disabled>Previous</button>
                <span id="database-page" class="muted">Page 1</span>
                <button id="database-next" type="button" disabled>Next</button>
            </div>
        </footer>
    </section>
    <noscript><p class="feedback">Notification Lab requires JavaScript.</p></noscript>
</main>
</body>
</html>
