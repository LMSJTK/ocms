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
    // Get thumbnail from database
    $content = $db->fetchOne(
        'SELECT thumbnail_filename, thumbnail_content FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content || !$content['thumbnail_filename'] || !$content['thumbnail_content']) {
        http_response_code(404);
        exit('Thumbnail not found');
    }

    // Determine MIME type from filename
    $extension = strtolower(pathinfo($content['thumbnail_filename'], PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp'
    ];

    $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';

    // Set headers
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($content['thumbnail_content']));
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    header('Content-Disposition: inline; filename="' . basename($content['thumbnail_filename']) . '"');

    // Output image
    echo $content['thumbnail_content'];

} catch (Exception $e) {
    error_log("Thumbnail Error: " . $e->getMessage());
    http_response_code(500);
    exit('Failed to load thumbnail');
}
