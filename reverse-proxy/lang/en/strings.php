<?php

return [
    'navigation_group' => 'Reverse Proxy',

    'target' => 'Proxy Manager|Proxy Managers',
    'domain' => 'Domain|Domains',
    'route' => 'Reverse Proxy|Reverse Proxies',

    'name' => 'Name',
    'hostname' => 'Hostname',
    'hostname_label' => 'Hostname',
    'port' => 'Port',
    'port_help' => 'The port the proxy should forward to. Port notes from the Network page are shown to help you pick.',

    'base_url' => 'Base URL',
    'base_url_help' => 'Where the admin API lives, e.g. https://npm.example.com:81. NPMplus must use https - its session cookie is marked Secure and is never issued over plain HTTP.',
    'identity' => 'Account email',
    'identity_help' => 'Use a dedicated service account, not your own admin login. Nginx Proxy Manager has no long-lived API keys, so this password is stored (encrypted) by the panel.',
    'secret' => 'Account password',
    'secret_help' => 'Leave blank when editing to keep the stored password. The account must not have two-factor authentication enabled.',
    'verify_tls' => 'Verify TLS certificate',
    'verify_tls_help' => 'Turn this off if the proxy manager serves its admin interface with a self-signed certificate, which NPMplus does by default.',
    'is_default' => 'Default',
    'is_default_help' => 'Preselected when adding domains. Only one proxy manager can be the default.',
    'variant' => 'Detected',
    'not_detected' => 'Not tested yet',
    'test_connection' => 'Test connection',

    'certificate' => 'Certificate',
    'certificate_help' => 'The certificate to attach to every hostname under this domain. A wildcard certificate covering *.<domain> is suggested automatically, so no per-hostname certificate is ever requested.',
    'no_certificate' => 'None',
    'domain_name_help' => 'The domain you own, without a wildcard, e.g. example.com. DNS for *.<domain> must already point at the proxy.',
    'force_ssl' => 'Force SSL',
    'force_ssl_help' => 'Redirect HTTP to HTTPS. Only applies when a certificate is selected.',
    'allow_user_routes' => 'Users may use this domain',
    'allow_user_routes_help' => 'When off, only admins can create proxies under this domain.',

    'forward_scheme' => 'Forward scheme',
    'forward_scheme_help' => 'How the proxy connects to your server - not how you reach the hostname. Visitors always get HTTPS, which the proxy terminates for you. This is almost always HTTP: choose HTTPS only if the service itself serves TLS on that port.',
    'websockets' => 'Allow websockets',
    'websockets_help' => 'Needed by most live-updating web interfaces, such as map viewers and admin panels.',
    'block_exploits' => 'Block common exploits',

    'last_synced' => 'Last synced',
    'never_synced' => 'Never',

    'type' => 'Type',
    'type_help' => 'HTTP(S) routes share ports 80/443 and are told apart by hostname, so you can have as many as you like. A TCP/UDP stream claims one port on the proxy for one server - use it for game protocols, which carry no hostname.',

    'stream_ports' => 'Stream ports',
    'stream_ports_help' => 'Ports published on this proxy manager that streams may listen on. Docker cannot add ports to a running container, so these must already be exposed on it - listing a port here that is not published produces a stream that never accepts a connection.',
    'stream_port' => 'Proxy port',
    'stream_port_help' => 'Use the game\'s default port so players do not have to type one: the proxy can listen on 25565 and forward to a server running on any port.',
    'stream_port_select_help' => 'Only unclaimed ports are listed. One port serves one server, because a stream cannot tell hostnames apart.',
    'no_stream_ports' => 'No stream ports',
    'unclaimed' => 'Unclaimed',
    'delete_stream_port_warning' => 'Any stream using this port will lose it and stop working until another is assigned.',
    'forward_tcp' => 'Forward TCP',
    'forward_udp' => 'Forward UDP',

    'destination' => 'Destination',
    'destination_help' => 'Where the proxy connects to reach this port. If this is not an address your proxy can reach, set a proxy forward host on the node.',
    'destination_unavailable' => 'Cannot be determined',
    'check' => 'Check',
    'check_running' => 'Checking the destination',

    'notifications_check' => [
        'reachable' => 'Destination is reachable',
        'unreachable' => 'Destination is not reachable',
        'dns_ok' => 'Hostname resolves in DNS.',
        'dns_missing' => 'Hostname does not resolve in DNS yet.',
        'from_panel' => 'Checked from the panel, which is not the proxy manager - treat it as a strong hint rather than proof.',
    ],

    'reconcile_alert' => 'Reverse proxy reconciliation needs attention',

    'limit' => 'Limit',
    'limit_help' => 'How many proxies this server\'s users may create. 0 hides the feature from them entirely.',
    'change_limit' => 'Change limit',
    'limit_changed' => 'Limit changed',
    'limit_reached' => 'This server has reached its reverse proxy limit.',
    'create_route' => 'Create proxy',

    'delete_target_warning' => 'Every domain and reverse proxy using this proxy manager will be deleted, and their entries removed from the proxy manager itself. If it is unreachable right now, those entries will be left behind with no way to remove them from here afterwards.',
    'delete_domain_warning' => 'Every reverse proxy under this domain will be deleted, and their entries removed from the proxy manager.',
    'certificates_unavailable' => 'Could not load certificates',
    'certificates_unavailable_help' => 'The proxy manager could not be reached, so the certificate list is empty. Use Test connection on the proxy manager to see why.',

    'no_targets' => 'No proxy managers',
    'no_targets_description' => 'Add your Nginx Proxy Manager or NPMplus instance to get started.',
    'no_domains' => 'No domains',
    'no_domains_description' => 'Register a domain you own, then users can create hostnames under it.',
    'no_routes' => 'No reverse proxies',
    'no_routes_description' => 'Give one of this server\'s ports a hostname instead of an IP and port.',

    'hostname_blacklist' => 'Hostname label blacklist',
    'hostname_blacklist_help' => "Labels users cannot claim - enter just the label, not a full hostname, so 'panel' and not 'panel.example.com'. Patterns with '*' are supported, e.g. 'admin*' blocks anything starting with 'admin'. The list applies across every domain.",

    'forward_host' => 'Proxy forward host',
    'forward_host_help' => 'Address the reverse proxy should connect to in order to reach this node. Leave blank to use the node FQDN. Set this when the proxy reaches the node on an internal address, or when allocations bind 0.0.0.0.',
    'dns_preflight' => 'Warn about missing DNS',
    'dns_preflight_help' => 'After creating a proxy, check whether the hostname resolves and warn if it does not. Recommended unless you have wildcard DNS and want to skip the lookup.',

    /*
     * Bridged into the panel's own `activity.server.proxy-route.*` keys by the
     * plugin's service provider, because ActivityLog::getLabel() resolves event
     * names against the app translation namespace, not a plugin's.
     */
    'activity' => [
        'create' => 'Created reverse proxy <b>:hostname</b> for port <b>:port</b>',
        'update' => 'Updated reverse proxy <b>:hostname</b>',
        'delete' => 'Deleted reverse proxy <b>:hostname</b>',
    ],

    'permissions' => [
        'read' => 'Read',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
    ],

    'notifications' => [
        'connected' => 'Connected to the proxy manager',
        'not_connected' => 'Could not connect to the proxy manager',
        'synced' => 'Reverse proxy saved',
        'not_synced' => 'Could not save the reverse proxy',
        'dns_missing' => 'DNS does not resolve yet',
        'dns_missing_body' => 'The proxy entry was created, but :hostname does not resolve. Add a DNS record for it (or a wildcard record for the domain) pointing at your proxy.',
    ],

    'validation' => [
        'on_blacklist' => 'The :attribute is not allowed.',
        'invalid_label' => 'The :attribute must be lowercase letters, numbers and hyphens only, start and end with a letter or number, and be at most 63 characters.',
        'reserved_label' => 'The :attribute uses a reserved prefix. Names like "xn--…" encode other alphabets and can be made to look like a different hostname.',
        'invalid_domain' => 'Enter a domain name such as example.com, without a wildcard or protocol.',
    ],

    'errors' => [
        'allocation_mismatch' => 'That port does not belong to this server.',
        'domain_not_available' => 'That domain is not available for this server.',
        'no_stream_port' => 'This stream has no proxy port assigned, so there is nothing for it to listen on.',
        'sync_failed' => 'The reverse proxy could not be saved. Please try again, or ask an administrator to check the proxy manager.',
        'tls_trust' => 'This looks like a self-signed certificate, which is how NPMplus serves its admin interface by default - and reaching it on a LAN address means no certificate could validate anyway. Edit this proxy manager and turn off "Verify TLS certificate".',
        'allocation_detached' => 'The port behind :hostname no longer belongs to this server, so it was not published. Delete this proxy or point it at a current port.',
    ],
];
