<?php
/**
 * Track View API
 * Records when content is viewed
 */

require_once '/var/www/html/public/api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();

    // Support both old and new parameter names
    // OLD: tracking_link_id (backwards compatibility)
    // NEW: trackingId (from external system)
    $trackingLinkId = $input['trackingId'] ?? $input['tracking_link_id'] ?? null;

    if (!$trackingLinkId) {
        sendJSON(['error' => 'Missing tracking ID (provide trackingId or tracking_link_id)'], 400);
    }

    // Optional parameters for new method
    $contentId = $input['content_id'] ?? null;
    $recipientId = $input['recipient_id'] ?? null;

    $result = $trackingManager->trackView($trackingLinkId, $contentId, $recipientId);

    sendJSON([
        'success' => true,
        'message' => 'View tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track View Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track view',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
