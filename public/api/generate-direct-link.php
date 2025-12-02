<?php
/**
 * Generate Direct Link API
 * Creates a training_tracking entry and returns the direct link
 */
 
require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['content_id', 'recipient_id']);

    $contentId = $input['content_id'];
    $recipientId = $input['recipient_id'];
    $email = $input['email'] ?? '';
    $firstName = $input['first_name'] ?? '';
    $lastName = $input['last_name'] ?? '';

    // Verify content exists
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Generate unique IDs
    $trainingId = bin2hex(random_bytes(16));
    $trainingTrackingId = bin2hex(random_bytes(16));
    $uniqueTrackingId = bin2hex(random_bytes(16));

    // First, create a training record
    // This is required because training_tracking.training_id references training.id
    $trainingRecord = [
        'id' => $trainingId,
        'company_id' => $input['company_id'] ?? 'default',
        'name' => 'Direct Link: ' . $content['title'],
        'description' => 'Auto-generated training for direct link',
        'training_type' => 'direct_link',
        'training_content_id' => $contentId,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training',
        $trainingRecord
    );

    // Now insert into training_tracking table with reference to the training record
    $trainingTrackingData = [
        'id' => $trainingTrackingId,
        'training_id' => $trainingId, // References the training record we just created
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

    // Determine which domain to use
    $domainUrl = $config['app']['base_url']; // Default fallback

    // Check if content has a domain
    if (!empty($content['domain_id'])) {
        try {
            $contentDomain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id',
                [':id' => $content['domain_id']]
            );

            if ($contentDomain && $contentDomain['is_active']) {
                $domainUrl = $contentDomain['domain_url'];
            }
        } catch (Exception $e) {
            error_log("Domain lookup failed: " . $e->getMessage());
        }
    }

    // Build direct launch URL with both trackingId and content parameters
    $directUrl = rtrim($domainUrl, '/') . '/launch.php?trackingId=' . $uniqueTrackingId . '&content=' . $contentId;

    sendJSON([
        'success' => true,
        'training_id' => $trainingId,
        'tracking_id' => $trainingTrackingId,
        'unique_tracking_id' => $uniqueTrackingId,
        'direct_url' => $directUrl,
        'content' => [
            'id' => $content['id'],
            'title' => $content['title'],
            'type' => $content['content_type']
        ],
        'recipient' => [
            'id' => $recipientId,
            'email' => $email
        ]
    ], 201);

} catch (Exception $e) {
    error_log("Generate Direct Link Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to generate direct link',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
