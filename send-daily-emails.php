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
        echo "ERROR: API returned no data or no rows\n";
        return [];
    }

    $allRows = $apiResponse['rows'];
    $newEntries = [];

    // Get today's date string in format d.m.Y (e.g., "22.01.2026")
    $todayStr = date('d.m.Y');
    echo "Looking for entries from today: $todayStr\n";

    $totalChecked = 0;
    $todayCount = 0;
    $ngoCount = 0;
    $todayNgoCount = 0;

    foreach ($allRows as $row) {
        $totalChecked++;

        // Use numeric indices like index.php does
        $dateStr = $row[4] ?? '';  // Date field
        $title = $row[6] ?? '';    // Title field
        $topics = $row[22] ?? '';  // Topics field

        // Skip if no date
        if (empty($dateStr)) continue;

        // Check if entry is from TODAY - simple string comparison
        $isToday = ($dateStr === $todayStr);
        if ($isToday) {
            $todayCount++;
        }

        // Check for NGO keywords in title AND topics (like index.php does!)
        $searchableText = $title . ' ' . $topics;
        $hasNGO = matchesNGOKeywords($searchableText);

        if ($hasNGO) {
            $ngoCount++;
        }

        if ($isToday && $hasNGO) {
            $todayNgoCount++;

            // Extract relevant information
            $partyCode = getPartyCode($row[21] ?? '[]');  // Party field (numeric index 21)
            $rowLink = $row[14] ?? '';  // Link field (numeric index 14) - relative path
            $rowNumber = $row[7] ?? '';  // Inquiry number

            // Build full URL like index.php does
            $fullLink = !empty($rowLink) ? 'https://www.parlament.gv.at' . $rowLink : '';

            $newEntries[] = [
                'date' => $dateStr,
                'title' => $title,
                'party' => $partyCode,
                'party_name' => PARTY_NAMES[$partyCode],
                'party_color' => PARTY_COLORS[$partyCode],
                'link' => $fullLink,
                'number' => $rowNumber
            ];
        }
    }

    echo "Checked $totalChecked total rows\n";
    echo "Entries from today: $todayCount\n";
    echo "NGO entries (all): $ngoCount\n";
    echo "NGO entries from today: $todayNgoCount\n";

    // Sort by date (newest first) - but since all are from today, sort by title
    usort($newEntries, function($a, $b) {
        return strcmp($a['title'], $b['title']);
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
    <title>NGO Business Tracker - Daily Report</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap');
    </style>
</head>
<body style="margin: 0; padding: 0; font-family: 'Inter', Helvetica, Arial, sans-serif; background-color: #000000; color: #ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #000000;">
        <tr>
            <td align="center" style="padding: 20px 10px;">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background-color: #000000; border: 2px solid #ffffff;">
                    
                    <tr>
                        <td style="padding: 40px 30px 20px 30px; text-align: left; border-bottom: 2px solid #333333;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td valign="middle" style="padding-bottom: 15px;">
                                        <div style="width: 12px; height: 12px; background-color: #ffffff;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td valign="middle">
                                        <h1 style="margin: 0; font-family: 'Bebas Neue', Impact, 'Arial Narrow', sans-serif; font-size: 42px; line-height: 1.0; font-weight: normal; color: #ffffff; letter-spacing: 1px; text-transform: uppercase;">
                                            NGO Business<br>Tracker
                                        </h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 15px;">
                                        <span style="font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 11px; letter-spacing: 2px; color: #666666; text-transform: uppercase;">
                                            PARLAMENTARISCHE ANFRAGEN // <?php echo $date; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 30px;">
                            
                            <?php if ($entryCount > 0): ?>
                                <div style="margin-bottom: 30px;">
                                    <span style="display: inline-block; padding: 4px 8px; background-color: #3B82F6; color: #ffffff; font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 12px; font-weight: bold; text-transform: uppercase;">
                                        NEUE DATEN
                                    </span>
                                    <h2 style="margin: 15px 0 0 0; font-family: 'Inter', Helvetica, Arial, sans-serif; font-size: 16px; font-weight: normal; color: #cccccc; line-height: 1.5;">
                                        Das Parlament hat heute <strong style="color: #ffffff;"><?php echo $entryCount; ?> Anfrage<?php echo $entryCount > 1 ? 'n' : ''; ?></strong> zum Thema NGOs veröffentlicht.
                                    </h2>
                                </div>

                                <table width="100%" cellpadding="0" cellspacing="0">
                                <?php foreach ($entries as $index => $entry): ?>
                                    <tr>
                                        <td style="padding-bottom: 20px;">
                                            <div style="border: 1px solid #333333; background-color: #0a0a0a;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td width="6" style="background-color: <?php echo $entry['party_color']; ?>;"></td>
                                                        
                                                        <td style="padding: 12px 15px; border-bottom: 1px solid #222222;">
                                                            <table width="100%" cellpadding="0" cellspacing="0">
                                                                <tr>
                                                                    <td align="left">
                                                                        <span style="font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-weight: bold; font-size: 12px; color: <?php echo $entry['party_color']; ?>;">
                                                                            <?php echo htmlspecialchars($entry['party_name']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td align="right">
                                                                        <span style="font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 10px; color: #666666;">
                                                                            <?php echo htmlspecialchars($entry['date']); ?> | <?php echo htmlspecialchars($entry['number']); ?>
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                </table>
                                                
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="padding: 15px;">
                                                            <a href="<?php echo htmlspecialchars($entry['link']); ?>" style="text-decoration: none; display: block;">
                                                                <span style="display: block; font-family: 'Inter', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; color: #ffffff; line-height: 1.4; margin-bottom: 10px;">
                                                                    <?php echo htmlspecialchars($entry['title']); ?>
                                                                </span>
                                                                <span style="display: inline-block; font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 11px; color: #999999; text-transform: uppercase; border-bottom: 1px solid #333333;">
                                                                    ZUR ANFRAGE &rarr;
                                                                </span>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </table>

                            <?php else: ?>
                                <div style="text-align: center; padding: 40px 20px; border: 1px dashed #333333;">
                                    <h2 style="margin: 0 0 15px 0; font-family: 'Bebas Neue', Impact, 'Arial Narrow', sans-serif; font-size: 32px; letter-spacing: 1px; color: #333333;">
                                        KEINE AKTIVITÄT
                                    </h2>
                                    
                                    <p style="margin: 0; font-family: 'Inter', Helvetica, Arial, sans-serif; font-size: 14px; color: #666666; line-height: 1.6;">
                                        <?php
                                        $funnyMessages = [
                                            "Aber keine Sorge, morgen kommt bestimmt was.",
                                            "Vielleicht ist ja heut wo ein Bierzelt?",
                                            "Da hat wohl wer die Deadline vergessen ...",
                                            "Heut ist noch nicht aller Tage, die Anfragen kommen wieder, keine Frage.",
                                            "Heute scheinen NGOs alles richtig gemacht zu haben.",
                                            "Scheint, als wär ChatGPT wohl down heut."
                                        ];
                                        echo $funnyMessages[array_rand($funnyMessages)];
                                        ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top: 30px; text-align: center;">
                                <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.at'; ?>" style="display: inline-block; padding: 12px 24px; border: 1px solid #ffffff; background-color: transparent; color: #ffffff; text-decoration: none; font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">
                                    Zum Dashboard
                                </a>
                            </div>

                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 20px 30px; background-color: #0a0a0a; border-top: 1px solid #222222; text-align: left;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding-bottom: 10px;">
                                        <p style="margin: 0; font-family: 'Inter', Helvetica, Arial, sans-serif; font-size: 11px; color: #444444; line-height: 1.4;">
                                            Dieser Newsletter wurde automatisch generiert. Sie erhalten ihn, weil Sie sich auf dem NGO Business Tracker angemeldet haben.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-family: 'JetBrains Mono', 'Courier New', Courier, monospace; font-size: 10px; color: #333333; text-transform: uppercase;">
                                            &copy; <?php echo date('Y'); ?> NGO Business Tracker
                                            <span style="color: #333333; padding: 0 5px;">/</span>
                                            <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.at'; ?>/impressum.php" style="color: #555555; text-decoration: none;">Impressum</a>
                                            <span style="color: #333333; padding: 0 5px;">/</span>
                                            <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>" style="color: #EF4444; text-decoration: none;">Abmelden</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
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
        return "Heute war man wohl zu faul | NGO Business Tracker";
    }
}

function sendEmailToSubscribers($subscribers, $subject, $entries) {
    $successCount = 0;
    $failCount = 0;

    // Use clean sender address
    $fromEmail = 'noreply@ngo-business.at';
    $fromName = 'NGO Business Tracker';
    $replyTo = 'kontakt@ngo-business.at';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Sender: NGO Business Newsletter <newsletter@ngo-business.at>',  // Override "Absender" field
        'Reply-To: ' . $replyTo,
        'X-Mailer: NGO-Business-Tracker/1.0',  // Custom mailer string
        'X-Priority: 3',
        'Return-Path: ' . $fromEmail,
        'Organization: NGO Business Tracker'  // Add organization header
    ];

    $headersString = implode("\r\n", $headers);

    // Additional parameters for mail() to set envelope sender
    $additionalParams = '-fnewsletter@ngo-business.at';

    foreach ($subscribers as $subscriber) {
        $email = $subscriber['email'];

        try {
            // Generate personalized email with unsubscribe link for this specific recipient
            $htmlBody = generateEmailHTML($entries, $email);

            // Use 5th parameter to set envelope sender (Return-Path)
            if (mail($email, $subject, $htmlBody, $headersString, $additionalParams)) {
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