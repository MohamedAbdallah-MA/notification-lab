# Notification Lab for Laravel

Test Firebase Cloud Messaging from your Laravel app while your mobile and frontend clients are still in development. Register a test browser, copy its FCM token into your existing notification flow, and inspect what actually arrives.

- **FCM inbox** — inspect notification titles, payloads, message IDs, and foreground or background receipt. Recent messages are stored in the browser.
- **Test sends** — target the current browser or a subscribed test topic. The UI shows when Firebase accepts the request and when the browser receives the message.
- **Topic management** — list, subscribe to, and unsubscribe from topics for the current browser.
- **Database notifications** — browse existing Laravel notification records, filter by type or read status, and inspect their payloads. Use **Refresh** anytime. An existing Reverb or Pusher connection enables automatic refresh on model creation.

## Installation

Run these commands inside the Laravel application that will host the lab. The package targets Laravel 10.50+ (within 10.x) and Laravel 11–13 and requires PHP 8.2 or later, subject to the installed Laravel version's PHP requirement. The UI assets ship with the package.

```bash
composer require --dev zunix/notification-lab
```

For development against a local checkout, configure it as a Composer path repository and require `zunix/notification-lab:@dev`.

## Quick start

### 1. Point the lab at Firebase

Enable the lab in the host application's `.env`. The default credentials directory is `storage/firebase` relative to the Laravel project. When it contains exactly one Service Account JSON file, the lab selects that file automatically.

```dotenv
NOTIFICATION_LAB_ENABLED=true
```

If your application stores the file elsewhere, or the default directory contains several service accounts, set the exact path. Absolute paths and paths relative to the Laravel project are supported.

```dotenv
NOTIFICATION_LAB_FIREBASE_CREDENTIALS=storage/app/private/firebase-service-account.json
```

Use the same Firebase project as your application's sender. If the service account sends on behalf of a different project, set `NOTIFICATION_LAB_FIREBASE_PROJECT_ID` to that target project ID. Keep the Service Account JSON in server-side storage.

### 2. Run the setup command

```bash
php artisan notification-lab:setup
```

Setup publishes `config/notification-lab.php` and saves the browser configuration to `storage/app/private/notification-lab-web.json`. It selects a sole Firebase Web App automatically, offers a choice if several exist, and can create a Web App with your confirmation. Discovery requires Firebase Management API access and permission to read the Web App. Creation requires permission to create one.

If your service account only has FCM permissions, copy the Web App configuration from Firebase Console into a plain JSON file and pass it to setup. The Web App must belong to the target Firebase project.

```bash
php artisan notification-lab:setup --web-config=/path/to/firebase-web.json
```

For scripted setup, use `--app-id` to select an existing Firebase Web App or `--create-web-app` to create one if the project has none. If your browser's push service requires a project-specific VAPID key, set `NOTIFICATION_LAB_VAPID_KEY` to the **public** key under **Project settings → Cloud Messaging → Web Push certificates**.

### 3. Authorize access

Define the `viewNotificationLab` gate in the `boot()` method of your application's `AppServiceProvider`. Use your existing developer or administrator authorization. This example authorizes one signed-in developer when `APP_ENV` is `local`. Replace the example email with your local account.

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Inside AppServiceProvider::boot()
Gate::define('viewNotificationLab', function (User $user): bool {
    return app()->environment('local') && $user->email === 'developer@example.com';
});
```

### 4. Register a browser and send a test

Sign in to the host application and open `/notification-lab` over HTTPS or on `localhost`. Select **Enable notifications** and allow the browser permission. Copy the token into your existing Laravel notification flow or select **Send test** for a quick round trip.

**FCM accepted** means Firebase accepted the send request. **Received in browser** appears when the lab receives the message. To inspect it, expand its row in **FCM inbox**. Use **Clear inbox** to clear the recent browser history.

## Switch Firebase projects

Change the credentials path in `.env` and run `php artisan notification-lab:setup` again. Setup reloads the configured credentials path, refreshes Laravel's configuration cache if it exists, and discovers a Web App when the saved browser configuration belongs to a different project. Once setup succeeds, reload the lab to register the browser with the new project. Update the public VAPID key in `.env` if you use a project-specific key.

## Topics

The **Topics** panel shows the current browser's Firebase subscriptions. To enable test sends to topics, set a comma-separated allowlist in `.env` and subscribe the test browser to one of those topics.

```dotenv
NOTIFICATION_LAB_TEST_TOPICS=qa-alerts,order-updates-test
```

A test send to a topic reaches every subscriber to that topic. Use dedicated test topics for isolated checks. Token registration is the default. Set `NOTIFICATION_LAB_REGISTRATION=fid` if your sending flow targets Firebase Installation IDs.

## Database notifications

The database tab reads the application's existing Laravel notification table. It supports filtering by notification or recipient ID, type, and read status. Expand a row to view the stored payload. For a custom notification model, set `database.model` in `config/notification-lab.php` to the model that creates the records.

**Reverb and Pusher are optional.** Press **Refresh** to reload the table with the current filters on any installation. For automatic updates, the lab uses the application's default Reverb or Pusher connection when configured. To select another configured connection, set `NOTIFICATION_LAB_BROADCAST_CONNECTION` to its name. Set `NOTIFICATION_LAB_BROADCAST=false` to use manual refresh only.

The observer publishes a private refresh signal after an Eloquent create commits. The browser reads the updated records through the authorized endpoint. For writes that bypass the Eloquent `created` event, use **Refresh**.

The default reader exposes the application's entire notification table to users who pass the gate. For tenant-scoped access, bind a subclass of `NotificationLab\DatabaseNotifications` in the service container and scope its `query()` to the authorized tenant.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| `/notification-lab` returns 404 | Set `NOTIFICATION_LAB_ENABLED=true` and check the path in the published config. |
| The dashboard returns 403 | Sign in to the host application and check the configured authorization gate. |
| Setup cannot discover a Web App | Check Firebase Management API access and service account permissions, or rerun with `--web-config`. |
| Browser registration fails | Check the browser notification permission, the secure origin, and the project's public VAPID key. |
| Firebase reports a sender mismatch | Check that the Web App and the send request target the same Firebase project, then register the browser again. |
| Firebase accepted the test and the inbox is still waiting | Check that the recipient is this browser's current registration or a topic it subscribes to. Then check browser permissions and the service worker status. |
| The database tab shows **Manual refresh** | Use **Refresh**, or check that the configured broadcaster is running and reachable from the browser if you want live updates. |

If the host application caches configuration or routes, rebuild those caches after changing the lab configuration.

## Develop the UI

Run these commands from the package checkout when editing the JavaScript or CSS. Keep the rebuilt `dist/` files in releases.

```bash
npm ci
npm run build
```

## License

Released under the [MIT License](LICENSE).
