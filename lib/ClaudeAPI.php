<?php
/**
 * Claude API Integration Class
 * Handles communication with Anthropic's Claude API for content tagging
 */

class ClaudeAPI {
    private $config;
    private $apiKey;
    private $apiUrl;
    private $model;
    private $maxTokens;
    private $maxContentSize;

    public function __construct($config) {
        $this->config = $config;
        $this->apiKey = $config['api_key'];
        $this->apiUrl = $config['api_url'];
        $this->model = $config['model'];
        $this->maxTokens = $config['max_tokens'];
        $this->maxContentSize = $config['max_content_size'] ?? 500000; // Default to 500KB
    }

    /**
     * Send request to Claude API
     */
    private function sendRequest($messages, $systemPrompt = null) {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Claude API cURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("Claude API HTTP Error {$httpCode}: {$response}");
        }

        $result = json_decode($response, true);
        if (!isset($result['content'][0]['text'])) {
            throw new Exception("Unexpected Claude API response format");
        }

        // Log if response was truncated
        if (isset($result['stop_reason']) && $result['stop_reason'] === 'max_tokens') {
            error_log("WARNING: Claude API response truncated due to max_tokens limit");
        }

        return $result['content'][0]['text'];
    }

    /**
     * Strip markdown code blocks from response
     */
    private function stripMarkdownCodeBlocks($text) {
        // Remove ```html ... ``` or ```... ``` blocks
        $text = preg_replace('/```(?:html)?\s*\n?(.*?)\n?```/s', '$1', $text);
        // Also remove any leading/trailing whitespace
        return trim($text);
    }

    /**
     * Extract only HTML from response, removing explanatory text
     */
    private function extractHTMLOnly($text) {
        // If the text starts with <!DOCTYPE or <html or <, it's likely all HTML
        if (preg_match('/^\s*(<(!DOCTYPE|html|head|body|div|form|script|style|!--|meta|link))/i', $text)) {
            // Find where HTML ends - look for common ending patterns followed by explanation text
            // Look for closing </html> or </body> followed by non-HTML text
            if (preg_match('/(.*?<\/html>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/(.*?<\/body>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
        }

        // Try to extract HTML between first < and last >
        if (preg_match('/<.*>/s', $text, $matches)) {
            return trim($matches[0]);
        }

        return trim($text);
    }

    /**
     * Protect sensitive HTML blocks (e.g., script/link tags) by replacing them with placeholders
     * Returns array with 'html' (placeholders inserted) and 'protectedBlocks' (placeholder => original block)
     */
    private function protectSensitiveBlocks($html) {
        $protectedBlocks = [];
        $tokenCounter = 0;

        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<link\b[^>]*?>/i'
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function ($matches) use (&$protectedBlocks, &$tokenCounter) {
                $token = '__PROTECTED_BLOCK_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $placeholder = '<!-- ' . $token . ' -->';
                $protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            }, $html);
        }

        return [
            'html' => $html,
            'protectedBlocks' => $protectedBlocks
        ];
    }

    /**
     * Restore sensitive HTML blocks that were replaced with placeholders
     */
    private function restoreProtectedBlocks($html, $protectedBlocks) {
        foreach ($protectedBlocks as $placeholder => $originalBlock) {
            $html = str_replace($placeholder, $originalBlock, $html);
        }

        return $html;
    }

    /**
     * Extract and tokenize file references from HTML
     * Returns array with 'html' (tokenized) and 'referenceMap' (token => original value)
     */
    private function tokenizeReferences($html) {
        $referenceMap = [];
        $tokenCounter = 0;

        // Attributes that contain file references
        $attributePatterns = [
            '/\ssrc\s*=\s*["\']([^"\']+)["\']/i',
            '/\shref\s*=\s*["\']([^"\']+)["\']/i',
            '/\ssrcset\s*=\s*["\']([^"\']+)["\']/i',
            '/\sposter\s*=\s*["\']([^"\']+)["\']/i',
            '/\sdata-src\s*=\s*["\']([^"\']+)["\']/i',
            '/\sdata-href\s*=\s*["\']([^"\']+)["\']/i',
            '/\saction\s*=\s*["\']([^"\']+)["\']/i',
            '/\sbackground\s*=\s*["\']([^"\']+)["\']/i',
        ];

        foreach ($attributePatterns as $pattern) {
            $html = preg_replace_callback($pattern, function($matches) use (&$referenceMap, &$tokenCounter) {
                $fullMatch = $matches[0];
                $url = $matches[1];

                // Skip if it's already a placeholder, data URI, or absolute https URL to external domains
                if (strpos($url, '__ASSET_REF_') === 0 ||
                    strpos($url, 'data:') === 0 ||
                    strpos($url, 'javascript:') === 0 ||
                    strpos($url, 'mailto:') === 0) {
                    return $fullMatch;
                }

                // Generate unique token
                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                // Replace the URL in the matched attribute with the token
                return str_replace($url, $token, $fullMatch);
            }, $html);
        }

        // Handle CSS url() references in style attributes
        $html = preg_replace_callback('/\sstyle\s*=\s*["\']([^"\']*url\([^)]+\)[^"\']*)["\']/i', function($matches) use (&$referenceMap, &$tokenCounter) {
            $fullMatch = $matches[0];
            $styleContent = $matches[1];

            // Find all url() references within this style attribute
            $tokenizedStyle = preg_replace_callback('/url\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', function($urlMatches) use (&$referenceMap, &$tokenCounter) {
                $url = $urlMatches[1];

                // Skip data URIs and already-tokenized values
                if (strpos($url, '__ASSET_REF_') === 0 || strpos($url, 'data:') === 0) {
                    return $urlMatches[0];
                }

                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                return str_replace($url, $token, $urlMatches[0]);
            }, $styleContent);

            return str_replace($styleContent, $tokenizedStyle, $fullMatch);
        }, $html);

        // Handle CSS url() references in <style> tags
        $html = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) use (&$referenceMap, &$tokenCounter) {
            $fullMatch = $matches[0];
            $styleContent = $matches[1];

            $tokenizedStyle = preg_replace_callback('/url\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', function($urlMatches) use (&$referenceMap, &$tokenCounter) {
                $url = $urlMatches[1];

                if (strpos($url, '__ASSET_REF_') === 0 || strpos($url, 'data:') === 0) {
                    return $urlMatches[0];
                }

                $token = '__ASSET_REF_' . str_pad($tokenCounter++, 4, '0', STR_PAD_LEFT) . '__';
                $referenceMap[$token] = $url;

                return str_replace($url, $token, $urlMatches[0]);
            }, $styleContent);

            return str_replace($styleContent, $tokenizedStyle, $fullMatch);
        }, $html);

        return [
            'html' => $html,
            'referenceMap' => $referenceMap
        ];
    }

    /**
     * Restore original file references from tokens
     */
    private function restoreReferences($html, $referenceMap) {
        // Simple str_replace for each token
        foreach ($referenceMap as $token => $originalUrl) {
            $html = str_replace($token, $originalUrl, $html);
        }

        return $html;
    }

    /**
     * Tag HTML content with interactive elements
     */
    public function tagHTMLContent($htmlContent, $contentType = 'educational') {
        // Check content size - skip AI processing if too large
        $contentSize = strlen($htmlContent);
        if ($contentSize > $this->maxContentSize) {
            error_log("Content size ({$contentSize} bytes) exceeds max_content_size ({$this->maxContentSize} bytes) - skipping AI processing");
            return [
                'html' => $htmlContent,
                'tags' => []
            ];
        }

        error_log("Content size: {$contentSize} bytes - proceeding with AI processing");

        // STEP 0: Protect sensitive blocks before any other processing
        $protected = $this->protectSensitiveBlocks($htmlContent);
        $protectedHtml = $protected['html'];
        $protectedBlocks = $protected['protectedBlocks'];

        error_log("Protected " . count($protectedBlocks) . " sensitive blocks before AI processing");

        // STEP 1: Tokenize all file references before sending to AI
        $tokenized = $this->tokenizeReferences($protectedHtml);
        $tokenizedHtml = $tokenized['html'];
        $referenceMap = $tokenized['referenceMap'];

        error_log("Tokenized " . count($referenceMap) . " file references before AI processing");

        // Allowed tags for educational content
        $allowedTags = [
            'brand-impersonation', 'compliance', 'emotions', 'financial-transactions',
            'general-phishing', 'cloud', 'mobile', 'news-and-events', 'office-communications',
            'passwords', 'reporting', 'safe-web-browsing', 'shipment-and-deliveries',
            'small-medium-businesses', 'social-media', 'spear-phishing', 'data-breach',
            'malware', 'mfa', 'personal-security', 'physical-security', 'ransomware',
            'shared-file', 'attachment-phish', 'bec-ceo-fraud', 'credential-phish',
            'qr-codes', 'url-phish'
        ];

        $allowedTagsList = implode(', ', $allowedTags);

        $systemPrompt = "You are an expert at analyzing educational content and identifying key assessment elements. " .
            "Your task is to SELECTIVELY add data-tag attributes ONLY to the most important interactive elements that represent core learning objectives or assessments.\n\n" .
            "ALLOWED TAGS (use ONLY these tags):\n" .
            "$allowedTagsList\n\n" .
            "CRITICAL RULES - FOLLOW EXACTLY:\n" .
            "1. ONLY add data-tag attributes to interactive elements (inputs, buttons, selects, textareas, clickable elements)\n" .
            "2. Tag values MUST be one of the allowed tags listed above - use ONLY these exact tag names\n" .
            "3. Make ZERO other changes to the HTML - preserve ALL:\n" .
            "   - Exact formatting, spacing, and indentation\n" .
            "   - All existing attributes exactly as written\n" .
            "   - All content, text, and structure\n" .
            "   - All comments, scripts, and styles\n" .
            "   - Character encoding and special characters\n" .
            "4. Return the COMPLETE HTML exactly as provided, with ONLY data-tag attributes added where appropriate\n" .
            "5. Do not fix, clean, or optimize the HTML in any way\n" .
            "6. Do not include explanations, comments, or markdown - return ONLY the raw HTML\n" .
            "7. If this appears to be a partial HTML chunk (missing opening/closing tags), that's expected - process it as-is";

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-tag attributes to interactive elements in this HTML. Make NO other changes whatsoever. " .
                    "Return ONLY the HTML with data-tag attributes added:\n\n" . $htmlContent
            ]
        ];

        // STEP 2: Send tokenized HTML to AI
        $taggedHTML = $this->sendRequest($messages, $systemPrompt);

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($taggedHTML);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // STEP 3: Restore original file references
        $taggedHTML = $this->restoreReferences($taggedHTML, $referenceMap);

        error_log("Restored " . count($referenceMap) . " file references after AI processing");

        // STEP 4: Restore protected blocks (e.g., scripts, links)
        $taggedHTML = $this->restoreProtectedBlocks($taggedHTML, $protectedBlocks);

        error_log("Restored " . count($protectedBlocks) . " protected blocks after AI processing");

        // STEP 5: Validate output size - check if response was significantly truncated
        $outputSize = strlen($taggedHTML);
        $sizeRatio = $outputSize / $contentSize;

        if ($sizeRatio < 0.8) {
            error_log("WARNING: Output size ({$outputSize} bytes) is significantly smaller than input size ({$contentSize} bytes). Response may have been truncated.");
            error_log("Size ratio: " . round($sizeRatio * 100, 2) . "%. Consider increasing max_tokens or max_content_size config.");
        }

        // Extract tags that were added
        preg_match_all('/data-tag="([^"]+)"/', $taggedHTML, $matches);
        $tags = array_unique($matches[1]);

        // Filter to only allowed tags
        $tags = array_values(array_intersect($tags, $allowedTags));

        return [
            'html' => $taggedHTML,
            'tags' => $tags
        ];
    }

    /**
     * Tag phishing email content with NIST Phish Scale cues
     */
    public function tagPhishingEmail($emailHTML, $nistGuideContent = null) {
        // Structured cue types with specific criteria
        $cueTypesJson = '{
  "cueTypes": [
    {
      "type": "Error",
      "cues": [
        {"name": "spelling-grammar", "criteria": "Does the message contain inaccurate spelling or grammar use, including mismatched plurality?"},
        {"name": "inconsistency", "criteria": "Are there inconsistencies contained in the email message?"}
      ]
    },
    {
      "type": "Technical indicator",
      "cues": [
        {"name": "attachment-type", "criteria": "Is there a potentially dangerous attachment?"},
        {"name": "display-name-email-mismatch", "criteria": "Does a display name hide the real sender or reply-to email address?"},
        {"name": "url-hyperlinking", "criteria": "Is there text that hides the true URL behind the text?"},
        {"name": "domain-spoofing", "criteria": "Is a domain name used in addresses or links plausibly similar to a legitimate entity\'s domain?"}
      ]
    },
    {
      "type": "Visual presentation indicator",
      "cues": [
        {"name": "no-minimal-branding", "criteria": "Are appropriately branded labeling, symbols, or insignias missing?"},
        {"name": "logo-imitation-outdated", "criteria": "Do any branding elements appear to be an imitation or out-of-date?"},
        {"name": "unprofessional-design", "criteria": "Does the design and formatting violate any conventional professional practices? Do the design elements appear to be unprofessionally generated?"},
        {"name": "security-indicators-icons", "criteria": "Are any markers, images, or logos that imply the security of the email present?"}
      ]
    },
    {
      "type": "Language and content",
      "cues": [
        {"name": "legal-language-disclaimers", "criteria": "Does the message contain any legal-type language such as copyright information, disclaimers, or tax information?"},
        {"name": "distracting-detail", "criteria": "Does the email contain details that are superfluous or unrelated to the email\'s main premise?"},
        {"name": "requests-sensitive-info", "criteria": "Does the message contain a request for any sensitive information, including personally identifying information or credentials?"},
        {"name": "sense-of-urgency", "criteria": "Does the message contain time pressure to get users to quickly comply with the request, including implied pressure?"},
        {"name": "threatening-language", "criteria": "Does the message contain a threat, including an implied threat, such as legal ramifications for inaction?"},
        {"name": "generic-greeting", "criteria": "Does the message lack a greeting or lack personalization in the message?"},
        {"name": "lack-signer-details", "criteria": "Does the message lack detail about the sender, such as contact information?"},
        {"name": "humanitarian-appeals", "criteria": "Does the message make an appeal to help others in need?"}
      ]
    },
    {
      "type": "Common tactic",
      "cues": [
        {"name": "too-good-to-be-true", "criteria": "Does the message offer anything that is too good to be true, such as winning a contest, lottery, free vacation and so on?"},
        {"name": "youre-special", "criteria": "Does the email offer anything just for you, such as a valentine e-card from a secret admirer?"},
        {"name": "limited-time-offer", "criteria": "Does the email offer anything that won\'t last long or for a limited length of time?"},
        {"name": "mimics-business-process", "criteria": "Does the message appear to be a work or business-related process, such as a new voicemail, package delivery, order confirmation, notice to reset credentials and so on?"},
        {"name": "poses-as-authority", "criteria": "Does the message appear to be from a friend, colleague, boss or other authority entity?"}
      ]
    }
  ]
}';

        $cueTypes = json_decode($cueTypesJson, true)['cueTypes'];

        // Build cue documentation for the prompt
        $cueDocumentation = "";
        foreach ($cueTypes as $cueType) {
            $cueDocumentation .= "\n{$cueType['type']}:\n";
            foreach ($cueType['cues'] as $cue) {
                $cueDocumentation .= "  - {$cue['name']}: {$cue['criteria']}\n";
            }
        }

        $systemPrompt = "You are an expert at analyzing phishing emails using the NIST Phishing Scale methodology. " .
            "Your task is to identify phishing indicators and add data-cue attributes to mark them.\n\n" .
            "PHISHING CUE TYPES AND CRITERIA:\n" .
            $cueDocumentation . "\n" .
            "NIST Phish Scale Difficulty Ratings:\n" .
            "- Least Difficult (1): Multiple obvious red flags, amateur mistakes, very easy to detect\n" .
            "- Moderately Difficult (2): Some red flags but requires closer inspection, decent attempt\n" .
            "- Very Difficult (3): Sophisticated, few obvious indicators, requires expert knowledge to detect\n\n" .
            "Rules:\n" .
            "1. Add data-cue attributes to elements containing phishing indicators\n" .
            "2. Use format: data-cue=\"cue-name\" using the exact cue names from the list above (e.g., data-cue=\"sense-of-urgency\")\n" .
            "3. Use ONLY the cue names provided in the list - these are the standardized NIST Phish Scale indicators\n" .
            "4. On the FIRST line, output: DIFFICULTY:X (where X is 1, 2, or 3)\n" .
            "5. Then output the complete modified HTML with data-cue attributes added\n" .
            "6. Do not modify the content or structure, only add data-cue attributes\n" .
            "7. Do not include any other explanations, comments, or markdown formatting\n" .
            "8. Only add cues where the criteria are clearly met in the email content";

        if ($nistGuideContent) {
            $systemPrompt .= "\n\nReference Guide:\n" . $nistGuideContent;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-cue attributes to phishing indicators in this email using the standardized cue names, and assess its difficulty level. " .
                    "First line must be DIFFICULTY:X (1, 2, or 3), then the modified HTML:\n\n" . $emailHTML
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Extract difficulty score from first line
        $difficulty = 2; // Default to moderate
        if (preg_match('/^DIFFICULTY:\s*(\d+)/i', $response, $diffMatch)) {
            $difficulty = intval($diffMatch[1]);
            // Remove the difficulty line from response
            $response = preg_replace('/^DIFFICULTY:\s*\d+\s*\n?/i', '', $response);
        }

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($response);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // Extract cues that were added
        preg_match_all('/data-cue="([^"]+)"/', $taggedHTML, $matches);
        $cues = array_unique($matches[1]);

        return [
            'html' => $taggedHTML,
            'cues' => $cues,
            'difficulty' => $difficulty
        ];
    }

    /**
     * Analyze SCORM content and suggest tags
     */
    public function analyzeSCORMContent($htmlContent) {
        $systemPrompt = "You are an expert at analyzing educational content. " .
            "Analyze the provided HTML content and identify the main topics, skills, or knowledge areas being taught or tested.";

        $messages = [
            [
                'role' => 'user',
                'content' => "Please analyze this SCORM content and list the main topics/skills covered. " .
                    "Return a JSON array of topic names (lowercase, hyphenated):\n\n" .
                    substr($htmlContent, 0, 10000) // Limit content size
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Try to parse JSON from response
        preg_match('/\[.*?\]/s', $response, $matches);
        if (!empty($matches)) {
            $tags = json_decode($matches[0], true);
            return is_array($tags) ? $tags : [];
        }

        return [];
    }

    /**
     * Generate interaction tracking script for injecting into content
     */
    public function generateTrackingScript($trackingLinkId, $basePath = '') {
        // Build API base URL
        $apiBase = $basePath . '/api';

        return <<<JAVASCRIPT
<script>
(function() {
    // API base path from config
    const API_BASE = '{$apiBase}';

    const TRACKING_LINK_ID = '{$trackingLinkId}';
    const interactions = [];
    let finalScore = null;

    // Track interactions with tagged elements
    function trackInteraction(element, interactionType, value = null) {
        const tag = element.getAttribute('data-tag') || element.getAttribute('data-cue');
        if (!tag) return;

        const interaction = {
            tag: tag,
            type: interactionType,
            value: value,
            timestamp: new Date().toISOString()
        };

        interactions.push(interaction);

        // Send to API
        fetch(API_BASE + '/track-interaction.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tracking_link_id: TRACKING_LINK_ID,
                tag_name: tag,
                interaction_type: interactionType,
                interaction_value: value
            })
        }).catch(err => console.error('Tracking error:', err));
    }

    // Listen for interactions on tagged elements
    document.addEventListener('DOMContentLoaded', function() {
        // Track clicks
        document.querySelectorAll('[data-tag], [data-cue]').forEach(el => {
            el.addEventListener('click', function(e) {
                trackInteraction(this, 'click');
            });

            // Track input changes
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                el.addEventListener('change', function(e) {
                    trackInteraction(this, 'input', this.value);
                });
            }
        });
    });

    // Hijack SCORM RecordTest function
    window.RecordTest = function(score) {
        finalScore = score;

        // Send score to API
        fetch(API_BASE + '/record-score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tracking_link_id: TRACKING_LINK_ID,
                score: score,
                interactions: interactions
            })
        }).catch(err => console.error('Score recording error:', err));

        return true;
    };

    // Track when page is viewed
    fetch(API_BASE + '/track-view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tracking_link_id: TRACKING_LINK_ID })
    }).catch(err => console.error('View tracking error:', err));
})();
</script>
JAVASCRIPT;
    }
}
