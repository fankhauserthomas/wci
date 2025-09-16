<?php
// Legacy entry point retained for backwards compatibility with login redirects
require_once __DIR__ . '/auth.php';

if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
}

// Delegate to the enhanced analyzer dashboard implementation
require __DIR__ . '/enhanced-wci-analyzer.php';
