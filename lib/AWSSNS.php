<?php
/**
 * AWS SNS Integration Class
 * Handles publishing messages to AWS SNS topics
 * Uses AWS Signature Version 4 for authentication (no SDK required)
 */

class AWSSNS {
    private $config;
    private $region;
    private $accessKeyId;
    private $secretAccessKey;
    private $topicArn;

    public function __construct($config) {
        $this->config = $config;
        $this->region = $config['region'];
        $this->accessKeyId = $config['access_key_id'];
        $this->secretAccessKey = $config['secret_access_key'];
        $this->topicArn = $config['topic_arn'];
    }

    /**
     * Publish a message to SNS topic
     */
    public function publish($message, $subject = null, $attributes = [], $messageGroupId = null) {
        $endpoint = "https://sns.{$this->region}.amazonaws.com/";

        // Prepare message data
        if (is_array($message) || is_object($message)) {
            $message = json_encode($message);
        }

        $params = [
            'Action' => 'Publish',
            'TopicArn' => $this->topicArn,
            'Message' => $message,
            'Version' => '2010-03-31'
        ];

        if ($subject) {
            $params['Subject'] = $subject;
        }

        // Check if this is a FIFO topic (ARN ends with .fifo)
        $isFifo = substr($this->topicArn, -5) === '.fifo';
        if ($isFifo) {
            // FIFO topics require MessageGroupId
            $params['MessageGroupId'] = $messageGroupId ?: 'default-group';
            // MessageDeduplicationId is optional if ContentBasedDeduplication is enabled
            // If not enabled, we'll generate one based on message content
            $params['MessageDeduplicationId'] = md5($message . time());
        }

        // Add message attributes
        $attrIndex = 1;
        foreach ($attributes as $name => $value) {
            $params["MessageAttributes.entry.{$attrIndex}.Name"] = $name;
            $params["MessageAttributes.entry.{$attrIndex}.Value.DataType"] = 'String';
            $params["MessageAttributes.entry.{$attrIndex}.Value.StringValue"] = $value;
            $attrIndex++;
        }

        try {
            $response = $this->makeRequest($endpoint, $params);
            return $this->parseResponse($response);
        } catch (Exception $e) {
            error_log("SNS Publish Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Make signed AWS request
     */
    private function makeRequest($endpoint, $params) {
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Add required parameters
        $params['Timestamp'] = $timestamp;

        // Sort parameters
        ksort($params);

        // Build query string
        $queryString = http_build_query($params);

        // Create canonical request
        $canonicalRequest = "POST\n/\n\n" .
            "content-type:application/x-www-form-urlencoded; charset=utf-8\n" .
            "host:sns.{$this->region}.amazonaws.com\n" .
            "x-amz-date:{$timestamp}\n\n" .
            "content-type;host;x-amz-date\n" .
            hash('sha256', $queryString);

        // Create string to sign
        $credentialScope = "{$date}/{$this->region}/sns/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" .
            hash('sha256', $canonicalRequest);

        // Calculate signature
        $signature = $this->calculateSignature($stringToSign, $date);

        // Create authorization header
        $authorization = "AWS4-HMAC-SHA256 " .
            "Credential={$this->accessKeyId}/{$credentialScope}, " .
            "SignedHeaders=content-type;host;x-amz-date, " .
            "Signature={$signature}";

        // Make HTTP request
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $queryString,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'Host: sns.' . $this->region . '.amazonaws.com',
                'X-Amz-Date: ' . $timestamp,
                'Authorization: ' . $authorization
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("SNS HTTP Error {$httpCode}: {$response}");
        }

        return $response;
    }

    /**
     * Calculate AWS Signature Version 4
     */
    private function calculateSignature($stringToSign, $date) {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 'sns', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * Parse SNS XML response
     */
    private function parseResponse($xmlString) {
        $xml = simplexml_load_string($xmlString);

        if ($xml === false) {
            throw new Exception("Failed to parse SNS response");
        }

        // Check for errors
        if (isset($xml->Error)) {
            $errorCode = (string)$xml->Error->Code;
            $errorMessage = (string)$xml->Error->Message;
            throw new Exception("SNS Error [{$errorCode}]: {$errorMessage}");
        }

        // Extract MessageId if available
        if (isset($xml->PublishResult->MessageId)) {
            return [
                'success' => true,
                'message_id' => (string)$xml->PublishResult->MessageId
            ];
        }

        return ['success' => true];
    }

    /**
     * Publish interaction tracking event
     */
    public function publishInteractionEvent($trackingLinkId, $recipientId, $contentId, $interactions, $score = null) {
        $message = [
            'event_type' => 'content_interaction',
            'tracking_link_id' => $trackingLinkId,
            'recipient_id' => $recipientId,
            'content_id' => $contentId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'interactions' => $interactions
        ];

        if ($score !== null) {
            $message['final_score'] = $score;
        }

        return $this->publish(
            $message,
            'Content Interaction Event',
            [
                'event_type' => 'content_interaction',
                'recipient_id' => $recipientId,
                'content_id' => $contentId
            ],
            $recipientId // Use recipient_id as MessageGroupId for FIFO topics
        );
    }
}
