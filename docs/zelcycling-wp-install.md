# zelcycling.com — WordPress Install Spec

## Quick Run

```bash
# From any machine with network access to the VPS:
chmod +x scripts/zelcycling-wp-install.sh
./scripts/zelcycling-wp-install.sh
```

## What the script does (6 steps)

1. **Create domain** — Plesk REST API call to create `zelcycling.com` subscription
2. **Verify SSH** — Confirms SSH access and Plesk version
3. **Install WordPress** — Uses WP Toolkit CLI, falls back to manual WP-CLI
4. **Configure WordPress** — Permalinks (`/%postname%/`), timezone (Europe/Madrid), removes default content
5. **SSL certificate** — Requests Let's Encrypt via Plesk API (requires DNS A record)
6. **Verify** — Checks WP version, URLs, users, and HTTPS response

## Server Details

| Key | Value |
|---|---|
| VPS IP | `212.227.105.0` |
| Domain | `zelcycling.com` |
| OS | Ubuntu 24.04.4 LTS |
| Plesk | Obsidian 18.0.76 |

## Success Criteria

- [ ] Domain exists in Plesk
- [ ] WordPress accessible at `https://zelcycling.com`
- [ ] Admin login works at `/wp-admin` (Lukasz)
- [ ] SSL active (no browser warnings)
- [ ] Permalinks set to `/%postname%/`

## Prerequisites

- `sshpass` installed (`apt install sshpass` / `brew install hudochenkov/sshpass/sshpass`)
- DNS A record: `zelcycling.com → 212.227.105.0` (required for SSL)
- Network access to the VPS (SSH port 22, Plesk port 8443)
