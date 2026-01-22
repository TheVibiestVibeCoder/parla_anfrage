<?php
// ==========================================
// DAILY EMAIL SENDER FOR MAILING LIST
// ==========================================
// This script should be run via cron at 20:00 daily
// Example cron: 0 20 * * * /usr/bin/php /path/to/send-daily-emails.php

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

    // Calculate cutoff date (24 hours ago)
    $cutoffDate = new DateTime('24 hours ago');

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

        // Check if entry is from last 24 hours
        if ($entryDate < $cutoffDate) {
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

function generateEmailHTML($entries) {
    $entryCount = count($entries);
    $date = date('d.m.Y');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NGO Business Tracker</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Inter', Helvetica, Arial, sans-serif; background-color: #000000; color: #ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #000000; width: 100%;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background-color: #000000;">
                    
                    <tr>
                        <td style="padding: 40px 20px 20px 20px; text-align: center; border-bottom: 2px solid #ffffff;">
                            <div style="font-family: 'Courier New', Courier, monospace; font-size: 10px; color: #666666; letter-spacing: 2px; margin-bottom: 10px; text-transform: uppercase;">
                                Tägliches Update &bull; <?php echo $date; ?>
                            </div>
                            <h1 style="margin: 0; font-family: 'Impact', 'Arial Narrow', sans-serif; font-size: 42px; line-height: 1; text-transform: uppercase; color: #ffffff; letter-spacing: 1px;">
                                NGO-Business<br>Tracker
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px 20px;">
                            <?php if ($entryCount > 0): ?>
                                <div style="margin-bottom: 40px; text-align: left;">
                                    <div style="border-left: 2px solid #ffffff; padding-left: 15px;">
                                        <p style="margin: 0; font-size: 18px; color: #ffffff; font-weight: bold;">
                                            <?php echo $entryCount; ?> neue Anfrage<?php echo $entryCount > 1 ? 'n' : ''; ?>
                                        </p>
                                        <p style="margin: 5px 0 0 0; font-family: 'Courier New', Courier, monospace; font-size: 12px; color: #888888;">
                                            DATENSATZ AKTUALISIERT
                                        </p>
                                    </div>
                                </div>

                                <?php foreach ($entries as $index => $entry): ?>
                                    <div style="margin-bottom: 0; padding-bottom: 25px; border-bottom: 1px solid #333333; padding-top: 25px;">
                                        <div style="font-family: 'Courier New', Courier, monospace; font-size: 11px; color: #666666; margin-bottom: 8px; letter-spacing: 1px;">
                                            <?php echo $entry['date']; ?> 
                                            <span style="color: #444;">|</span> 
                                            <?php echo !empty($entry['nparl']) ? $entry['nparl'] : '---'; ?>
                                            <span style="color: #444;">|</span> 
                                            <span style="color: <?php echo $entry['party_color']; ?>; font-weight: bold;">
                                                <?php echo htmlspecialchars($entry['party_name']); ?>
                                            </span>
                                        </div>

                                        <div style="margin-bottom: 15px;">
                                            <a href="<?php echo htmlspecialchars($entry['link']); ?>" style="text-decoration: none; color: #ffffff; font-size: 16px; line-height: 1.4; font-weight: normal; display: block;">
                                                <?php echo htmlspecialchars($entry['title']); ?>
                                            </a>
                                        </div>

                                        <?php if (!empty($entry['link'])): ?>
                                            <table cellpadding="0" cellspacing="0">
                                                <tr>
                                                    <td>
                                                        <a href="<?php echo htmlspecialchars($entry['link']); ?>" style="display: inline-block; font-family: 'Courier New', Courier, monospace; font-size: 11px; color: #ffffff; text-decoration: none; border: 1px solid #333333; padding: 5px 10px; text-transform: uppercase;">
                                                            Dokument öffnen &rarr;
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <div style="text-align: center; padding: 40px 0; border: 1px solid #222222; background-color: #111111;">
                                    <h2 style="margin: 0 0 15px 0; font-family: 'Impact', 'Arial Narrow', sans-serif; font-size: 28px; color: #333333; text-transform: uppercase;">
                                        Keine Aktivitäten
                                    </h2>
                                    <p style="margin: 0; font-family: 'Courier New', Courier, monospace; font-size: 12px; color: #666666;">
                                        <?php
                                        $funnyMessages = [
                                            "SYSTEM STATUS: SILENT",
                                            "PARLAMENT: PAUSED",
                                            "NO DATA DETECTED",
                                            "ANFRAGE-GENERATOR: OFFLINE"
                                        ];
                                        echo $funnyMessages[array_rand($funnyMessages)];
                                        ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 30px 20px; border-top: 2px solid #ffffff; background-color: #000000;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="left" style="font-family: 'Courier New', Courier, monospace; font-size: 10px; color: #555555; line-height: 1.6;">
                                        SYSTEM OPERATIONAL<br>
                                        <span style="color: #22c55e;">●</span> ONLINE
                                    </td>
                                    <td align="right" style="font-family: 'Courier New', Courier, monospace; font-size: 10px; color: #555555; line-height: 1.6;">
                                        SOURCE: PARLAMENT.GV.AT<br>
                                        <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.com'; ?>" style="color: #888888; text-decoration: none;">DASHBOARD ÖFFNEN</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center" style="padding-top: 30px; font-family: sans-serif; font-size: 10px; color: #333333;">
                                        &copy; <?php echo date('Y'); ?> NGO Business Tracker. <a href="https://<?php echo $_SERVER['HTTP_HOST'] ?? 'ngo-business.com'; ?>/impressum.php" style="color: #333333; text-decoration: underline;">Impressum</a>.
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
        return "⚠️ $entryCount neue Anfrage" . ($entryCount > 1 ? 'n' : '') . " | NGO Business Tracker";
    } else {
        return "Status: Keine neuen Anfragen | NGO Business Tracker";
    }
}

function sendEmailToSubscribers($subscribers, $subject, $htmlBody) {
    $successCount = 0;
    $failCount = 0;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: NGO Business Tracker <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'ngo-business.com') . '>',
        'X-Mailer: PHP/' . phpversion()
    ];

    $headersString = implode("\r\n", $headers);

    foreach ($subscribers as $subscriber) {
        $email = $subscriber['email'];

        try {
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

    // Fetch new entries from last 24 hours
    echo "Fetching new entries from Parliament API...\n";
    $newEntries = getNewEntries();
    $entryCount = count($newEntries);

    echo "Found $entryCount new entries in the last 24 hours.\n";

    // Generate email
    $subject = generateEmailSubject($entryCount);
    $htmlBody = generateEmailHTML($newEntries);

    echo "Sending emails to $subscriberCount subscribers...\n";

    // Send emails
    $result = sendEmailToSubscribers($subscribers, $subject, $htmlBody);

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