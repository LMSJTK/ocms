<?php
/**
 * List Content API
 * Returns a list of all uploaded content
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    // Get all content (excluding binary attachment and thumbnail content to avoid JSON encoding issues)
    $content = $db->fetchAll('
        SELECT id, company_id, title, description, content_type, content_preview,
               content_url, email_from_address, email_subject, email_body_html,
               attachment_filename, thumbnail_filename, tags, difficulty,
               created_at, updated_at
        FROM content
        ORDER BY created_at DESC
    ');

    // For each content item, get its tags
    foreach ($content as &$item) {
        $tags = $db->fetchAll(
            'SELECT tag_name FROM content_tags WHERE content_id = :id',
            [':id' => $item['id']]
        );
        $item['tags'] = array_column($tags, 'tag_name');
    }

    sendJSON([
        'success' => true,
        'content' => $content
    ]);

} catch (Exception $e) {
    error_log("List Content Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to list content',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
