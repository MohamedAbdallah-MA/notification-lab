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
                <h1 data-i18n>Notification Lab</h1>
                <p class="muted brand-detail">
                    <bdi>{{ config('app.name') }}</bdi> ·
                    @if ($settings['firebase'])
                        <bdi>{{ $settings['firebase']['projectId'] }}</bdi>
                    @else
                        <span data-i18n>Firebase setup pending</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="header-tools">
            <div class="status-group">
                <span class="pill environment" data-i18n>{{ app()->environment() }}</span>
                <span class="pill" id="browser-state" data-i18n>Not listening</span>
            </div>
            <div class="preferences" role="group" aria-label="Display preferences" data-i18n-aria>
                <button id="language-toggle" type="button" lang="ar" aria-label="Switch to Arabic">العربية</button>
                <button id="theme-toggle" type="button" aria-label="Dark mode" aria-pressed="false" data-i18n-aria>
                    <span id="theme-symbol" aria-hidden="true">☾</span>
                    <span id="theme-label" data-i18n>Dark</span>
                </button>
            </div>
        </div>
    </header>

    <nav class="tabs" role="tablist" aria-label="Notifications" data-i18n-aria>
        <button id="inbox-tab" class="tab" type="button" role="tab" aria-controls="inbox-panel" aria-selected="true">
            <span data-i18n>FCM inbox</span> <span id="inbox-count" class="pill count">0</span>
        </button>
        <button id="database-tab" class="tab" type="button" role="tab" aria-controls="database-panel" aria-selected="false" tabindex="-1" data-i18n>
            Database notifications
        </button>
    </nav>

    <section class="inbox-layout" id="inbox-panel" role="tabpanel" aria-labelledby="inbox-tab">
        <aside class="sidebar" aria-label="Browser setup and subscriptions" data-i18n-aria>
            <section class="surface side-card">
                <h2 data-i18n>This browser</h2>
                <ul class="checks">
                    <li><span class="check" id="permission-check" data-state="pending" aria-hidden="true"></span><span id="permission-label" data-i18n>Notification permission</span></li>
                    <li><span class="check" id="worker-check" data-state="pending" aria-hidden="true"></span><span data-i18n>Service worker registered</span></li>
                    <li><span class="check" id="registration-check" data-state="pending" aria-hidden="true"></span><span data-i18n>{{ $settings['mode'] === 'fid' ? 'Installation registered' : 'Token issued' }}</span></li>
                </ul>

                <p class="muted field-caption" data-i18n>{{ $settings['mode'] === 'fid' ? 'Firebase installation ID' : 'FCM token' }}</p>
                <div class="registration">
                    <code id="registration-value" data-i18n>Awaiting registration</code>
                    <button id="copy-registration" class="square" type="button" aria-label="Copy registration" title="Copy" data-i18n-aria data-i18n-title disabled>▣</button>
                    <button id="refresh-registration" class="square" type="button" aria-label="Refresh registration" title="Refresh" data-i18n-aria data-i18n-title disabled>↻</button>
                </div>
                <button id="enable-browser" class="button-soft enable" type="button" data-i18n @disabled(!$settings['firebase'])>Enable notifications</button>
                <p id="browser-feedback" class="feedback" role="status" data-i18n>{{ $settings['firebaseError'] }}</p>
            </section>

            <section class="surface side-card">
                <div class="row between">
                    <h2 data-i18n>Topics</h2>
                    <span id="topic-count" class="muted" data-i18n>0 subscribed</span>
                </div>
                <form id="topic-form" class="topic-form">
                    <input id="topic-name" aria-label="Topic name" placeholder="order-updates" dir="ltr" maxlength="900" data-i18n-aria required disabled>
                    <button id="topic-add" class="square" type="submit" aria-label="Subscribe" title="Subscribe" data-i18n-aria data-i18n-title disabled>+</button>
                </form>
                <p id="topic-feedback" class="feedback" role="status"></p>
                <ul id="topic-list" class="topic-list" aria-label="Subscribed topics" data-i18n-aria></ul>
            </section>
        </aside>

        <section class="surface incoming" aria-labelledby="incoming-title">
            <header class="panel-header">
                <h2 id="incoming-title" data-i18n>Incoming</h2>
                <button id="open-send" class="button-soft" type="button" aria-expanded="false" aria-controls="send-form" disabled>
                    <span class="send-icon" aria-hidden="true">➤</span> <span data-i18n>Send test</span>
                </button>
            </header>

            <form id="send-form" class="composer" hidden>
                <div class="form-fields">
                    <label class="target-field"><span data-i18n>To</span>
                        <select id="send-target">
                            <option value="browser" data-i18n>This browser</option>
                        </select>
                    </label>
                    <label><span data-i18n>Title</span><input id="send-title" placeholder="Order shipped" dir="auto" maxlength="160" data-i18n-placeholder required></label>
                    <label><span data-i18n>Body</span><input id="send-body" placeholder="Order #1042 is on its way" dir="auto" maxlength="1024" data-i18n-placeholder></label>
                </div>
                <details>
                    <summary data-i18n>Advanced</summary>
                    <label><span data-i18n>Data (JSON)</span>
                        <textarea id="send-data" rows="3" dir="ltr" spellcheck="false" placeholder='{"order_id":"1042"}'></textarea>
                    </label>
                </details>
                <div class="row between">
                    <p id="send-feedback" class="feedback" role="status"></p>
                    <button id="send-button" class="button-primary" type="submit" data-i18n>Send</button>
                </div>
            </form>

            <p id="receipt-feedback" class="receipt-feedback" role="status" hidden></p>
            <div id="feed" class="feed"></div>
            <footer class="panel-footer row between">
                <span class="muted" id="storage-caption" data-i18n>Stored in this browser</span>
                <button id="clear-inbox" class="text-button" type="button" data-i18n disabled>Clear inbox</button>
            </footer>
        </section>
    </section>

    <section class="surface database" id="database-panel" role="tabpanel" aria-labelledby="database-tab" hidden>
        <header class="panel-header database-header">
            <h2 data-i18n>Database notifications</h2>
            <div class="row">
                <span class="pill" id="database-live" data-i18n>Manual refresh</span>
                <button id="database-refresh" type="button"><span aria-hidden="true">↻</span> <span data-i18n>Refresh</span></button>
            </div>
        </header>
        <form id="database-filters" class="database-filters">
            <input id="database-search" aria-label="Notification or recipient ID" placeholder="Search by ID" data-i18n-aria data-i18n-placeholder>
            <input id="database-type" aria-label="Notification type" placeholder="Filter type" data-i18n-aria data-i18n-placeholder>
            <select id="database-status" aria-label="Read status" data-i18n-aria>
                <option value="" data-i18n>Any status</option>
                <option value="unread" data-i18n>Unread</option>
                <option value="read" data-i18n>Read</option>
            </select>
            <button type="submit" data-i18n>Apply</button>
        </form>
        <p id="database-feedback" class="feedback database-feedback" role="status"></p>
        <div class="table-wrap" role="region" aria-label="Notification records" data-i18n-aria tabindex="0">
            <table>
                <thead><tr><th scope="col" data-i18n>Type</th><th scope="col" data-i18n>Title</th><th scope="col" data-i18n>Recipient</th><th scope="col" data-i18n>Status</th><th scope="col" data-i18n>Created</th><th scope="col"><span class="sr-only" data-i18n>Details</span></th></tr></thead>
                <tbody id="database-rows"></tbody>
            </table>
        </div>
        <footer class="panel-footer row between">
            <span id="database-updated" class="muted" role="status"></span>
            <div class="row pagination">
                <button id="database-prev" type="button" data-i18n disabled>Previous</button>
                <span id="database-page" class="muted" data-i18n>Page 1</span>
                <button id="database-next" type="button" data-i18n disabled>Next</button>
            </div>
        </footer>
    </section>
    <noscript><p class="feedback">Notification Lab requires JavaScript.</p></noscript>
</main>
</body>
</html>
