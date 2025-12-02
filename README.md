# Headless PHP Content Platform

A PHP platform for managing SCORM/HTML content with AI-powered tagging, interaction tracking, 
and AWS SNS integration.

1. A script (yet to write) will be written that will periodically check for updates in
   Phishme content management database and upload content using the REST service 
   api/upload.php. 
2. Script will run as background service in ocms-service container.
   Can load manually for now. 
3. Content will be saved directly to postgres aurora database for use by 
   platform oneview service.
4. Platform UI to call one-view service to get templates when scheduling scenarios, training etc.
5. When a user would click on a link from the phishing/scenario email, 
   it will open "immediate training".
6. ocms-service will also host data entry landing pages, follow on training, remediation training,
   fake credphish page, customization portals for emails, attachments, and landing sites
7. ocms-service will add results to queue when a person visits landing. 
8. Results will be picked up by ocms-worker and processed 

## Features

- **Content Upload**: Support for SCORM zips, HTML zips, raw HTML, and video files (mp4)
- **AI-Powered Tagging**: Automatic content tagging using Claude API
- **NIST Phish Scales**: Auto-cue detection for phishing training emails
- **Content Launch**: Generate unique launch links for recipients
- **Interaction Tracking**: Track user interactions with content elements
- **Score Recording**: SCORM-compatible score tracking
- **AWS SNS Integration**: Publish interaction events to SNS FIFO topics
- **SNS Monitoring**: Real-time viewer for published SNS messages

## Directory Structure

```
├── api/                    # API endpoints
│   ├── upload.php         # Content upload endpoint
│   ├── launch-link.php    # Generate launch links
│   ├── record-score.php   # Record test scores
│   └── track-interaction.php # Track interactions
├── config/                 # Configuration files
│   ├── config.example.php # Example configuration
│   └── config.php         # Actual config (not in git)
├── content/               # Uploaded content storage
├── database/              # Database schemas
│   ├── schema.sql        # PostgreSQL schema
│   └── schema.mysql.sql  # MySQL schema
├── lib/                   # Core libraries
│   ├── Database.php      # PDO database connection
│   ├── ClaudeAPI.php     # Claude API integration
│   ├── AWSSNS.php        # AWS SNS integration
│   ├── ContentProcessor.php # Post-upload processing
│   └── TrackingManager.php  # Interaction tracking
├── public/                # Web interface
│   ├── index.html        # Upload/management interface
│   ├── launch.php        # Content player
│   ├── sns-monitor.html  # SNS message monitor
│   ├── system-check.php  # System diagnostics
│   └── assets/           # CSS, JS assets
└── SNS_SETUP_GUIDE.md    # Guide for SNS monitoring setup
```

## Setup

1. **Database Setup**:

   The platform supports both **PostgreSQL** and **MySQL**. See [DATABASE.md](DATABASE.md) for detailed setup instructions.

   **PostgreSQL** (recommended):
   ```bash
   psql -U your_username -d your_database -f database/schema.sql
   ```

   **MySQL**:
   ```bash
   mysql -u your_username -p your_database < database/schema.mysql.sql
   ```

2. **Configuration**:
   ```bash
   cp config/config.example.php config/config.php
   # Edit config/config.php with your credentials
   # Set 'type' to 'pgsql' or 'mysql' in the database config
   ```

3. **Permissions**:
   ```bash
   chmod 755 content/
   chmod 644 config/config.php
   ```

4. **Web Server**: Configure your web server to point to the `/public` directory or use PHP's built-in server:
   ```bash
   php -S localhost:8000 -t public
   ```

## API Endpoints

### Upload Content
```
POST /api/upload.php
```

### Request Launch Link
```
POST /api/launch-link.php
Body: {
  "recipient_id": "recipient-123",
  "content_id": "content-456"
}
```

### Record Score
```
POST /api/record-score.php
Body: {
  "tracking_link_id": "link-789",
  "score": 100
}
```

### Track Interaction
```
POST /api/track-interaction.php
Body: {
  "tracking_link_id": "link-789",
  "tag_name": "ransomware",
  "interaction_type": "click",
  "success": true
}
```

## Monitoring SNS Messages

View messages published to AWS SNS in real-time:

1. **Database Viewer** (Quickest):
   - Navigate to `/public/sns-monitor.html`
   - View all messages stored in `sns_message_queue` table
   - Filter by sent/pending status
   - Auto-refresh every 10 seconds

2. **SQS Queue Subscription** (Production):
   - See `SNS_SETUP_GUIDE.md` for complete instructions
   - Create an SQS FIFO queue
   - Subscribe queue to SNS topic
   - Receive actual SNS messages for processing

The monitor shows:
- Total messages sent
- Pending vs. sent status
- Full message payload with interactions
- Recipient and content details
- Timestamp information

## Requirements

- PHP 7.4+
- **Database**: PostgreSQL 12+ OR MySQL 5.7+ / MariaDB 10.3+
- **PHP Extensions**: pdo, zip, json, curl
  - For PostgreSQL: pdo_pgsql
  - For MySQL: pdo_mysql
- AWS Account (for SNS integration)
- Claude API Key (Anthropic)

