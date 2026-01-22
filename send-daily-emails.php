<?php
// ==========================================
// DAILY EMAIL SENDER FOR MAILING LIST
// ==========================================
// This script should be run via cron at 20:00 daily
// Example cron: 0 20 * * * /usr/bin/php /path/to/send-daily-emails.php

// SECURITY: Only allow execution via CLI or with secret token
if (php_sapi_name() !== 'cli') {
    // Allow web execution only with secret token
    // IMPORTANT: Change this token to something random and keep it secret!
    $secretToken = 'a770374e67f3b9b2ab510f4dcd815291';
    $providedToken = $_GET['token'] ?? '';

    if ($providedToken !== $secretToken) {
        http_response_code(403);
        die('Access denied. This script can only be executed via command line or with valid token.');
    }
}

require_once __DIR__ . '/MailingListDB.php';

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/email-sender-errors.log');

// Parliament API configuration
define('PARL_API_URL', 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true');

// NGO-related keywords for filtering
define('NGO_KEYWORDS', [
    'ngo',
    'ngos',
    "ngo-business",
    "NGO-Business",
    "NGO business",
    "ngo business",
    'nicht-regierungsorganisation',
    'nicht regierungsorganisation',
    'nichtregierungsorganisation',
    'non-governmental',
    'nonprofit',
    'non-profit',
    'ehrenamtlich'
]);

// Party color mapping
define('PARTY_COLORS', [
    'S' => '#EF4444',   // SPÖ - Red
    'V' => '#22D3EE',   // ÖVP - Cyan
    'F' => '#3B82F6',   // FPÖ - Blue
    'G' => '#22C55E',   // GRÜNE - Green
    'N' => '#E879F9',   // NEOS - Magenta
    'OTHER' => '#9CA3AF' // Other - Gray
]);

define('PARTY_NAMES', [
    'S' => 'SPÖ',
    'V' => 'ÖVP',
    'F' => 'FPÖ',
    'G' => 'GRÜNE',
    'N' => 'NEOS',
    'OTHER' => 'Andere'
]);

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function getPartyCode($rowPartyJson) {
    $rowParties = json_decode($rowPartyJson ?? '[]', true);
    if (!is_array($rowParties)) return 'OTHER';
    $pStr = mb_strtoupper(implode(' ', $rowParties));

    if (strpos($pStr, 'SPÖ') !== false || strpos($pStr, 'SOZIALDEMOKRATEN') !== false) return 'S';
    if (strpos($pStr, 'ÖVP') !== false || strpos($pStr, 'VOLKSPARTEI') !== false) return 'V';
    if (strpos($pStr, 'FPÖ') !== false || strpos($pStr, 'FREIHEITLICHE') !== false) return 'F';
    if (strpos($pStr, 'GRÜNE') !== false) return 'G';
    if (strpos($pStr, 'NEOS') !== false) return 'N';

    return 'OTHER';
}

function matchesNGOKeywords($text) {
    $text = mb_strtolower($text);
    foreach (NGO_KEYWORDS as $keyword) {
        if (strpos($text, mb_strtolower($keyword)) !== false) {
            return true;
        }
    }
    return false;
}

function fetchAllRows($gpCodes) {
    $payload = [
        "GP_CODE" => $gpCodes,
        "VHG" => ["J_JPR_M"],
        "DOKTYP" => ["J"]
    ];

    $ch = curl_init(PARL_API_URL);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("API request failed with HTTP code: $httpCode");
        return null;
    }

    return json_decode($response, true);
}

