<?php
/**
 * Tracking Manager Class
 * Handles interaction tracking, score recording, and SNS publishing
 */

class TrackingManager {
    private $db;
    private $sns;

    public function __construct($db, $sns) {
        $this->db = $db;
        $this->sns = $sns;
    }

    /**
     * Validate external tracking session from training_tracking table
     * This supports the direct-link approach where external system generates tracking IDs
     *
     * @param string $trackingId The unique_tracking_id from training_tracking table
     * @return array|null Session data if valid, null otherwise
     */
    public function validateTrainingSession($trackingId) {
        try {
            $session = $this->db->fetchOne(
                'SELECT * FROM ' . ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking
                 WHERE unique_tracking_id = :id',
                [':id' => $trackingId]
            );

            if (!$session) {
                error_log("Invalid tracking session: $trackingId");
                return null;
            }

            return $session;
        } catch (Exception $e) {
            error_log("Error validating training session: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Track content view using training_tracking
     */
    public function trackView($trackingLinkId, $contentId = null, $recipientId = null) {
        // Validate that tracking session exists in training_tracking
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Get recipient_id from session if not provided
        if (!$recipientId) {
            $recipientId = $session['recipient_id'];
        }

        // Update training_tracking with opened timestamp
        try {
            $this->db->update(
                ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
                ['training_opened_at' => date('Y-m-d H:i:s')],
                'unique_tracking_id = :id AND training_opened_at IS NULL',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking: " . $e->getMessage());
        }

        return ['success' => true];
    }

    /**
     * Track interaction with tagged element using training_tracking
     */
    public function trackInteraction($trackingLinkId, $tagName, $interactionType, $interactionValue = null, $success = null) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        // Insert interaction record
        $this->db->insert('content_interactions', [
            'tracking_link_id' => $trackingLinkId,
            'tag_name' => $tagName,
            'interaction_type' => $interactionType,
            'interaction_value' => $interactionValue,
            'success' => $success,
            'interaction_data' => json_encode([
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
            ])
        ]);

        return ['success' => true];
    }

    /**
     * Record test score using training_tracking
     * Determines whether to use training_score or follow_on_score based on content
     */
    public function recordScore($trackingLinkId, $score, $interactions = [], $contentId = null) {
        // Validate tracking session
        $session = $this->validateTrainingSession($trackingLinkId);
        if (!$session) {
            throw new Exception("Invalid tracking session");
        }

        $recipientId = $session['recipient_id'];
        $passed = $score >= 80;
        $status = $passed ? 'passed' : 'failed';

        // Get the training record to determine which content this is
        $training = $this->db->fetchOne(
            'SELECT training_content_id, follow_on_content_id FROM ' .
            ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training WHERE id = :training_id',
            [':training_id' => $session['training_id']]
        );

        if (!$training) {
            throw new Exception("Training record not found");
        }

        // Determine if this is training or follow-on content
        $isFollowOn = ($contentId === $training['follow_on_content_id']);

        // Build update data based on whether it's training or follow-on
        if ($isFollowOn) {
            // This is follow-on content
            $updateData = [
                'follow_on_score' => $score,
                'follow_on_completed_at' => date('Y-m-d H:i:s'),
                'status' => $status
            ];
        } else {
            // This is training content
            $updateData = [
                'training_score' => $score,
                'training_completed_at' => date('Y-m-d H:i:s'),
                'status' => $status
            ];
        }

        // Update training_tracking with score and completion
        try {
            $this->db->update(
                ($this->db->getDbType() === 'pgsql' ? 'global.' : '') . 'training_tracking',
                $updateData,
                'unique_tracking_id = :id',
                [':id' => $trackingLinkId]
            );
        } catch (Exception $e) {
            error_log("Could not update training_tracking: " . $e->getMessage());
        }

        // If passed, increment tag scores for recipient
        if ($passed && $contentId) {
            $this->updateRecipientTagScores($recipientId, $contentId);
        }

        return [
            'success' => true,
            'score' => $score,
            'status' => $status,
            'content_type' => $isFollowOn ? 'follow_on' : 'training'
        ];
    }

    /**
     * Update recipient tag scores
     */
    private function updateRecipientTagScores($recipientId, $contentId) {
        // Get content tags
        $tags = $this->db->fetchAll(
            'SELECT DISTINCT tag_name FROM content_tags WHERE content_id = :content_id',
            [':content_id' => $contentId]
        );

        foreach ($tags as $tag) {
            $tagName = $tag['tag_name'];

            // Check if record exists
            $existing = $this->db->fetchOne(
                'SELECT * FROM recipient_tag_scores WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                [':recipient_id' => $recipientId, ':tag_name' => $tagName]
            );

            if ($existing) {
                // Update existing record
                $this->db->query(
                    'UPDATE recipient_tag_scores SET score_count = score_count + 1, total_attempts = total_attempts + 1, last_updated = NOW() WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                    [':recipient_id' => $recipientId, ':tag_name' => $tagName]
                );
            } else {
                // Insert new record
                $this->db->insert('recipient_tag_scores', [
                    'recipient_id' => $recipientId,
                    'tag_name' => $tagName,
                    'score_count' => 1,
                    'total_attempts' => 1
                ]);
            }
        }
    }

    /**
     * Generate unique ID
     */
    private function generateUniqueId() {
        return bin2hex(random_bytes(16));
    }
}