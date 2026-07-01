<?php

$parseHosts = static function (?string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) $value)
    )));
};

$localHosts = $parseHosts(env(
    'PRODELYA_LOCAL_HOSTS',
    'localhost,127.0.0.1,prodelya_core.test,prodelya.test,app.prodelya.test'
));

$centralHosts = $parseHosts(env(
    'PRODELYA_CENTRAL_HOSTS',
    implode(',', $localHosts)
));

$reservedHosts = $parseHosts(env(
    'PRODELYA_RESERVED_HOSTS',
    implode(',', array_values(array_unique(array_merge($centralHosts, $localHosts))))
));

return [
    'main_domain' => trim((string) env('PRODELYA_MAIN_DOMAIN', 'prodelya_core.test')) ?: 'prodelya_core.test',
    'panel_domain' => trim((string) env('PRODELYA_PANEL_DOMAIN', env('PRODELYA_MAIN_DOMAIN', 'prodelya_core.test'))) ?: 'prodelya_core.test',
    'central_hosts' => $centralHosts,
    'reserved_hosts' => $reservedHosts,
    'force_https' => filter_var(env('PRODELYA_FORCE_HTTPS', false), FILTER_VALIDATE_BOOL),
    'allow_local_hosts' => filter_var(env('PRODELYA_ALLOW_LOCAL_HOSTS', true), FILTER_VALIDATE_BOOL),
    'local_hosts' => $localHosts,
];
