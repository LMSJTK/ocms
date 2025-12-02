<?php
/**
 * Content Launch Player
 * Displays content and tracks user interactions
 */

require_once '/var/www/html/public/api/bootstrap.php';

// Calculate base path from config URL
$baseUrl = $config['app']['base_url'];
$parsedUrl = parse_url($baseUrl);
$basePath = $parsedUrl['path'] ?? '';

// URL format:
// New: /launch.php/{arbitrary paths}/{content_id_without_dashes}/{tracking_id_without_dashes}
// Old (backward compat): ?trackingId=xyz789&content=abc123

$pathInfo = $_SERVER['PATH_INFO'] ?? null;
$trackingParam = $_GET['trackingId'] ?? null;
$contentIdParam = $_GET['content'] ?? null;

// Try new PATH_INFO format first
if ($pathInfo) {
    // Parse PATH_INFO: /arbitrary/paths/contentid/trackingid
    // Extract last two path segments
    $pathSegments = array_values(array_filter(explode('/', $pathInfo), function($seg) {
        return $seg !== '';
    }));

    if (count($pathSegments) >= 2) {
        // Get last two segments
        $contentIdDashless = $pathSegments[count($pathSegments) - 2];
        $trackingIdDashless = $pathSegments[count($pathSegments) - 1];

        // Restore dashes to UUIDs
        $contentId = restoreUUIDDashes($contentIdDashless);
        $trackingLinkId = restoreUUIDDashes($trackingIdDashless);

        if (!$contentId || !$trackingLinkId) {
            http_response_code(400);
            echo '<h1>Error: Invalid ID format in URL</h1>';
            exit;
        }

        $trainingType = null; // Not available in PATH_INFO format
    } else {
        http_response_code(400);
        echo '<h1>Error: Invalid URL format</h1>';
        exit;
    }
} elseif ($trackingParam) {
    // Old query string format (backward compatibility): ?trackingId=xyz&content=abc
    if (!$contentIdParam) {
        http_response_code(400);
        echo '<h1>Error: Missing content ID for legacy format</h1>';
        exit;
    }
    $trackingLinkId = $trackingParam;
    $contentId = $contentIdParam;
    $trainingType = null;
} else {
    http_response_code(400);
    echo '<h1>Error: Missing tracking information</h1>';
    exit;
}

try {
    // Validate the tracking session exists in training_tracking table
    $session = $trackingManager->validateTrainingSession($trackingLinkId);
    if (!$session) {
        http_response_code(403);
        echo '<h1>Error: Invalid or expired tracking session</h1>';
        exit;
    }

    $recipientId = $session['recipient_id'];

    // Fetch content directly using the content ID from URL
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        http_response_code(404);
        echo '<h1>Error: Content not found</h1>';
        exit;
    }

    // Track view
    $trackingManager->trackView($trackingLinkId, $contentId, $recipientId);

    // Determine how to display content based on type
    $contentType = $content['content_type'];
    $contentUrl = $content['content_url'];

    switch ($contentType) {
        case 'video':
            // Display video player
            $videoPath = $config['content']['upload_dir'] . $contentUrl;
            $videoExt = pathinfo($videoPath, PATHINFO_EXTENSION);
            $mimeType = 'video/' . $videoExt;
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php echo htmlspecialchars($content['title']); ?></title>
                <style>
                    body {
                        margin: 0;
                        padding: 20px;
                        font-family: Arial, sans-serif;
                        background: #f5f5f5;
                    }
                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                        background: white;
                        padding: 20px;
                        border-radius: 8px;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    h1 {
                        margin-top: 0;
                    }
                    video {
                        width: 100%;
                        max-width: 800px;
                        display: block;
                        margin: 20px auto;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1><?php echo htmlspecialchars($content['title']); ?></h1>
                    <video controls>
                        <source src="<?php echo htmlspecialchars($basePath); ?>/content/<?php echo htmlspecialchars($contentUrl); ?>" type="<?php echo $mimeType; ?>">
                        Your browser does not support the video tag.
                    </video>
                </div>
            </body>
            </html>
            <?php
            break;

        case 'scorm':
        case 'html':
        case 'training':
        case 'landing':
        case 'email':
            // Display HTML/SCORM content via iframe or direct include
            $contentPath = $config['content']['upload_dir'] . $contentUrl;

            if (!file_exists($contentPath)) {
                http_response_code(404);
                echo '<h1>Error: Content file not found</h1>';
                exit;
            }

            // Set tracking link ID for the content to use
            $_GET['tid'] = $trackingLinkId;

            // Include the PHP content file (which has tracking script embedded)
            include $contentPath;
            break;

        default:
            http_response_code(400);
            echo '<h1>Error: Unsupported content type</h1>';
            exit;
    }

} catch (Exception $e) {
    error_log("Launch Error: " . $e->getMessage());
    http_response_code(500);
    echo '<h1>Error: Failed to load content</h1>';
    if ($config['app']['debug']) {
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}
