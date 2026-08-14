# Reverse Proxy

Gives Pelican servers real hostnames instead of `ip:port`. An admin registers the domains they own and the proxy manager that fronts them; server owners then self-serve a hostname for any of their server's ports, and the plugin creates, updates and deletes the matching proxy entry over the proxy manager's API.

Built for the ports that serve HTTP — map viewers (BlueMap, dynmap), Foundry VTT, code-server, bot dashboards, and the growing number of plain Docker apps people run on Pelican.

Supports **Nginx Proxy Manager** and **NPMplus**, autodetected.

## Prerequisites

Three things must be true before the plugin is useful:

1. **The proxy manager can reach your nodes.** It dials the node directly, so it needs a route to it — same LAN, VPN, or a public address.
2. **DNS points at the proxy.** A wildcard `*.example.com` A/AAAA record aimed at the proxy is the sane setup, because it covers every hostname a user might create. Without it you'd add a record by hand per proxy. The plugin warns when a hostname doesn't resolve; it does not create DNS records (the [subdomains plugin](https://github.com/pelican-dev/plugins/tree/main/subdomains) does that).
3. **A certificate covering the domain exists in the proxy manager.** Create one certificate for `example.com` + `*.example.com` and every hostname reuses it. Nothing here requests per-hostname certificates, so Let's Encrypt rate limits are never a factor.

## Install

Requires Pelican Panel **v1.0.0-beta36 or newer** — the plugin uses panel extension points (`CanModifyTable`, `registerCustomRelations`, `HasPluginSettings`) and Filament 5 APIs that older releases lack. The panel refuses to install it below that version rather than failing at runtime.

Build the archive from the workspace root with `composer build`, then either upload `dist/reverse-proxy-<version>.zip` via *Admin → Plugins → Import*, or install it from the command line:

```bash
# unzip into your panel's plugins/ directory, then from the panel directory
php artisan p:plugin:install reverse-proxy
```

Installing runs the plugin's migrations, which add three tables plus `servers.proxy_route_limit` and `nodes.proxy_forward_host`. `php artisan p:plugin:uninstall reverse-proxy` rolls all of that back.

## Setup

**1. Create a service account in your proxy manager.** Give it permission to manage proxy hosts and nothing else — do not use your own admin login. Neither NPM nor NPMplus issues long-lived API keys, so the panel has to store a real password; least privilege is the mitigation. **Do not enable two-factor authentication on it** — both forks put a TOTP challenge in front of token issuance, which cannot be automated.

**2. Add the proxy manager** under *Reverse Proxy → Proxy Managers*, then hit **Test connection**. It reports which fork it found and how many certificates are visible. For NPMplus the base URL must be `https://…:81`, and you'll usually need *Verify TLS certificate* off because its admin interface is self-signed.

**3. Register your domain** under *Reverse Proxy → Domains*. Enter `example.com` and the certificate picker preselects your `*.example.com` wildcard. Leave *Users may use this domain* on for self-service.

**4. Set a per-server limit.** Open a server in the admin panel and use **Change limit** on its Reverse Proxies section. The limit is `0` by default, which hides the feature from users entirely — nothing is exposed until you grant it.

**5. Optionally set a node forward host.** Edit the node in the admin panel and use its **Reverse Proxy** tab. Needed when the proxy reaches the node on an internal address; otherwise the node FQDN is used as a sensible fallback.

Admin permissions are registered for *Proxy Manager*, *Domain* and *Reverse Proxy*, so you can grant proxy management to a role without giving it the rest of the panel. The per-server section on a server's admin page requires the *Reverse Proxy* permission.

Users then get a **Reverse Proxies** page on their server, and a **Create proxy** button next to each port on the Network page.

## Where the proxy forwards to

First match wins:

1. The node's **Proxy forward host**, if set
2. The allocation's IP, when it is a concrete address
3. The node's FQDN, for allocations bound to `0.0.0.0` / `::`

A bind-all allocation is reachable at the node's own address, so falling back to the FQDN is correct rather than an error.

The allocation's **alias is deliberately never used**. In Pelican an alias is a display value — the friendly address shown to players, usually a public DNS name. Forwarding to it sends traffic out to the internet and back in, which only works if your network does NAT hairpinning; a proxy sitting alongside the node wants the node's own address instead. Where an alias genuinely is the right destination, set the node's *Proxy forward host* so the intent is explicit rather than inferred.

## Keeping things in sync

The proxy manager is external state that you also edit by hand, so drift happens. Every entry this plugin creates is stamped in its `meta` field, which lets it recognise its own work and leave yours alone.

```bash
php artisan p:reverse-proxy:reconcile            # report only
php artisan p:reverse-proxy:reconcile --repair   # recreate missing, rewrite drifted
php artisan p:reverse-proxy:reconcile --prune    # delete plugin-owned entries whose route is gone
```

Entries without the stamp are never touched, and neither are entries stamped by a **different panel** — the stamp records which panel wrote it, so two panels can share one proxy manager without `--prune` deleting each other's work. Those show up as `foreign` and are only ever reported. The same applies to two proxy managers configured against one NPM instance: orphan detection compares against every local route, not just the one target's.

Reported categories are `detached`, `missing`, `drifted`, `duplicated`, `orphaned` and `foreign`. `--repair` handles the first three; `--prune` handles duplicates and orphans. `duplicated` means more than one remote entry claims the same route, which happens when a create succeeds but its response never arrives — nginx ends up with two conflicting `server_name` blocks, and prune removes the one the panel isn't tracking.

The command exits non-zero when anything actionable is left unresolved, so a cron watching exit codes learns about drift rather than only about connection failures.

It also reports **detached** routes — where the port behind the hostname no longer belongs to the server — and `--repair` deletes those rather than rewriting them. This matters more than it sounds: Pelican detaches an allocation from a server with a query-builder update, so no model event fires and no cascade removes the route. The freed port can then be assigned to a different server, at which point the hostname would publish somebody else's service. Comparing forwarding values can't detect it, because both sides derive from the same allocation — so removal is the only safe remedy, and `syncToProxy()` refuses to publish a detached route at all.

`--repair` also runs automatically, hourly by default, provided the panel's own `schedule:run` cron is set up. `--prune` is never scheduled — it deletes remote entries, so it stays a deliberate manual action. Two settings control this:

| Env var | Default | Effect |
|---|---|---|
| `REVERSE_PROXY_RECONCILE_CRON` | `0 * * * *` | Cron expression. Empty string disables the schedule. |
| `REVERSE_PROXY_RECONCILE_REPAIR` | `true` | When false, the scheduled run reports drift without fixing it. |
| `REVERSE_PROXY_PANEL_ID` | `APP_URL` | Identifies this panel in the stamp. Only set it if two panels share a proxy manager and their `APP_URL` values collide. Changing it orphans existing entries. |

## Suspension

Suspending a server publishes its proxy entries as disabled, and unsuspending re-enables them. Reconciliation knows the expected state, so `--repair` will not switch a suspended server's hostname back on, and it flags an entry that was disabled by hand.

## Diagnosing a proxy that doesn't work

Each route shows its **destination** — the address the proxy actually dials — in both the server and admin panels, and the create form previews it before you save. That is the value to check first when a hostname resolves but returns a bad gateway.

The **Check** action on a route resolves that destination, attempts a connection and reports whether the hostname resolves in DNS. It runs from the panel rather than from the proxy manager, so on the usual same-network setup treat a pass as a strong hint rather than proof.

## Activity log

Creating, updating and deleting a proxy is recorded in the server's **Activity** tab, with the hostname and port. Since a subuser with the right permission can publish a hostname that points at the server, those actions should not be invisible to the owner.

## Settings

Under the plugin's settings page:

- **Hostname label blacklist** — labels users cannot claim, `*` wildcards supported. Enter the *label* only (`panel`, not `panel.example.com`) — the rule never sees the domain. Keep `panel` and `api` here so nobody takes over your own hostnames.

Labels are also restricted to lowercase letters, numbers and inner hyphens, and normalised as they're typed. Reserved `??--` prefixes are rejected, which blocks punycode labels such as `xn--pnel-53d` — pure ASCII, but rendered by browsers as `panel` with a Cyrillic character, on your domain and your certificate.
- **Warn about missing DNS** — the post-create DNS lookup. Turn off if you have wildcard DNS and want to skip it.

## Development

This plugin lives in the [pelican-plugins workspace](../README.md) - see that README for the full setup. Style checks run from the workspace root; static analysis and tests need a panel checkout, because larastan boots a real Laravel application and the panel owns the PHPUnit harness.

`tests/Unit/ReverseProxy/NpmFamilyDriverTest.php` covers the part of this plugin most likely to break - the driver's auth handling. Against faked HTTP it asserts bearer-vs-cookie detection, that NPMplus's signed cookie is captured and replayed, that the session is cached rather than re-minted per call, and that the request payload stays inside the field set both forks accept.

## Notes and limits

- **HTTP(S) only.** Raw game ports need TCP/UDP stream forwarding, which is a different feature with a real constraint: each stream's incoming port must already be published on the proxy container, and Docker cannot add ports to a running container. Deliberately left out. For Minecraft hostnames, SRV records via the subdomains plugin are the right tool.
- **Caddy is not supported yet.** The driver interface (`Contracts/ProxyDriver`) exists so it can be added without touching models or UI. Caddy's admin API binds localhost with no authentication, so it needs a tunnel or mTLS to be usable from the panel.
- **Deleting a route when the proxy manager is down** removes the local record and leaves the remote entry behind; server deletion is deliberately never blocked on a third-party service. `--prune` cleans up afterwards.
