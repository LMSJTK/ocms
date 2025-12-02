<?php
/**
 * SNS Messages API
 * Returns SNS messages from the queue for monitoring
 */

require_once '/var/www/html/public/api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    // Get all messages from SNS queue, ordered by most recent first
    $messages = $db->fetchAll(
        'SELECT * FROM sns_message_queue ORDER BY created_at DESC LIMIT 100'
    );

    // Get statistics
    $stats = $db->fetchOne(
        'SELECT
            COUNT(*) as total,
            SUM(CASE WHEN sent = true THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN sent = false THEN 1 ELSE 0 END) as pending
         FROM sns_message_queue'
    );

    sendJSON([
        'success' => true,
        'messages' => $messages,
        'stats' => [
            'total' => intval($stats['total']),
            'sent' => intval($stats['sent']),
            'pending' => intval($stats['pending'])
        ]
    ]);

} catch (Exception $e) {
    error_log("SNS Messages Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to load messages',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
