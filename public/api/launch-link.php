<?php
/**
 * Launch Link API
 * Creates a tracking link for content launch
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['recipient_id', 'content_id']);

    $recipientId = $input['recipient_id'];
    $contentId = $input['content_id'];
    $overrideDomainId = $input['domain_id'] ?? null; // Optional domain override

    // Verify content exists
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Determine which domain to use
    $domainUrl = $config['app']['base_url']; // Default fallback
    $usedDomainId = null;

    // Try to use domain functionality if tables exist
    try {
        // If override domain is specified, use it
        if ($overrideDomainId !== null) {
            $overrideDomain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id AND is_active = :is_active',
                [':id' => $overrideDomainId, ':is_active' => 1]
            );

            if (!$overrideDomain) {
                sendJSON(['error' => 'Invalid or inactive override domain_id'], 400);
            }

            $domainUrl = $overrideDomain['domain_url'];
            $usedDomainId = $overrideDomain['id'];
        }
        // Otherwise, if content has a default domain, use it
        elseif (!empty($content['domain_id'])) {
            $contentDomain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id',
                [':id' => $content['domain_id']]
            );

            if ($contentDomain && $contentDomain['is_active']) {
                $domainUrl = $contentDomain['domain_url'];
                $usedDomainId = $contentDomain['id'];
            }
        }
    } catch (Exception $e) {
        // If domains table doesn't exist, just use config base_url
        error_log("Domain lookup skipped (table may not exist): " . $e->getMessage());
    }

    // Generate unique IDs
    $trainingId = generateUUID4();
    $trainingTrackingId = generateUUID4();
    $uniqueTrackingId = generateUUID4();

    // Create training record
    $trainingRecord = [
        'id' => $trainingId,
        'company_id' => $input['company_id'] ?? 'default',
        'name' => 'Launch Link: ' . $content['title'],
        'description' => 'Auto-generated training for launch link',
        'training_type' => 'launch_link',
        'training_content_id' => $contentId,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training',
        $trainingRecord
    );

    // Create training_tracking record
    $trainingTrackingData = [
        'id' => $trainingTrackingId,
        'training_id' => $trainingId,
        'recipient_id' => $recipientId,
        'unique_tracking_id' => $uniqueTrackingId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
        $trainingTrackingData
    );

    // Build full launch URL with PATH_INFO format (dashless IDs)
    // Format: /launch.php/{content_id_without_dashes}/{tracking_id_without_dashes}
    $contentIdNoDash = str_replace('-', '', $contentId);
    $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
    $fullLaunchUrl = rtrim($domainUrl, '/') . '/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

    sendJSON([
        'success' => true,
        'training_id' => $trainingId,
        'tracking_id' => $trainingTrackingId,
        'unique_tracking_id' => $uniqueTrackingId,
        'launch_url' => $fullLaunchUrl,
        'domain_used' => $usedDomainId,
        'content' => [
            'id' => $content['id'],
            'title' => $content['title'],
            'type' => $content['content_type'],
            'default_domain_id' => $content['domain_id'] ?? null
        ]
    ]);

} catch (Exception $e) {
    error_log("Launch Link Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to create launch link',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
