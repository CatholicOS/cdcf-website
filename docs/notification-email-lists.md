# Future Enhancement: Per-Endpoint Notification Email Lists

## Problem

All submission/referral notification emails currently go to the single WordPress admin email (`get_option('admin_email')`). Different stakeholders may want to receive notifications for different submission types — e.g., the technical council cares about project submissions, while community leads care about local group referrals.

## Current Behavior

Three endpoints send admin notification emails in `functions.php`:

| Endpoint | Line | Subject |
|----------|------|---------|
| `POST /cdcf/v1/refer-local-group` | ~1467 | `[CDCF] New Local Group Referral: {name}` |
| `POST /cdcf/v1/refer-community-project` | ~1639 | `[CDCF] New Community Project Referral: {name}` |
| `POST /cdcf/v1/submit-project` | ~1894 | `[CDCF] New Project Submission: {name}` |

Each uses the same pattern:

```php
$admin_email = get_option('admin_email');
// ... build $subject and $body ...
wp_mail($admin_email, $subject, $body);
```

## Proposed Change

Replace `get_option('admin_email')` with a per-endpoint WordPress option that falls back to the admin email. This allows setting a mailing list address (Google Group, Mailman, etc.) for each submission type independently.

### Implementation

**1. Define option names for each endpoint:**

```php
// In each endpoint callback, replace:
$admin_email = get_option('admin_email');

// With:
$admin_email = get_option('cdcf_notify_local_group', get_option('admin_email'));       // refer-local-group
$admin_email = get_option('cdcf_notify_community_project', get_option('admin_email')); // refer-community-project
$admin_email = get_option('cdcf_notify_project_submission', get_option('admin_email')); // submit-project
```

That's the one-line change per endpoint. The `wp_mail()` calls remain unchanged since `$admin_email` already holds the recipient.

**2. Set the options via WP-CLI or the database:**

```bash
# Point project submissions to a mailing list
wp option update cdcf_notify_project_submission "project-review@catholicdigitalcommons.org"

# Point community project referrals to a different list
wp option update cdcf_notify_community_project "community-review@catholicdigitalcommons.org"

# Point local group referrals to the community list too
wp option update cdcf_notify_local_group "community-review@catholicdigitalcommons.org"
```

When an option is not set, it falls back to the WordPress admin email (current behavior).

**3. (Optional) Add a settings page:**

Register a simple settings section under **Settings → CDCF Notifications** to let admins configure these addresses from the WordPress dashboard instead of WP-CLI.

## Mailing List Recommendations

The notification recipient can be any email address, but a mailing list is ideal because:

- **No WordPress accounts needed** — anyone can subscribe
- **Self-service subscribe/unsubscribe** — people manage their own preferences
- **Archive** — submissions are searchable in the list archive
- **Multiple recipients** — one address, many subscribers

Options for mailing list providers:
- **Google Groups** — free, easy setup, good for small teams
- **Mailman / GNU Mailman** — self-hosted, full-featured, free
- **Listmonk** — self-hosted, modern UI, open source
- **Groups.io** — hosted, free tier available

## Notes

- `wp_mail()` also accepts an array of addresses, so the option value could be a comma-separated list parsed with `array_map('trim', explode(',', $option))` if a mailing list service is not desired. However, a proper mailing list is preferred for manageability.
- The SMTP configuration (already set up via the `phpmailer_init` hook in `functions.php`) handles delivery — no changes needed there.
