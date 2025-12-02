-- Headless PHP Content Platform Database Schema
-- MySQL Database Schema

-- Content table
CREATE TABLE IF NOT EXISTS content
(
    id VARCHAR(255) PRIMARY KEY,
    company_id VARCHAR(255),
    title TEXT,
    description TEXT,
    content_type VARCHAR(50), -- 'scorm', 'html', 'raw_html', 'video', 'email'
    content_preview TEXT,
    content_url TEXT, -- Path where content files are stored
    email_from_address VARCHAR(255),
    email_subject TEXT,
    email_body_html LONGTEXT,
    email_attachment_filename VARCHAR(255),
    email_attachment_content LONGBLOB,
    thumbnail_filename TEXT, -- Full URL to thumbnail image
    tags TEXT, -- Comma-separated list of tags for quick display
    difficulty VARCHAR(10), -- NIST Phish Scales difficulty score (1-5)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content tags table - stores tags identified by Claude API
CREATE TABLE IF NOT EXISTS content_tags
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_id VARCHAR(255) NOT NULL,
    tag_name VARCHAR(255) NOT NULL, -- e.g., 'ransomware', 'phishing', 'social-engineering'
    tag_type VARCHAR(50), -- 'interaction', 'topic', 'phish-cue'
    confidence_score DECIMAL(3,2), -- 0.00 to 1.00
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_content_tag (content_id, tag_name),
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_content_tags_content_id ON content_tags(content_id);
CREATE INDEX idx_content_tags_tag_name ON content_tags(tag_name);

-- Recipient tag scores - cumulative scores per tag per recipient
CREATE TABLE IF NOT EXISTS recipient_tag_scores
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id VARCHAR(255) NOT NULL,
    tag_name VARCHAR(255) NOT NULL,
    score_count INT DEFAULT 0, -- Number of times they passed content with this tag
    total_attempts INT DEFAULT 0, -- Total attempts on content with this tag
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_recipient_tag (recipient_id, tag_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_recipient_tag_scores_recipient_id ON recipient_tag_scores(recipient_id);

-- Tracking links table - tracks content launches
CREATE TABLE IF NOT EXISTS oms_tracking_links
(
    id VARCHAR(255) PRIMARY KEY,
    recipient_id VARCHAR(255) NOT NULL,
    content_id VARCHAR(255) NOT NULL,
    launch_url TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'viewed', 'completed', 'passed', 'failed'
    score INT,
    viewed_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tracking_links_recipient_id ON oms_tracking_links(recipient_id);
CREATE INDEX idx_tracking_links_content_id ON oms_tracking_links(content_id);
CREATE INDEX idx_tracking_links_status ON oms_tracking_links(status);

-- Content interactions table - tracks individual tagged interactions
CREATE TABLE IF NOT EXISTS content_interactions
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_link_id VARCHAR(255) NOT NULL,
    tag_name VARCHAR(255) NOT NULL,
    interaction_type VARCHAR(50), -- 'click', 'input', 'submit', 'focus', 'blur'
    interaction_value TEXT, -- The value if applicable (e.g., input value)
    success BOOLEAN, -- Whether the interaction was correct/successful
    interaction_data JSON, -- Additional metadata about the interaction
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_link_id) REFERENCES oms_tracking_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_content_interactions_tracking_link_id ON content_interactions(tracking_link_id);
CREATE INDEX idx_content_interactions_tag_name ON content_interactions(tag_name);

-- SNS message queue table - temporary storage before sending to SNS
CREATE TABLE IF NOT EXISTS sns_message_queue
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_link_id VARCHAR(255) NOT NULL,
    message_data JSON NOT NULL,
    sent BOOLEAN DEFAULT false,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_link_id) REFERENCES oms_tracking_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_sns_queue_tracking_link_id ON sns_message_queue(tracking_link_id);
CREATE INDEX idx_sns_queue_sent ON sns_message_queue(sent);
