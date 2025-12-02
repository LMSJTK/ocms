<?php
/**
 * Record Score API
 * Records test score and publishes to SNS
 */

require_once '/var/www/html/public/api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['score']);

    // Support both old and new parameter names
    // OLD: tracking_link_id (backwards compatibility)
    // NEW: trackingId (from external system)
    $trackingLinkId = $input['trackingId'] ?? $input['tracking_link_id'] ?? null;

    if (!$trackingLinkId) {
        sendJSON(['error' => 'Missing tracking ID (provide trackingId or tracking_link_id)'], 400);
    }

    $score = intval($input['score']);
    $interactions = $input['interactions'] ?? [];
    $contentId = $input['content_id'] ?? null; // For new method

    $result = $trackingManager->recordScore($trackingLinkId, $score, $interactions, $contentId);

    sendJSON([
        'success' => true,
        'score' => $result['score'],
        'status' => $result['status'],
        'message' => 'Score recorded successfully'
    ]);

} catch (Exception $e) {
    error_log("Record Score Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to record score',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