function getNewEntries() {
    // Fetch data from Parliament API
    $gpCodes = ["XXVIII", "XXVII", "XXVI", "XXV"];
    $apiResponse = fetchAllRows($gpCodes);

    if (!$apiResponse || !isset($apiResponse['rows'])) {
        error_log('Failed to fetch data from Parliament API');
        return [];
    }

    $allRows = $apiResponse['rows'];
    $newEntries = [];

    // Get today's date at 00:00 (midnight)
    $today = new DateTime('today');
    $todayStr = $today->format('Y-m-d');

    foreach ($allRows as $row) {
        $title = $row['TITEL'] ?? '';

        // Filter by NGO keywords
        if (!matchesNGOKeywords($title)) {
            continue;
        }

        // Parse date
        $dateStr = $row['DATUM'] ?? '';
        if (empty($dateStr)) continue;

        try {
            $entryDate = new DateTime($dateStr);
        } catch (Exception $e) {
            continue;
        }

        // Check if entry is from TODAY (not last 24 hours, but calendar day)
        if ($entryDate->format('Y-m-d') !== $todayStr) {
            continue;
        }

        // Extract relevant information
        $partyCode = getPartyCode($row['PARTIE'] ?? '[]');
        $nparl = $row['NPARL'] ?? '';
        $link = !empty($nparl) ? "https://www.parlament.gv.at/gegenstand/XXVIII/$nparl" : '';

        $newEntries[] = [
            'date' => $entryDate->format('d.m.Y'),
            'title' => $title,
            'party' => $partyCode,
            'party_name' => PARTY_NAMES[$partyCode],
            'party_color' => PARTY_COLORS[$partyCode],
            'link' => $link,
            'nparl' => $nparl
        ];
    }

    // Sort by date (newest first)
    usort($newEntries, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $newEntries;
}

function generateEmailHTML($entries, $recipientEmail) {
    $entryCount = count($entries);
    $date = date('d.m.Y');

    // Generate unsubscribe link with token
    $unsubToken = hash('sha256', $recipientEmail . 'ngo-unsubscribe-salt-2026');
    $unsubscribeUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ngo-business.at') . '/unsubscribe.php?email=' . urlencode($recipientEmail) . '&token=' . $unsubToken;

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NGO Business Tracker - Täglicher Newsletter</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #000000; color: #ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #000000;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #111111; border: 1px solid #333333; border-radius: 8px;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px; text-align: center; border-bottom: 2px solid #3B82F6;">
                            <h1 style="margin: 0; font-size: 32px; font-weight: bold; letter-spacing: 2px; color: #ffffff;">
                                "NGO BUSINESS" TRACKER
                            </h1>
                            <p style="margin: 10px 0 0 0; font-size: 14px; color: #9CA3AF;">
                                Täglicher Newsletter vom <?php echo $date; ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            <?php if ($entryCount > 0): ?>
                                <h2 style="margin: 0 0 20px 0; font-size: 24px; color: #3B82F6;">
                                    📋 <?php echo $entryCount; ?> neue Anfrage<?php echo $entryCount > 1 ? 'n' : ''; ?> heute
                                </h2>

                                <p style="margin: 0 0 30px 0; font-size: 14px; line-height: 1.6; color: #E5E5E5;">
                                    Hier sind die heutigen parlamentarischen Anfragen zum Thema NGO-Business:
                                </p>

                                <?php foreach ($entries as $entry): ?>
                                    <div style="margin-bottom: 20px; padding: 20px; background-color: #1a1a1a; border-left: 4px solid <?php echo $entry['party_color']; ?>; border-radius: 4px;">
                                        <div style="margin-bottom: 10px;">
                                            <span style="display: inline-block; padding: 4px 12px; background-color: <?php echo $entry['party_color']; ?>; color: #ffffff; font-size: 12px; font-weight: bold; border-radius: 12px;">
                                                <?php echo htmlspecialchars($entry['party_name']); ?>
                                            </span>
                                            <span style="margin-left: 10px; font-size: 12px; color: #9CA3AF;">
                                                📅 <?php echo htmlspecialchars($entry['date']); ?>
                                            </span>
                                        </div>

                                        <p style="margin: 10px 0; font-size: 14px; line-height: 1.5; color: #E5E5E5;">
                                            <?php echo htmlspecialchars($entry['title']); ?>
                                        </p>

                                        <?php if (!empty($entry['link'])): ?>
                                            <a href="<?php echo htmlspecialchars($entry['link']); ?>" style="display: inline-block; margin-top: 10px; padding: 8px 16px; background-color: #3B82F6; color: #ffffff; text-decoration: none; font-size: 12px; border-radius: 4px;">
                                                🔗 Anfrage ansehen
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <div style="text-align: center; padding: 40px 20px;">
                                    <h2 style="margin: 0 0 20px 0; font-size: 28px; color: #3B82F6;">
                                        😴 Heut war die FPÖ wohl faul...
                                    </h2>

                                    <p style="margin: 0 0 10px 0; font-size: 16px; line-height: 1.6; color: #E5E5E5;">
                                        <?php
                                        $funnyMessages = [
                                            "Aber keine Sorge, morgen kommt bestimmt was!",
                                            "Vielleicht haben sie heute ausnahmsweise Urlaub? 🏖️",
                                            "Die Anfrage-Maschinerie macht wohl Pause... für einen Tag.",
                                            "Stille im Parlament – ein seltenes Phänomen! 🦄",
                                            "Heute mal keine NGO-Panik. Genießen Sie die Ruhe! ☕",
                                            "Scheint, als hätte heute jemand den Anfrage-Generator ausgesteckt.",
                                            "Ein Tag ohne NGO-Anfrage ist wie... eigentlich ganz entspannt! 😌"
                                        ];
                                        echo $funnyMessages[array_rand($funnyMessages)];
                                        ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; background-color: #0a0a0a; border-top: 1px solid #333333; text-align: center;">
                            <p style="margin: 0 0 10px 0; font-size: 12px; color: #9CA3AF;">
                                Mehr Informationen und Statistiken finden Sie auf:
                            </p>
                            <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.com'; ?>" style="display: inline-block; margin-bottom: 20px; padding: 10px 20px; background-color: #3B82F6; color: #ffffff; text-decoration: none; font-size: 14px; border-radius: 4px;">
                                🌐 NGO Business Tracker besuchen
                            </a>

                            <p style="margin: 20px 0 10px 0; font-size: 11px; color: #666666;">
                                Sie erhalten diese E-Mail, weil Sie sich für den täglichen Newsletter angemeldet haben.
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #666666;">
                                <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>" style="color: #EF4444; text-decoration: underline;">
                                    Newsletter abbestellen
                                </a>
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #666666;">
                                © <?php echo date('Y'); ?> NGO Business Tracker |
                                <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.at'; ?>/impressum.php" style="color: #666666;">Impressum</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
    <?php
    return ob_get_clean();
}

function generateEmailSubject($entryCount) {
    if ($entryCount > 0) {
        return "📋 $entryCount neue NGO-Anfrage" . ($entryCount > 1 ? 'n' : '') . " | NGO Business Tracker";
    } else {
        return "😴 Heute war die FPÖ wohl faul | NGO Business Tracker";
    }
}

function sendEmailToSubscribers($subscribers, $subject, $entries) {
    $successCount = 0;
    $failCount = 0;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: NGO Business Tracker <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'ngo-business.at') . '>',
        'X-Mailer: PHP/' . phpversion()
    ];

    $headersString = implode("\r\n", $headers);

    foreach ($subscribers as $subscriber) {
        $email = $subscriber['email'];

        try {
            // Generate personalized email with unsubscribe link for this specific recipient
            $htmlBody = generateEmailHTML($entries, $email);

            if (mail($email, $subject, $htmlBody, $headersString)) {
                $successCount++;
            } else {
                $failCount++;
                error_log("Failed to send email to: $email");
            }
        } catch (Exception $e) {
            $failCount++;
            error_log("Exception sending email to $email: " . $e->getMessage());
        }
    }

    return [
        'success' => $successCount,
        'failed' => $failCount,
        'total' => count($subscribers)
    ];
}

