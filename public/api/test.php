<?php
/**
 * Simple test endpoint to verify API is accessible
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once '/var/www/html/public/api/bootstrap.php';

    echo json_encode([
        'success' => true,
        'message' => 'API is working!',
        'php_version' => PHP_VERSION,
        'config_loaded' => isset($config),
        'db_connected' => isset($db),
        'claude_api_loaded' => isset($claudeAPI),
        'content_processor_loaded' => isset($contentProcessor),
        'content_dir' => $config['content']['upload_dir'] ?? 'not set',
        'content_dir_writable' => is_writable($config['content']['upload_dir'] ?? ''),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}
