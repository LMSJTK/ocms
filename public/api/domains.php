<?php
/**
 * Domains API Endpoint
 *
 * GET /api/domains.php - List all domains
 * POST /api/domains.php - Add a new domain
 *
 * Requires: Bearer token authentication
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Authenticate
validateBearerToken($config);

// Handle GET request - List all domains
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Check if domains table exists (backwards compatibility)
        $tableExists = false;
        try {
            $checkSql = $db->getDbType() === 'pgsql'
                ? "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'global' AND table_name = 'domains')"
                : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'domains'";
            $result = $db->fetchOne($checkSql, []);
            $tableExists = $db->getDbType() === 'pgsql' ? $result['exists'] : $result['COUNT(*)'] > 0;
        } catch (Exception $e) {
            error_log('Could not check if domains table exists: ' . $e->getMessage());
        }

        // If table doesn't exist, return empty array
        if (!$tableExists) {
            sendJSON([
                'success' => true,
                'domains' => [],
                'count' => 0,
                'message' => 'Domains table not yet created. Run migration: database/migration_add_domains.sql'
            ], 200);
        }

        // Get optional filters
        $isActive = isset($_GET['is_active']) ? filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN) : null;

        // Build query
        $sql = 'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains';
        $params = [];

        if ($isActive !== null) {
            $sql .= ' WHERE is_active = :is_active';
            $params[':is_active'] = $isActive ? 1 : 0;
        }

        $sql .= ' ORDER BY created_at DESC';

        $domains = $db->fetchAll($sql, $params);

        sendJSON([
            'success' => true,
            'domains' => $domains,
            'count' => count($domains)
        ], 200);

    } catch (Exception $e) {
        error_log('Error listing domains: ' . $e->getMessage());
        sendJSON(['error' => 'Failed to retrieve domains'], 500);
    }
}

// Handle POST request - Add a new domain
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = getJSONInput();

        // Validate required fields
        validateRequired($input, ['domain_url', 'name']);

        // Validate domain URL format
        $domainUrl = $input['domain_url'];

        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $domainUrl)) {
            $domainUrl = 'https://' . $domainUrl;
        }

        $domainUrl = rtrim($domainUrl, '/');

        if (!filter_var($domainUrl, FILTER_VALIDATE_URL)) {
            sendJSON(['error' => 'Invalid domain URL format'], 400);
        }

        // Check if domain URL already exists
        $existingDomain = $db->fetchOne(
            'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE domain_url = :domain_url',
            [':domain_url' => $domainUrl]
        );

        if ($existingDomain) {
            sendJSON(['error' => 'Domain URL already exists'], 409);
        }

        // Prepare domain data (let AUTO_INCREMENT handle the ID)
        $domainData = [
            'domain_url' => $domainUrl,
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'is_active' => isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1
        ];

        // Insert domain
        $db->insert('domains', $domainData);

        // Get the last inserted ID
        $domainId = $db->getPdo()->lastInsertId();

        // Return the created domain
        $createdDomain = $db->fetchOne(
            'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id',
            [':id' => $domainId]
        );

        sendJSON([
            'success' => true,
            'message' => 'Domain added successfully',
            'domain' => $createdDomain
        ], 201);

    } catch (Exception $e) {
        error_log('Error adding domain: ' . $e->getMessage());
        sendJSON(['error' => 'Failed to add domain: ' . $e->getMessage()], 500);
    }
}

// Invalid method
sendJSON(['error' => 'Method not allowed. Use GET or POST.'], 405);
