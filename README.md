# F450 Email Registration

A WordPress plugin that lets visitors register custom `@f450.com` email accounts directly from the site through cPanel integration. It provides a self-service registration form, username availability checks, login/logout handling, and an admin management screen.

## Tech Stack

- **PHP** (single-file WordPress plugin)
- **WordPress** plugin API (shortcodes, AJAX actions, activation hooks)
- **MySQL** (custom database tables created on activation)
- **cPanel** integration for creating real email accounts

## Features

- Self-service email registration form via the `[f450_email_form]` shortcode.
- Live **username availability** checking before submission.
- Creates `@f450.com` mailboxes through cPanel integration.
- User **login / logout** flow with the `[f450_login_form]` shortcode and session handling.
- Admin menu screen for managing registrations and admin actions.
- Custom database tables created automatically on plugin activation.
- AJAX-driven for both logged-in and guest (nopriv) visitors.

## Requirements

- WordPress (with shortcode and AJAX support)
- PHP with session and cURL support
- A hosting account with **cPanel** access for email account creation
- MySQL database

## Installation

1. Copy `f450-email-registration.php` into a folder (e.g. `f450-email-registration`) under `/wp-content/plugins/`.
2. Activate **F450 Email Registration** from the **Plugins** menu — required database tables are created automatically on activation.
3. Configure the cPanel credentials/settings used by the plugin for email account creation.

## Usage

Place the shortcodes on any page or post:

```text
[f450_email_form]    Registration form for creating a new @f450.com account
[f450_login_form]    Login form for existing accounts
```

Manage registrations from the plugin's admin menu screen in the WordPress dashboard.

## License

GPL v2 or later (as declared in the plugin header).