// ==========================================
// MAIN EXECUTION
// ==========================================

try {
    echo "=== NGO Business Tracker - Daily Email Sender ===\n";
    echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";

    // Initialize database
    $db = new MailingListDB();

    // Get active subscribers
    $subscribers = $db->getActiveSubscribers();
    $subscriberCount = count($subscribers);

    echo "Active subscribers: $subscriberCount\n";

    if ($subscriberCount === 0) {
        echo "No active subscribers. Exiting.\n";
        exit(0);
    }

    // Fetch new entries from today
    echo "Fetching new entries from Parliament API...\n";
    $newEntries = getNewEntries();
    $entryCount = count($newEntries);

    echo "Found $entryCount new entries from today.\n";

    // Generate email subject
    $subject = generateEmailSubject($entryCount);

    echo "Sending emails to $subscriberCount subscribers...\n";

    // Send emails (each with personalized unsubscribe link)
    $result = sendEmailToSubscribers($subscribers, $subject, $newEntries);

    echo "Emails sent: {$result['success']} successful, {$result['failed']} failed\n";

    // Log email sending
    $db->logEmailSending($subscriberCount, $entryCount > 0, $entryCount, $result['success'] > 0);

    // Update last email sent for all subscribers
    foreach ($subscribers as $subscriber) {
        $db->updateLastEmailSent($subscriber['email']);
    }

    echo "\n=== Completed successfully at: " . date('Y-m-d H:i:s') . " ===\n";

} catch (Exception $e) {
    error_log("Daily email sender error: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
