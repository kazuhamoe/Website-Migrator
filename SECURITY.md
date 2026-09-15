# Security & Transparency Policy 🛡️

**Website Migrator** was created by [kazuhamoe](https://github.com/kazuhamoe) as an open-source, developer-friendly utility to help webmasters migrate websites and MySQL databases between hostings or localhost smoothly without third-party plugin bloat.

---

## 🔒 100% Code Transparency & Anti-Backdoor Guarantee

We take security, data privacy, and community trust very seriously.

1. **No Backdoors, No Hidden Access, No Exploits:**
   - This project contains **ZERO** unauthorized access mechanisms, obfuscated strings, `eval(base64_decode(...))`, or reverse shells.
   - All PHP files are written in clean, standard, human-readable code. Anyone can inspect every single line before running it on their server.

2. **No Data Collection & Zero Telemetry:**
   - Website Migrator does **NOT** phone home, send external HTTP requests, or track any server info.
   - Your database credentials, domain names, and website contents stay strictly on your local machine and your own servers.

3. **No External Dependencies Required:**
   - Runs natively on PHP 7.4+ using standard extensions (`pdo_mysql`, `zip`).
   - Does not download external untrusted binaries or scripts during execution.

4. **Self-Destruct Cleanup Mechanism:**
   - `installer.php` features a **1-Click Self-Destruct** button that permanently removes itself, `migrator_package.zip`, and temporary SQL dumps once migration is completed, leaving zero lingering entry points on your hosting.

5. **Path Traversal Protection:**
   - Archive extraction strictly validates all paths and blocks directory traversal attempts (e.g. `../` or `..\`).

---

## 📌 Best Practices for Production Migration

- **Delete After Migration:** Always run the **Self-Destruct** button or manually delete `installer.php` and any `.zip`/`.sql` files once your site is up and running.
- **Temporary Access:** Do not leave `installer.php` publicly accessible without running the migration promptly.
- **Directory Permissions:** Keep file permissions set to `0644` and directories to `0755`.

---

## 🐛 Reporting a Vulnerability

If you discover a security vulnerability within Website Migrator, please do not open a public issue. Instead, report it privately via GitHub Security Advisories or by contacting:

- **Author:** [@kazuhamoe](https://github.com/kazuhamoe)
- **Email:** `kazuhamoe@users.noreply.github.com`

All legitimate security reports will be investigated and patched promptly.
