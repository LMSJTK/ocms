<?php
/**
 * Content Upload API
 * Handles file uploads for SCORM, HTML, videos, and raw HTML
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Increase limits for large content processing
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '512M'); // 512MB memory

error_log("=== UPLOAD.PHP STARTING ===");

require_once '/var/www/html/public/api/bootstrap.php';
error_log("Bootstrap loaded successfully");

// Validate bearer token authentication
validateBearerToken($config);

/**
 * Generate a preview link for content using training_tracking
 */
function generatePreviewLink($contentId, $db, $trackingManager, $config) {
    // Generate unique IDs for preview
    $trainingId = generateUUID4();
    $trainingTrackingId = generateUUID4();
    $uniqueTrackingId = generateUUID4();

    // Get content details for training name
    $content = $db->fetchOne(
        'SELECT title FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    // Create training record for preview
    $trainingRecord = [
        'id' => $trainingId,
        'company_id' => 'system',
        'name' => 'Preview: ' . ($content['title'] ?? 'Content'),
        'description' => 'Auto-generated training for content preview',
        'training_type' => 'preview',
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
        'recipient_id' => 'preview',
        'unique_tracking_id' => $uniqueTrackingId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $db->insert(
        ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
        $trainingTrackingData
    );

    // Build preview URL with PATH_INFO format (dashless IDs)
    // Format: /launch.php/{content_id_without_dashes}/{tracking_id_without_dashes}
    $contentIdNoDash = str_replace('-', '', $contentId);
    $trackingIdNoDash = str_replace('-', '', $uniqueTrackingId);
    $previewUrl = rtrim($config['app']['base_url'], '/') . '/public/launch.php/' . $contentIdNoDash . '/' . $trackingIdNoDash;

    // Update content with preview link
    $db->update('content',
        ['content_preview' => $previewUrl],
        'id = :id',
        [':id' => $contentId]
    );

    return $previewUrl;
}

/**
 * Process thumbnail upload
 * Returns full URL to the thumbnail, or null if no thumbnail
 */
function processThumbnail($contentId, $config) {
    if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Thumbnail upload failed');
    }

    $thumbnailFile = $_FILES['thumbnail'];
    $thumbnailFilename = basename($thumbnailFile['name']);

    // Validate it's an image file
    $imageInfo = getimagesize($thumbnailFile['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('Thumbnail must be a valid image file');
    }

    // Create content directory
    $contentDir = $config['content']['upload_dir'] . $contentId . '/';
    if (!is_dir($contentDir)) {
        mkdir($contentDir, 0755, true);
    }

    // Save thumbnail to content directory
    $thumbnailPath = $contentDir . $thumbnailFilename;
    if (!move_uploaded_file($thumbnailFile['tmp_name'], $thumbnailPath)) {
        throw new Exception('Failed to save thumbnail file');
    }

    // Build full URL to thumbnail
    $thumbnailUrl = rtrim($config['app']['base_url'], '/') . '/content/' . $contentId . '/' . $thumbnailFilename;

    return $thumbnailUrl;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Wrong method: " . $_SERVER['REQUEST_METHOD']);
    sendJSON(['error' => 'Method not allowed'], 405);
}

error_log("POST request received");

try {
    // Get form data
    $contentType = $_POST['content_type'] ?? null;
    $title = $_POST['title'] ?? 'Untitled Content';
    $description = $_POST['description'] ?? '';
    $companyId = $_POST['company_id'] ?? null;
    $domainId = isset($_POST['domain_id']) && $_POST['domain_id'] !== '' ? $_POST['domain_id'] : null;

    error_log("Content type: " . ($contentType ?? 'NULL'));
    error_log("Title: " . $title);
    error_log("Domain ID: " . ($domainId ?? 'NULL'));

    if (!$contentType) {
        sendJSON(['error' => 'content_type is required'], 400);
    }

    // Validate domain_id if provided (check if table exists first)
    if ($domainId !== null) {
        try {
            $domain = $db->fetchOne(
                'SELECT * FROM ' . ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'domains WHERE id = :id AND is_active = :is_active',
                [':id' => $domainId, ':is_active' => 1]
            );

            if (!$domain) {
                sendJSON(['error' => 'Invalid or inactive domain_id'], 400);
            }
        } catch (Exception $e) {
            // If domains table doesn't exist, just log and continue without domain
            error_log("Domain validation skipped (table may not exist): " . $e->getMessage());
            $domainId = null;
        }
    }

    // Generate unique content ID
    $contentId = generateUUID4();

    // Handle different content types
    switch ($contentType) {
        case 'scorm':
        case 'html':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($fileExt !== 'zip') {
                sendJSON(['error' => 'Only ZIP files are allowed for ' . $contentType], 400);
            }

            // Move uploaded file to temp location
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                sendJSON(['error' => 'Failed to save uploaded file'], 500);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => $contentType,
                'content_url' => null // Will be set after processing
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process content
            $result = $contentProcessor->processContent($contentId, $contentType, $tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Content uploaded and processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'video':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($fileExt, ['mp4', 'webm', 'ogg'])) {
                sendJSON(['error' => 'Invalid video format. Allowed: mp4, webm, ogg'], 400);
            }

            // Create directory
            $videoDir = $config['content']['upload_dir'] . $contentId . '/';
            if (!is_dir($videoDir)) {
                mkdir($videoDir, 0755, true);
            }

            $videoPath = $videoDir . 'video.' . $fileExt;
            if (!move_uploaded_file($file['tmp_name'], $videoPath)) {
                sendJSON(['error' => 'Failed to save video file'], 500);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'video',
                'content_url' => $contentId . '/video.' . $fileExt
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Video uploaded successfully',
                'path' => $contentId . '/video.' . $fileExt,
                'preview_url' => $previewUrl
            ]);
            break;

        case 'training':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'training',
                'content_url' => null
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process content
            $result = $contentProcessor->processContent($contentId, 'training', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'HTML content processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'landing':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'landing',
                'content_url' => null
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process landing page content (similar to training but designated as landing)
            $result = $contentProcessor->processContent($contentId, 'landing', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Landing page processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'email':
            $emailHTML = $_POST['email_html'] ?? null;
            $emailSubject = $_POST['email_subject'] ?? '';
            $emailFrom = $_POST['email_from'] ?? '';

            if (!$emailHTML) {
                sendJSON(['error' => 'email_html is required'], 400);
            }

            // Handle attachment if provided
            $attachmentFilename = null;
            $attachmentContent = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $attachmentFile = $_FILES['attachment'];
                $attachmentFilename = basename($attachmentFile['name']);

                // Create content directory
                $contentDir = $config['content']['upload_dir'] . $contentId . '/';
                if (!is_dir($contentDir)) {
                    mkdir($contentDir, 0755, true);
                }

                // Save attachment to content directory
                $attachmentPath = $contentDir . $attachmentFilename;
                if (!move_uploaded_file($attachmentFile['tmp_name'], $attachmentPath)) {
                    sendJSON(['error' => 'Failed to save attachment file'], 500);
                }

                // Read file content as binary for database storage
                $attachmentContent = file_get_contents($attachmentPath);
            }

            // Process thumbnail if provided
            $thumbnailUrl = processThumbnail($contentId, $config);

            // Save email HTML to temp file
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.html';
            file_put_contents($tempPath, $emailHTML);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'email',
                'email_subject' => $emailSubject,
                'email_from_address' => $emailFrom,
                'email_body_html' => $emailHTML,
                'content_url' => null
            ];

            // Only add domain_id if it's set (for backwards compatibility)
            if ($domainId !== null) {
                $insertData['domain_id'] = $domainId;
            }

            // Add attachment fields if attachment was provided
            if ($attachmentFilename !== null) {
                $insertData['email_attachment_filename'] = $attachmentFilename;
                $insertData['email_attachment_content'] = $attachmentContent;
            }

            // Add thumbnail field if thumbnail was provided
            if ($thumbnailUrl !== null) {
                $insertData['thumbnail_filename'] = $thumbnailUrl;
            }

            $db->insert('content', $insertData);

            // Process email content
            $result = $contentProcessor->processContent($contentId, 'email', $tempPath);

            // Clean up temp file
            unlink($tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Email content processed successfully',
                'cues' => $result['cues'] ?? [],
                'difficulty' => $result['difficulty'] ?? null,
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ];

            // Include attachment info if present
            if ($attachmentFilename !== null) {
                $response['attachment_filename'] = $attachmentFilename;
                $response['attachment_size'] = strlen($attachmentContent);
            }

            sendJSON($response);
            break;

        default:
            sendJSON(['error' => 'Invalid content_type'], 400);
    }

} catch (Exception $e) {
    error_log("Upload Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJSON([
        'error' => 'Upload failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $config['app']['debug'] ? $e->getTraceAsString() : null
    ], 500);
}
