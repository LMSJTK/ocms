# API Documentation

## Base URL

All API endpoints are relative to `/api/`

## Authentication

Currently, no authentication is implemented. This should be added for production use.

## Endpoints

### 1. Upload Content

**Endpoint:** `POST /api/upload.php`

**Description:** Upload and process content (SCORM, HTML, Email, Video)

**Content Types:**
- `scorm` - SCORM package (ZIP)
- `html` - HTML package (ZIP)
- `raw_html` - Raw HTML content
- `email` - Phishing training email
- `video` - Video file (MP4, WebM, OGG)

#### SCORM/HTML Upload

**Request (multipart/form-data):**
```
content_type: "scorm" | "html"
title: string
description: string (optional)
company_id: string (optional, default: "default")
file: ZIP file
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Content uploaded and processed successfully",
  "tags": ["ransomware", "phishing", "password-security"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Raw HTML Upload

**Request (multipart/form-data):**
```
content_type: "raw_html"
title: string
description: string (optional)
company_id: string (optional)
html_content: string (HTML content)
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "HTML content processed successfully",
  "tags": ["topic1", "topic2"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Email Upload

**Request (multipart/form-data):**
```
content_type: "email"
title: string
email_subject: string
email_from: string
email_html: string (HTML content)
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Email content processed successfully",
  "cues": ["visual:logo-issue", "language:urgency", "technical:domain-spoofing"],
  "path": "a1b2c3d4.../index.php"
}
```

#### Video Upload

**Request (multipart/form-data):**
```
content_type: "video"
title: string
description: string (optional)
file: Video file (MP4/WebM/OGG)
```

**Response:**
```json
{
  "success": true,
  "content_id": "a1b2c3d4...",
  "message": "Video uploaded successfully",
  "path": "a1b2c3d4.../video.mp4"
}
```

---

### 2. List Content

**Endpoint:** `GET /api/list-content.php`

**Description:** Get list of all uploaded content

**Response:**
```json
{
  "success": true,
  "content": [
    {
      "id": "a1b2c3d4...",
      "company_id": "default",
      "title": "Security Awareness Training",
      "description": "Basic security awareness",
      "content_type": "scorm",
      "content_url": "a1b2c3d4.../index.php",
      "created_at": "2025-11-04 10:30:00",
      "tags": ["ransomware", "phishing"]
    }
  ]
}
```

---

### 3. Generate Launch Link

**Endpoint:** `POST /api/launch-link.php`

**Description:** Create a tracking link for content launch

**Request (application/json):**
```json
{
  "recipient_id": "user-123",
  "content_id": "a1b2c3d4...",
  "email": "user@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "company_id": "acme-corp"
}
```

**Response:**
```json
{
  "success": true,
  "tracking_link_id": "x9y8z7w6...",
  "launch_url": "/launch.php?tid=x9y8z7w6...",
  "content": {
    "id": "a1b2c3d4...",
    "title": "Security Awareness Training",
    "type": "scorm"
  }
}
```

---

### 4. Track View

**Endpoint:** `POST /api/track-view.php`

**Description:** Record when content is viewed (automatically called by launch.php)

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6..."
}
```

**Response:**
```json
{
  "success": true,
  "message": "View tracked successfully"
}
```

---

### 5. Track Interaction

**Endpoint:** `POST /api/track-interaction.php`

**Description:** Record interaction with tagged element (automatically called by tracking script)

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6...",
  "tag_name": "ransomware",
  "interaction_type": "click",
  "interaction_value": "answer_b",
  "success": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Interaction tracked successfully"
}
```

---

### 6. Record Score

**Endpoint:** `POST /api/record-score.php`

**Description:** Record final test score and publish to SNS

**Request (application/json):**
```json
{
  "tracking_link_id": "x9y8z7w6...",
  "score": 100,
  "interactions": [
    {
      "tag": "ransomware",
      "type": "click",
      "value": "correct",
      "timestamp": "2025-11-04T10:30:00Z"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "score": 100,
  "status": "passed",
  "message": "Score recorded successfully"
}
```

---

## Error Responses

All endpoints may return error responses in this format:

```json
{
  "error": "Error title",
  "message": "Detailed error message"
}
```

Common HTTP status codes:
- `200` - Success
- `400` - Bad Request (missing/invalid parameters)
- `404` - Not Found (content/tracking link not found)
- `405` - Method Not Allowed
- `500` - Internal Server Error

---

## Content Launch Flow

1. **Upload Content** → POST to `/api/upload.php`
   - Returns `content_id`
   - Content is processed and tagged by Claude API
   - Tags stored in database

2. **Generate Launch Link** → POST to `/api/launch-link.php`
   - Provide `recipient_id` and `content_id`
   - Returns `launch_url`

3. **User Opens Launch Link** → GET `/launch.php?tid={tracking_link_id}`
   - Automatically tracks view
   - Displays content with embedded tracking

4. **User Interacts with Content**
   - Tracking script automatically calls `/api/track-interaction.php`
   - Each tagged element interaction is recorded

5. **User Completes Content**
   - `RecordTest(score)` function is called
   - Triggers POST to `/api/record-score.php`
   - Score recorded, tag scores updated
   - Event published to SNS

---

## SNS Message Format

When content is completed, an SNS message is published:

```json
{
  "tracking_link_id": "x9y8z7w6...",
  "recipient_id": "user-123",
  "content_id": "a1b2c3d4...",
  "events": [
    {
      "event": "viewed",
      "timestamp": "2025-11-04T10:30:00Z"
    }
  ],
  "interactions": [
    {
      "tag": "ransomware",
      "type": "click",
      "value": "correct",
      "success": true,
      "timestamp": "2025-11-04T10:31:00Z"
    }
  ],
  "final_score": 100,
  "status": "passed",
  "completed_at": "2025-11-04T10:32:00Z"
}
```

---

## Database Schema

See `database/schema.sql` for complete schema.

### Key Tables:

- **content** - Uploaded content
- **content_tags** - Tags associated with content
- **oms_tracking_links** - Content launch tracking (recipient_id stored directly)
- **content_interactions** - Individual tagged interactions
- **recipient_tag_scores** - Cumulative tag scores per recipient
- **sns_message_queue** - Messages pending SNS publish

---

## SCORM Integration

The platform hijacks the standard SCORM `RecordTest()` function:

```javascript
// In SCORM content:
RecordTest(100); // Automatically recorded and sent to API
```

The tracking script injected into content provides:
- `RecordTest(score)` - Record final score
- Automatic interaction tracking on tagged elements

---

## Tagging System

### Educational Content (SCORM/HTML)

Claude API adds `data-tag` attributes:

```html
<input type="text" data-tag="password-security" />
<button data-tag="ransomware">Submit</button>
```

### Email Content (Phishing)

Claude API adds `data-cue` attributes based on NIST Phish Scales:

```html
<a href="..." data-cue="technical:domain-spoofing">Click here</a>
<span data-cue="language:urgency-tactic">Act now!</span>
<img data-cue="visual:logo-issue" src="..." />
```

---

## Rate Limits

No rate limits currently implemented. Should be added for production.

## CORS

CORS is enabled for all origins (`Access-Control-Allow-Origin: *`).
Restrict this in production.
