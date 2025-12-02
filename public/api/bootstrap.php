<?php
/**
 * Bootstrap file for API endpoints
 * Loads configuration and initializes core classes
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration
$configPath = '/var/www/html/config/config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/config/config.example.php';
}
error_log("Loading config from: " . $configPath);
$config = require $configPath;
error_log("Config loaded, debug mode: " . ($config['app']['debug'] ? 'true' : 'false'));

// Extract base path from base_url for API calls
$parsedUrl = parse_url($config['app']['base_url']);
$basePath = $parsedUrl['path'] ?? '';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoload classes
spl_autoload_register(function ($className) {
    $file = '/var/www/html/lib/' . $className . '.php';
    error_log("Autoloading class: $className from $file");
    if (file_exists($file)) {
        require_once $file;
    } else {
        error_log("Class file not found: $file");
    }
});

// Initialize core classes
try {
    error_log("Initializing Database...");
    $db = Database::getInstance($config['database']);
    error_log("Database initialized");

    error_log("Initializing ClaudeAPI...");
    $claudeAPI = new ClaudeAPI($config['claude']);
    error_log("ClaudeAPI initialized");

    error_log("Initializing AWSSNS...");
    $sns = new AWSSNS($config['aws_sns']);
    error_log("AWSSNS initialized");

    error_log("Initializing ContentProcessor...");
    $contentProcessor = new ContentProcessor($db, $claudeAPI, $config['content']['upload_dir'], $basePath);
    error_log("ContentProcessor initialized");

    error_log("Initializing TrackingManager...");
    $trackingManager = new TrackingManager($db, $sns);
    error_log("TrackingManager initialized");

    error_log("All classes initialized successfully");
} catch (Exception $e) {
    error_log("Bootstrap initialization failed: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'System initialization failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}

/**
 * Helper function to send JSON response
 */
function sendJSON($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Helper function to get JSON input
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Helper function to validate required fields
 */
function validateRequired($data, $required) {
    $missing = [];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        sendJSON([
            'error' => 'Missing required fields',
            'fields' => $missing
        ], 400);
    }
}

/*
 * Helper for the helper, adding misssing function
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    return $headers;
    }
}

/**
 * Helper function to validate bearer token authentication
 */
function validateBearerToken($config) {
    // Check if API authentication is enabled
    if (!isset($config['api']['enabled']) || !$config['api']['enabled']) {
        return; // Authentication disabled
    }

    // Get the Authorization header
    $headers = getallheaders();
    $authHeader = null;

    // Look for Authorization header (case-insensitive)
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }

    // Check if Authorization header exists
    if (!$authHeader) {
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Missing Authorization header'
        ], 401);
    }

    // Extract bearer token
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Invalid Authorization header format. Expected: Bearer <token>'
        ], 401);
    }

    $providedToken = $matches[1];
    $expectedToken = $config['api']['bearer_token'] ?? null;

    // Validate token
    if (!$expectedToken) {
        error_log('Warning: API bearer token not configured in config.php');
        sendJSON([
            'error' => 'Server configuration error',
            'message' => 'API authentication not properly configured'
        ], 500);
    }

    // Use timing-safe comparison to prevent timing attacks
    if (!hash_equals($expectedToken, $providedToken)) {
        error_log('Authentication failed: Invalid bearer token provided');
        sendJSON([
            'error' => 'Unauthorized',
            'message' => 'Invalid bearer token'
        ], 401);
    }

    // Token is valid, continue
}
