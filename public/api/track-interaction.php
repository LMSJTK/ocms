<?php
/**
 * Track Interaction API
 * Records interactions with tagged elements
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

    validateRequired($input, ['tag_name', 'interaction_type']);

    $tagName = $input['tag_name'];
    $interactionType = $input['interaction_type'];
    $interactionValue = $input['interaction_value'] ?? null;
    $success = $input['success'] ?? null;

    $result = $trackingManager->trackInteraction(
        $trackingLinkId,
        $tagName,
        $interactionType,
        $interactionValue,
        $success
    );

    sendJSON([
        'success' => true,
        'message' => 'Interaction tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track Interaction Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track interaction',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
