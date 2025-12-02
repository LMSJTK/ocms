<?php
/**
 * Thumbnail Image API
 * Serves thumbnail images for content
 */

require_once '/var/www/html/public/api/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}

$contentId = $_GET['id'] ?? null;

if (!$contentId) {
    http_response_code(400);
    exit('Content ID required');
}

try {
    // Get thumbnail URL from database
    $content = $db->fetchOne(
        'SELECT thumbnail_filename FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content || !$content['thumbnail_filename']) {
        http_response_code(404);
        exit('Thumbnail not found');
    }

    // Redirect to the thumbnail URL
    header('Location: ' . $content['thumbnail_filename']);
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    exit;

} catch (Exception $e) {
    error_log("Thumbnail Error: " . $e->getMessage());
    http_response_code(500);
    exit('Failed to load thumbnail');
}
