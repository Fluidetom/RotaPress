=== RotaPress ===
Contributors: fluidetom
Tags: calendar, schedule, editorial, rota, recurring-events
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editorial rota calendar for WordPress — shared scheduling, recurring events, email reminders, and personal iCal feed.

== Description ==

RotaPress gives editorial teams a shared calendar to plan and track who publishes what, and when. It embeds a visual rota directly inside WordPress, where the work actually happens.

**Key features:**

* **Shared calendar** — month grid and list views, colour-coded by author.
* **Recurring events** — daily, weekly, or monthly, with a required end date. Edit a single occurrence, all future events, or an entire series.
* **Role-based access** — map any WordPress role to Admin, Edit, or Read permission without modifying core roles.
* **Email reminders** — automatic reminders via `wp_mail()` at configurable intervals (e.g. 7, 3, 1 days before). Fully customisable subject and body template with placeholders. Recipients can opt out per-event via a one-click link.
* **Personal iCal feed** — each user gets a private, token-secured feed URL compatible with Google Calendar, Apple Calendar, Outlook, and any iCal app.
* **Bulk actions** — select multiple events in list view to reassign or delete in one click.
* **Trash & restore** — deleted events go to a 30-day trash with full restore capability.
* **Translatable** — ships with a French translation; `.pot` file included for other languages.

RotaPress is a scheduling layer, not a publishing workflow tool. It answers the question "who is writing what this week?" — it does not control post statuses or article drafts.

== Installation ==

1. Upload the `rotapress` folder to `wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate **RotaPress** from the WordPress Plugins screen.
3. Go to **RotaPress → Settings** to configure role mapping, participants, and email reminders.

== Frequently Asked Questions ==

= Which WordPress roles can access the calendar? =

By default: Administrator (Admin), Editor (Edit), Author (Read). You can remap any WordPress role to any RotaPress permission level in **Settings → Role Mapping**. Capabilities are granted dynamically — no roles are modified in the database.

= How do email reminders work? =

RotaPress uses `wp_mail()` and WP-Cron to send reminders at configurable intervals before each event. For reliable delivery, install an SMTP plugin such as FluentSMTP, WP Mail SMTP, or Post SMTP. For reliable scheduling, configure a real server cron job instead of relying on WordPress pseudo-cron.

= Can I customise the reminder email? =

Yes. Go to **Settings → Reminder email template** to edit the subject and body. The body supports placeholders: `{title}`, `{assignee}`, `{date}`, `{notes}`, `{days}`, `{site}`, `{calendar_url}`, and `{no_reminder_url}`.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating keeps all data intact. Deleting removes all data by default. Enable **Keep all RotaPress data when the plugin is deleted** in Settings to preserve data across reinstalls.

= How do I set up the iCal feed? =

In the calendar view, click the **iCal Feed** button in the toolbar. Generate a feed URL and add it to any iCal-compatible calendar app. You can regenerate or revoke the token at any time.

= Are recurring events stored as individual posts? =

No. Only the parent event is stored. Instances are expanded on the fly from the recurrence rule when the calendar loads. Individually edited occurrences are stored as separate exception posts linked to the parent.

== Screenshots ==

1. Month grid view with colour-coded events per author.
2. List view with Year / Month / Today scope filters and bulk-select checkboxes.
3. Event creation modal with recurrence options.
4. Settings page — role mapping, participants, and email template.

== Changelog ==

= 1.2.0 =
* Added participant removal guard: when unchecking a participant in Settings who has upcoming events, a modal prompts to reassign, delete, or clear those events before saving.
* Added French (Belgium) translation (fr_BE), identical to fr_FR.

= 1.1.0 =
* Added per-event email reminder opt-out for assignees (one-click link in reminder email).
* Added test email tool in settings with dummy-data and real-event modes.
* Added Year / Month / Today filter buttons in list view.
* Added personal iCal feed with token management.
* Translatable — French translation included.
* Email subject and body template now translatable via standard .po/.mo files.
* Reminder email template section renamed for clarity.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
New feature: participant removal guard in Settings. No database changes — safe to upgrade directly.

= 1.1.0 =
New features: per-event reminder opt-out, iCal feed, list view filters, and test email tool. No database changes required — safe to upgrade directly.
