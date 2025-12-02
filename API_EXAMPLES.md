# API Usage Examples

This document provides CURL examples for uploading different content types to the OCMS API.

## Base URL

Replace `http://localhost` with actual base URL:

```bash
API_BASE="http://localhost/api"
```

## Authentication

Some API endpoints require bearer token authentication (configured in `config/config.php`). Set token:

```bash
BEARER_TOKEN="secure_random_token_here"
```

**Protected Endpoints (require bearer token):**
- `/upload.php` - Content uploads
- `/list-content.php` - Content listing
- `/launch-link.php` - Tracking link generation

**Public Endpoints (no authentication):**
- `/record-score.php` - Score recording (called by end users)
- `/track-interaction.php` - Interaction tracking (called by end users)
- `/track-view.php` - View tracking (called by end users)
- `/sns-messages.php` - SNS webhook receiver

For protected endpoints, include the Authorization header:

```bash
-H "Authorization: Bearer ${BEARER_TOKEN}"
```

## Upload Landing Page

Landing pages are HTML content designated for use as landing pages (e.g., after clicking email links).

### Example 1: Simple Landing Page

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=landing" \
  -F "title=Welcome Landing Page" \
  -F "description=Landing page for campaign" \
  -F "company_id=acme-corp" \
  -F "html_content=<!DOCTYPE html>
<html>
<head>
    <title>Welcome</title>
</head>
<body>
    <h1>Welcome to our security training!</h1>
    <p>You successfully identified the phishing attempt.</p>
</body>
</html>"
```

### Example 2: Landing Page from File

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=landing" \
  -F "title=Training Complete" \
  -F "description=Success landing page" \
  -F "company_id=acme-corp" \
  -F "html_content=<landing.html"
```

### Response Format

```json
{
  "success": true,
  "content_id": "a1b2c3d4e5f6...",
  "message": "Landing page processed successfully",
  "tags": ["security", "training"],
  "path": "a1b2c3d4e5f6.../index.php",
  "preview_url": "http://localhost/public/launch.php?tid=preview_tracking_id"
}
```

## Upload Email (Phishing Training)

### Example 1: Email without Attachment

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=email" \
  -F "title=Phishing Test - Fake Invoice" \
  -F "description=Invoice phishing simulation" \
  -F "company_id=acme-corp" \
  -F "email_subject=Urgent: Invoice Payment Required" \
  -F "email_from=billing@fake-company.com" \
  -F "email_html=<!DOCTYPE html>
<html>
<head><title>Invoice</title></head>
<body>
    <p>Dear Customer,</p>
    <p>Please click here to view your invoice: <a href='http://malicious.com'>View Invoice</a></p>
</body>
</html>"
```

### Example 2: Email with Attachment

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=email" \
  -F "title=Phishing Test - Fake Invoice with PDF" \
  -F "description=Invoice phishing simulation with malicious attachment" \
  -F "company_id=acme-corp" \
  -F "email_subject=Urgent: Invoice Payment Required" \
  -F "email_from=billing@fake-company.com" \
  -F "email_html=<!DOCTYPE html>
<html>
<head><title>Invoice</title></head>
<body>
    <p>Dear Customer,</p>
    <p>Please see the attached invoice for payment.</p>
    <p>Click here to download: <a href='http://malicious.com'>Download Invoice</a></p>
</body>
</html>" \
  -F "attachment=@/path/to/fake_invoice.pdf"
```

### Response Format

```json
{
  "success": true,
  "content_id": "a1b2c3d4e5f6...",
  "message": "Email content processed successfully",
  "cues": ["urgent-language", "suspicious-link", "unknown-sender"],
  "difficulty": 2,
  "path": "a1b2c3d4e5f6.../index.php",
  "preview_url": "http://localhost/public/launch.php?tid=preview_tracking_id",
  "attachment_filename": "fake_invoice.pdf",
  "attachment_size": 45678
}
```

## Upload SCORM Package

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=scorm" \
  -F "title=Security Awareness Training" \
  -F "description=Interactive SCORM course" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/scorm-package.zip"
```

## Upload HTML Package

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=html" \
  -F "title=Interactive HTML Training" \
  -F "description=HTML5 based training module" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/html-package.zip"
```

## Upload Raw HTML

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=raw_html" \
  -F "title=Simple HTML Page" \
  -F "description=Basic HTML content" \
  -F "company_id=acme-corp" \
  -F "html_content=<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body><h1>Hello World</h1></body>
</html>"
```

## Upload Video

```bash
curl -X POST "${API_BASE}/upload.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -F "content_type=video" \
  -F "title=Security Training Video" \
  -F "description=Introduction to cybersecurity" \
  -F "company_id=acme-corp" \
  -F "file=@/path/to/video.mp4"
```

## Generate Launch Link

After uploading content, create a launch link for a recipient:

```bash
curl -X POST "${API_BASE}/launch-link.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient_id": "user123",
    "content_id": "a1b2c3d4e5f6...",
    "company_id": "acme-corp",
    "email": "user@company.com",
    "first_name": "John",
    "last_name": "Doe"
  }'
```

### Response Format

```json
{
  "success": true,
  "tracking_link_id": "tracking123...",
  "launch_url": "http://localhost/public/launch.php?tid=tracking123...",
  "content": {
    "id": "a1b2c3d4e5f6...",
    "title": "Welcome Landing Page",
    "type": "landing"
  }
}
```

## List All Content

```bash
curl -X GET "${API_BASE}/list-content.php" \
  -H "Authorization: Bearer ${BEARER_TOKEN}"
```

### Response Format

```json
{
  "success": true,
  "content": [
    {
      "id": "a1b2c3d4e5f6...",
      "company_id": "acme-corp",
      "title": "Welcome Landing Page",
      "description": "Landing page for campaign",
      "content_type": "landing",
      "content_preview": "http://localhost/public/launch.php?tid=preview_tracking_id",
      "content_url": "a1b2c3d4e5f6.../index.php",
      "tags": ["security", "training"],
      "difficulty": null,
      "created_at": "2025-01-15 10:30:00",
      "updated_at": "2025-01-15 10:30:00"
    }
  ]
}
```

## Track Interaction

```bash
curl -X POST "${API_BASE}/track-interaction.php" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_link_id": "tracking123...",
    "tag_name": "phishing",
    "interaction_type": "click",
    "success": true,
    "interaction_value": "suspicious-link"
  }'
```

## Record Score

```bash
curl -X POST "${API_BASE}/record-score.php" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_link_id": "tracking123...",
    "score": 85
  }'
```

## Content Types Summary

| Type | Description | Input Format | Use Case |
|------|-------------|--------------|----------|
| `landing` | Landing page HTML | `html_content` (form field) | Post-click landing pages |
| `email` | Phishing training email | `email_html`, `email_subject`, `email_from` | Phishing simulations |
| `scorm` | SCORM package | `file` (ZIP upload) | E-learning courses |
| `html` | HTML package | `file` (ZIP upload) | Interactive HTML content |
| `raw_html` | Simple HTML | `html_content` (form field) | Basic HTML pages |
| `video` | Video file | `file` (MP4/WEBM/OGG) | Training videos |

## Notes

- All uploads automatically generate a preview link (uses recipient_id="preview")
- Content IDs are automatically generated (32-character hex string)
- Preview links don't affect tracking statistics
- Landing pages use the same processing as raw HTML but are designated as 'landing' type for organizational purposes
