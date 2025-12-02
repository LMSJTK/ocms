<?php
/**
 * Report Suspicious Email Handler
 * Tracks when a user reports a suspicious email (positive outcome)
 */

require_once '/var/www/html/public/api/bootstrap.php';

// URL format: ?trackingId=xyz789
$trackingLinkId = $_GET['trackingId'] ?? null;

if (!$trackingLinkId) {
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

    // Update training_tracking with reported timestamp
    try {
        $db->update(
            ($db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
            ['training_reported_at' => date('Y-m-d H:i:s')],
            'unique_tracking_id = :id AND training_reported_at IS NULL',
            [':id' => $trackingLinkId]
        );
    } catch (Exception $e) {
        error_log("Could not update training_tracking: " . $e->getMessage());
        throw new Exception("Failed to record report");
    }

    // Display success message
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Email Reported Successfully</title>
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
            }
            .container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                padding: 48px;
                max-width: 500px;
                text-align: center;
            }
            .success-icon {
                width: 80px;
                height: 80px;
                background: #10b981;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 24px;
                animation: scaleIn 0.5s ease-out;
            }
            .success-icon svg {
                width: 48px;
                height: 48px;
                stroke: white;
                stroke-width: 3;
                fill: none;
            }
            h1 {
                color: #1f2937;
                font-size: 28px;
                margin: 0 0 16px 0;
                font-weight: 600;
            }
            p {
                color: #6b7280;
                font-size: 16px;
                line-height: 1.6;
                margin: 0 0 24px 0;
            }
            .info-box {
                background: #f3f4f6;
                border-radius: 8px;
                padding: 16px;
                margin-top: 24px;
                text-align: left;
            }
            .info-box strong {
                color: #374151;
                display: block;
                margin-bottom: 8px;
            }
            .info-box ul {
                margin: 0;
                padding-left: 20px;
                color: #6b7280;
                font-size: 14px;
            }
            .info-box li {
                margin-bottom: 4px;
            }
            @keyframes scaleIn {
                0% {
                    transform: scale(0);
                    opacity: 0;
                }
                50% {
                    transform: scale(1.1);
                }
                100% {
                    transform: scale(1);
                    opacity: 1;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="success-icon">
                <svg viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h1>Great Job!</h1>
            <p>
                You've successfully reported this suspicious email. This is exactly what you
                should do when you receive a message that looks like it might be a phishing attempt.
            </p>
            <p>
                Your report has been recorded and your security team has been notified.
            </p>

            <div class="info-box">
                <strong>Remember to look for these warning signs:</strong>
                <ul>
                    <li>Urgent or threatening language</li>
                    <li>Requests for personal information</li>
                    <li>Suspicious sender addresses</li>
                    <li>Unexpected attachments or links</li>
                    <li>Spelling and grammar errors</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    error_log("Report Error: " . $e->getMessage());
    http_response_code(500);
    echo '<h1>Error: Failed to record report</h1>';
    if ($config['app']['debug']) {
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}