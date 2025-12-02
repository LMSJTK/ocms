-- Headless PHP Content Platform Database Schema
-- PostgreSQL Database Schema

-- Content table (already exists, but included for completeness)
CREATE TABLE IF NOT EXISTS global.content
(
    id text PRIMARY KEY,
    company_id text,
    title text,
    description text,
    content_type text, -- 'scorm', 'html', 'raw_html', 'video', 'email'
    content_preview text,
    content_url text, -- Path where content files are stored
    email_from_address text,
    email_subject text,
    email_body_html text,
    email_attachment_filename text,
    email_attachment_content bytea,
    thumbnail_filename text, -- Full URL to thumbnail image
    tags text, -- Comma-separated list of tags for quick display
    difficulty text, -- NIST Phish Scales difficulty score (1-5)
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

-- Content tags table - stores tags identified by Claude API
CREATE TABLE IF NOT EXISTS global.content_tags
(
    id SERIAL PRIMARY KEY,
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    tag_name text NOT NULL, -- e.g., 'ransomware', 'phishing', 'social-engineering'
    tag_type text, -- 'interaction', 'topic', 'phish-cue'
    confidence_score numeric(3,2), -- 0.00 to 1.00
    created_at timestamp DEFAULT now(),
    UNIQUE(content_id, tag_name)
);

CREATE INDEX idx_content_tags_content_id ON global.content_tags(content_id);
CREATE INDEX idx_content_tags_tag_name ON global.content_tags(tag_name);

-- Recipient tag scores - cumulative scores per tag per recipient
CREATE TABLE IF NOT EXISTS global.recipient_tag_scores
(
    id SERIAL PRIMARY KEY,
    recipient_id text NOT NULL,
    tag_name text NOT NULL,
    score_count integer DEFAULT 0, -- Number of times they passed content with this tag
    total_attempts integer DEFAULT 0, -- Total attempts on content with this tag
    last_updated timestamp DEFAULT now(),
    UNIQUE(recipient_id, tag_name)
);

CREATE INDEX idx_recipient_tag_scores_recipient_id ON global.recipient_tag_scores(recipient_id);

-- Tracking links table - tracks content launches
CREATE TABLE IF NOT EXISTS global.oms_tracking_links
(
    id text PRIMARY KEY,
    recipient_id text NOT NULL,
    content_id text NOT NULL REFERENCES global.content(id) ON DELETE CASCADE,
    launch_url text NOT NULL,
    status text DEFAULT 'pending', -- 'pending', 'viewed', 'completed', 'passed', 'failed'
    score integer,
    viewed_at timestamp,
    completed_at timestamp,
    created_at timestamp DEFAULT now(),
    updated_at timestamp DEFAULT now()
);

CREATE INDEX idx_tracking_links_recipient_id ON global.oms_tracking_links(recipient_id);
CREATE INDEX idx_tracking_links_content_id ON global.oms_tracking_links(content_id);
CREATE INDEX idx_tracking_links_status ON global.oms_tracking_links(status);

-- Content interactions table - tracks individual tagged interactions
CREATE TABLE IF NOT EXISTS global.content_interactions
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL REFERENCES global.oms_tracking_links(id) ON DELETE CASCADE,
    tag_name text NOT NULL,
    interaction_type text, -- 'click', 'input', 'submit', 'focus', 'blur'
    interaction_value text, -- The value if applicable (e.g., input value)
    success boolean, -- Whether the interaction was correct/successful
    interaction_data jsonb, -- Additional metadata about the interaction
    created_at timestamp DEFAULT now()
);

CREATE INDEX idx_content_interactions_tracking_link_id ON global.content_interactions(tracking_link_id);
CREATE INDEX idx_content_interactions_tag_name ON global.content_interactions(tag_name);

-- SNS message queue table - temporary storage before sending to SNS
CREATE TABLE IF NOT EXISTS global.sns_message_queue
(
    id SERIAL PRIMARY KEY,
    tracking_link_id text NOT NULL REFERENCES global.oms_tracking_links(id) ON DELETE CASCADE,
    message_data jsonb NOT NULL,
    sent boolean DEFAULT false,
    sent_at timestamp,
    created_at timestamp DEFAULT now()
);

CREATE INDEX idx_sns_queue_tracking_link_id ON global.sns_message_queue(tracking_link_id);
CREATE INDEX idx_sns_queue_sent ON global.sns_message_queue(sent);

-- Helper function to update timestamps
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Triggers for updated_at
CREATE TRIGGER update_content_updated_at BEFORE UPDATE ON global.content
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tracking_links_updated_at BEFORE UPDATE ON global.oms_tracking_links
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
