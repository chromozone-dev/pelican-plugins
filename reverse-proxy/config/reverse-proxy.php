<?php

return [
    /*
     * Hostname labels users are not allowed to claim. Supports '*' wildcards,
     * e.g. 'admin*' blocks 'admin', 'admin2', 'admin-panel'.
     */
    'hostname_blacklist' => env('REVERSE_PROXY_HOSTNAME_BLACKLIST', 'www,panel,api,admin,mail,node,billing,static,cdn'),

    /*
     * Warn (non-blocking) when a hostname does not resolve in DNS yet. Requires
     * a wildcard record pointing at the proxy for self-service to work smoothly.
     */
    'dns_preflight' => (bool) env('REVERSE_PROXY_DNS_PREFLIGHT', true),

    /*
     * HTTP client timeouts, in seconds, for talking to the proxy manager.
     */
    'timeout' => (int) env('REVERSE_PROXY_TIMEOUT', 10),
    'connect_timeout' => (int) env('REVERSE_PROXY_CONNECT_TIMEOUT', 3),

    /*
     * Scheduled reconciliation. The proxy manager is external state that drifts,
     * so this re-syncs entries the plugin owns on a cron. Set to an empty string
     * to disable. Requires the panel's own `schedule:run` cron to be set up.
     *
     * Only --repair is ever scheduled; --prune deletes remote entries and stays
     * a deliberate, manual action.
     */
    'reconcile_cron' => env('REVERSE_PROXY_RECONCILE_CRON', '0 * * * *'),
    'reconcile_repair' => (bool) env('REVERSE_PROXY_RECONCILE_REPAIR', true),

    /*
     * Identifies this panel in the meta stamp written to every proxy entry, so
     * reconciliation can tell its own entries apart from those of another panel
     * sharing the same proxy manager - without it, --prune would delete theirs.
     * Changing this orphans entries created under the old value.
     */
    'panel_id' => env('REVERSE_PROXY_PANEL_ID', env('APP_URL', 'pelican')),
];
