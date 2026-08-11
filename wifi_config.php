<?php
// wifi_config.php
// Hardcoded IP prefixes considered "on the lecturer's hotspot/local network".
// Used to verify a student's IP address is plausibly connected to the
// same WiFi hotspot/network as the lecturer when marking attendance.

$allowed_ip_prefixes = [
    "192.168.43.",   // common Android hotspot range
    "172.20.10.",    // common iPhone Personal Hotspot range
    "192.168.1.",    // common home/office router range
    "192.168.0.",    // common home/office router range
    "10.0.0.",        // some routers/hotspots
    "192.168.22."     // common home/office router range
];

/**
 * Check whether a given IP address matches any allowed prefix.
 */
function ip_matches_allowed_prefix(string $ip, array $prefixes): bool {
    foreach ($prefixes as $prefix) {
        if (strpos($ip, $prefix) === 0) {
            return true;
        }
    }
    return false;
}
?>