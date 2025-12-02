<?php
/**
 * System Check - Debug script to verify configuration
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>System Check</h1>";
echo "<pre>";

// Check PHP version
echo "PHP Version: " . PHP_VERSION . "\n";
echo "---\n\n";

// Check required extensions
echo "Required PHP Extensions:\n";
$required_extensions = ['pdo', 'zip', 'json', 'curl'];
foreach ($required_extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "  - {$ext}: " . ($loaded ? "âœ“ INSTALLED" : "âœ— MISSING") . "\n";
}
// Check database-specific extensions
echo "  - pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? "âœ“ INSTALLED" : "âœ— MISSING") . " (for PostgreSQL)\n";
echo "  - pdo_mysql: " . (extension_loaded('pdo_mysql') ? "âœ“ INSTALLED" : "âœ— MISSING") . " (for MySQL)\n";
echo "\n";

// Check config file
echo "Configuration:\n";
$configPath = '/var/www/html/config/config.php';
$exampleConfigPath = '/var/www/html/config/config.example.php';

if (file_exists($configPath)) {
    echo "  - config.php: âœ“ EXISTS\n";
    try {
        $config = require $configPath;
        echo "  - Config loaded successfully\n";
        echo "  - Database type: " . ($config['database']['type'] ?? 'pgsql (default)') . "\n";
        echo "  - Database host: " . ($config['database']['host'] ?? 'NOT SET') . "\n";
        echo "  - Database name: " . ($config['database']['dbname'] ?? 'NOT SET') . "\n";
        echo "  - Content upload dir: " . ($config['content']['upload_dir'] ?? 'NOT SET') . "\n";
        echo "  - Debug mode: " . (($config['app']['debug'] ?? false) ? 'ENABLED' : 'DISABLED') . "\n";
    } catch (Exception $e) {
        echo "  - âœ— ERROR loading config: " . $e->getMessage() . "\n";
    }
} else {
    echo "  - config.php: âœ— MISSING (using example config)\n";
}
echo "\n";

// Check content directory
echo "Content Directory:\n";
$contentDir = '/var/www/html/content/';
echo "  - Path: {$contentDir}\n";
echo "  - Exists: " . (is_dir($contentDir) ? "âœ“ YES" : "âœ— NO") . "\n";
if (is_dir($contentDir)) {
    echo "  - Writable: " . (is_writable($contentDir) ? "âœ“ YES" : "âœ— NO") . "\n";
    echo "  - Permissions: " . substr(sprintf('%o', fileperms($contentDir)), -4) . "\n";
}
echo "\n";

// Check database connection
echo "Database Connection:\n";
if (file_exists($configPath)) {
    try {
        require_once '/var/www/html/lib/Database.php';
        $config = require $configPath;
        $db = Database::getInstance($config['database']);
        echo "  - Connection: âœ“ SUCCESS\n";

        // Try a simple query
        $result = $db->fetchOne("SELECT NOW() as current_time");
        echo "  - Query test: âœ“ SUCCESS (Server time: " . $result['current_time'] . ")\n";

        // Check if content table exists (database-specific queries)
        $dbType = $db->getDbType();
        if ($dbType === 'mysql') {
            $tables = $db->fetchAll("SELECT table_name as tablename FROM information_schema.tables WHERE table_schema = :dbname", [
                ':dbname' => $config['database']['dbname']
            ]);
        } else {
            $tables = $db->fetchAll("SELECT tablename FROM pg_tables WHERE schemaname = :schema", [
                ':schema' => $config['database']['schema'] ?? 'global'
            ]);
        }
        $tableNames = array_column($tables, 'tablename');
        echo "  - Tables found: " . count($tableNames) . "\n";

        $requiredTables = ['content', 'content_tags', 'content_interactions'];
        foreach ($requiredTables as $table) {
            $exists = in_array($table, $tableNames);
            echo "    - {$table}: " . ($exists ? "âœ“" : "âœ—") . "\n";
        }
    } catch (Exception $e) {
        echo "  - âœ— ERROR: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// Check file permissions
echo "File Permissions:\n";
$checkPaths = [
    'api/bootstrap.php',
    'lib/Database.php',
    'lib/ClaudeAPI.php',
    'lib/ContentProcessor.php',
];
foreach ($checkPaths as $path) {
    $fullPath = '/var/www/html' . $path;
    if (file_exists($fullPath)) {
        echo "  - {$path}: " . substr(sprintf('%o', fileperms($fullPath)), -4) . " (readable: " . (is_readable($fullPath) ? "âœ“" : "âœ—") . ")\n";
    } else {
        echo "  - {$path}: âœ— NOT FOUND\n";
    }
}
echo "\n";

echo "---\n";
echo "If all checks pass, try uploading content.\n";
echo "If upload fails, check your web server error logs for more details.\n";
echo "</pre>";