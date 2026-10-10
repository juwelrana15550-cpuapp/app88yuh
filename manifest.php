<?php
// Web-app manifest: lets users "Add to Home screen" with the site icon and name.
require __DIR__ . '/lib.php';
session_write_close();
header('Content-Type: application/manifest+json');
echo json_encode([
    'name' => site_name(), 'short_name' => mb_substr(site_name(), 0, 12),
    'start_url' => '/dashboard.php', 'scope' => '/', 'display' => 'standalone',
    'background_color' => '#f3f5fb', 'theme_color' => '#4338ca',
    'icons' => [
        ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES);
