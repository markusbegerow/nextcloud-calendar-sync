# Calendar Sync for Nextcloud

<img alt="nextcloud-calendar-sync" src="https://github.com/user-attachments/assets/a18dda70-c6c6-4d98-bb4a-8a81b1919eb4" />

**Calendar Sync** lets you connect your Nextcloud calendar to any CalDAV server - keeping both sides in sync automatically, every 30 minutes. Multiple external sources, configurable sync direction, and secure encrypted credential storage are all built in.

---

## Features

- **Bidirectional sync** - changes made on either side are reflected on the other
- **Multiple configurations** - connect to as many external CalDAV sources as you need
- **Sync direction control** - bidirectional, external → Nextcloud only, or Nextcloud → external only
- **Conflict resolution** - choose between "update with newer", "skip", or "keep both"
- **Content-based duplicate detection** - matches events by title to avoid duplicates
- **Scheduled sync** - runs automatically every 30 minutes in the background
- **Manual sync** - trigger a full sync with one click
- **Sync history** - view a log of recent sync runs with created/updated/deleted counts
- **Secure credentials** - passwords encrypted with Nextcloud's built-in `ICrypto` before storage
- **Auto-refresh** - configurable live status updates (1, 5, 10, 15 min, or custom)
- **Languages** - English 🇬🇧 · German 🇩🇪

---

## Requirements

| Dependency | Version |
|---|---|
| Nextcloud | 20 – 33 |
| PHP | 8.0+ |

---

## Installation

### From source (manual)

1. Clone or download this repository into your Nextcloud `custom_apps/` directory:
   ```bash
   cd /path/to/nextcloud/custom_apps
   git clone https://github.com/markusbegerow/nextcloud-calendar-sync calendar-sync
   ```
2. Enable the app in Nextcloud:
   ```bash
   php occ app:enable calendar-sync
   ```
3. Navigate to the **Sync** entry in the Nextcloud top navigation bar.

---

## Usage

1. Click **Add Configuration** to create a connection to an external CalDAV server.
2. Fill in the CalDAV server URL, username, and password. Use **Test Connection** to verify.
3. Choose a sync direction and how to handle duplicate events.
4. Enable the configuration and click **Sync Now** to run the first sync manually.
5. Background sync runs every 30 minutes automatically - check **Recent Sync History** for results.

### CalDAV URL examples

| Provider | URL format |
|---|---|
| Nextcloud | `https://cloud.example.com/remote.php/dav/calendars/user/calendar-name/` |
| Generic CalDAV | `https://example.com/dav/calendars/user/default/` |

---

## Security

- Passwords are encrypted with Nextcloud's `ICrypto` service before being written to the database. They are never logged or stored in plain text.
- All requests from the background job run within the Nextcloud security context of the owning user.

---

## License

[GPL v3](https://www.gnu.org/licenses/gpl-3.0.html) © [Markus Begerow](https://markus-begerow.de/linktree)

---

## 🙋‍♂️ Get Involved

If you encounter any issues or have questions:
- 🐛 [Report bugs](https://github.com/markusbegerow/nextcloud-calendar-sync/issues)
- 💡 [Request features](https://github.com/markusbegerow/nextcloud-calendar-sync/issues)
- ⭐ Star the repo if you find it useful!

## ☕ Support the Project

If you like this project, support further development with a repost or coffee:

<a href="https://www.linkedin.com/sharing/share-offsite/?url=https://github.com/MarkusBegerow/nextcloud-calendar-sync" target="_blank"> <img src="https://img.shields.io/badge/💼-Share%20on%20LinkedIn-blue" /> </a>

[![Buy Me a Coffee](https://img.shields.io/badge/☕-Buy%20me%20a%20coffee-yellow)](https://paypal.me/MarkusBegerow)

## 📬 Contact

- 🧑‍💻 [Markus Begerow](https://linkedin.com/in/markusbegerow)
- 💾 [GitHub](https://github.com/markusbegerow)
- ✉️ [Twitter](https://x.com/markusbegerow)
