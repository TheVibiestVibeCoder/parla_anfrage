<?php
// ==========================================
// NGO ANFRAGEN TRACKER
// Single Purpose: Track NGO-related parliamentary inquiries
// ==========================================

// COMPREHENSIVE ERROR LOGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Custom error handler to log to console and file
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $error = date('[Y-m-d H:i:s] ') . "Error [$errno]: $errstr in $errfile on line $errline\n";
    error_log($error);
    echo "<script>console.error(" . json_encode($error) . ");</script>";
    return false;
});

// Exception handler
set_exception_handler(function($exception) {
    $error = date('[Y-m-d H:i:s] ') . "Exception: " . $exception->getMessage() .
             " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n";
    error_log($error);
    echo "<script>console.error(" . json_encode($error) . ");</script>";
    echo "<div style='background: #ff0000; color: #fff; padding: 20px; margin: 20px;'>";
    echo "<h2>FEHLER!</h2>";
    echo "<pre>" . htmlspecialchars($error) . "</pre>";
    echo "</div>";
});

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

// German stopwords - Füllwörter die wir NICHT in den Kampfbegriffen haben wollen
define('STOPWORDS', [
    // Artikel
    'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines', 'einem', 'einen',
    // Präpositionen
    'für', 'von', 'mit', 'bei', 'aus', 'nach', 'vor', 'über', 'unter', 'durch', 'ohne', 'gegen',
    // Konjunktionen
    'und', 'oder', 'aber', 'sondern', 'denn', 'sowie', 'bzw', 'bzw.',
    // Pronomen
    'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'diese', 'dieser', 'dieses', 'jene', 'jener',
    // Hilfsverben & häufige Verben
    'ist', 'sind', 'war', 'waren', 'wird', 'werden', 'wurde', 'wurden', 'sein', 'haben', 'hat', 'hatte',
    // Parlamentarische Standardwörter
    'beantwortet', 'beantwortung', 'anfrage', 'anfragen', 'frist', 'offen', 'erledigt',
    // Datum/Zeit
    'januar', 'februar', 'märz', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'dezember',
    // Zahlen & Währung (als Wörter)
    'euro', 'cent', 'prozent',
    // Administrative Begriffe
    'öffentliche', 'öffentlichen', 'öffentlicher', 'gelder', 'geld',
    // Sonstiges
    'mehr', 'weniger', 'sehr', 'auch', 'nicht', 'nur', 'noch', 'schon', 'alle', 'jede', 'jeden', 'jedes',
    'welche', 'welcher', 'welches', 'deren', 'dessen', 'wie', 'was', 'wer', 'wann', 'wo', 'warum',
    'bzw', 'etc', 'usw', 'dass', 'daß', 'damit', 'dazu', 'davon'
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

function extractAnswerInfo($rowTitle) {
    if (preg_match('/beantwortet durch (\d+)\/AB/i', $rowTitle, $matches)) {
        return [
            'answered' => true,
            'answer_number' => $matches[1]
        ];
    }
    return ['answered' => false, 'answer_number' => null];
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
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

// ==========================================
// TIME RANGE CALCULATION
// ==========================================

$timeRange = $_GET['range'] ?? '12months';
$now = new DateTime();
$cutoffDate = clone $now;

switch ($timeRange) {
    case '1week':
        $cutoffDate->modify('-1 week');
        $rangeLabel = 'Letzte Woche';
        $gpCodes = ['XXVIII'];
        break;
    case '1month':
        $cutoffDate->modify('-1 month');
        $rangeLabel = 'Letzter Monat';
        $gpCodes = ['XXVIII'];
        break;
    case '3months':
        $cutoffDate->modify('-3 months');
        $rangeLabel = 'Letzte 3 Monate';
        $gpCodes = ['XXVIII'];
        break;
    case '6months':
        $cutoffDate->modify('-6 months');
        $rangeLabel = 'Letzte 6 Monate';
        $gpCodes = ['XXVIII', 'XXVII'];
        break;
    case '12months':
        $cutoffDate->modify('-12 months');
        $rangeLabel = 'Letzte 12 Monate';
        $gpCodes = ['XXVIII', 'XXVII'];
        break;
    case '1year':
        $cutoffDate->modify('-1 year');
        $rangeLabel = 'Letztes Jahr';
        $gpCodes = ['XXVIII', 'XXVII'];
        break;
    case '3years':
        $cutoffDate->modify('-3 years');
        $rangeLabel = 'Letzte 3 Jahre';
        $gpCodes = ['XXVIII', 'XXVII', 'XXVI'];
        break;
    case '5years':
        $cutoffDate->modify('-5 years');
        $rangeLabel = 'Letzte 5 Jahre';
        $gpCodes = ['XXVIII', 'XXVII', 'XXVI', 'XXV'];
        break;
    default:
        $cutoffDate->modify('-12 months');
        $rangeLabel = 'Letzte 12 Monate';
        $gpCodes = ['XXVIII', 'XXVII'];
}

// ==========================================
// FETCH AND FILTER DATA
// ==========================================

$apiResponse = fetchAllRows($gpCodes);
$allNGOResults = [];
$wordFrequency = [];
$monthlyData = [];
// Initialize with all parties to ensure they appear in the chart
$partyStats = ['S' => 0, 'V' => 0, 'F' => 0, 'G' => 0, 'N' => 0, 'OTHER' => 0];
$answeredCount = 0;
$pendingCount = 0;

if (isset($apiResponse['rows'])) {
    foreach ($apiResponse['rows'] as $row) {
        $rowDateStr = $row[4] ?? '';
        $rowTitle = $row[6] ?? '';
        $rowTopics = $row[22] ?? '[]';
        $rowPartyCode = getPartyCode($row[21] ?? '[]');
        $rowLink = $row[14] ?? '';
        $rowNumber = $row[7] ?? '';

        // Check if matches NGO keywords
        $searchableText = $rowTitle . ' ' . $rowTopics;
        if (!matchesNGOKeywords($searchableText)) {
            continue;
        }

        // Parse date
        $rowDate = DateTime::createFromFormat('d.m.Y', $rowDateStr);
        if (!$rowDate || $rowDate < $cutoffDate) {
            continue;
        }

        // Extract answer info
        $answerInfo = extractAnswerInfo($rowTitle);

        // Count statistics
        $partyStats[$rowPartyCode]++;
        if ($answerInfo['answered']) {
            $answeredCount++;
        } else {
            $pendingCount++;
        }

        // Timeline data for graph (days for week/month, months for longer periods)
        $useDays = in_array($timeRange, ['1week', '1month']);
        $timeKey = $useDays ? $rowDate->format('Y-m-d') : $rowDate->format('Y-m');
        if (!isset($monthlyData[$timeKey])) {
            $monthlyData[$timeKey] = [
                'count' => 0,
                'label' => $useDays ? $rowDate->format('d.m.') : $rowDate->format('M Y'),
                'timestamp' => $rowDate->getTimestamp()
            ];
        }
        $monthlyData[$timeKey]['count']++;

        // Word frequency - ONLY meaningful keywords (no stopwords!)
        $words = preg_split('/\s+/', mb_strtolower($rowTitle));
        foreach ($words as $word) {
            $word = preg_replace('/[^\p{L}\p{N}\-]/u', '', $word); // Keep hyphens for compound words
            $word = trim($word, '-'); // Remove leading/trailing hyphens

            // Filter: min length 5, not a stopword, not a number
            if (mb_strlen($word) >= 5 &&
                !in_array($word, STOPWORDS) &&
                !is_numeric($word)) {

                if (!isset($wordFrequency[$word])) {
                    $wordFrequency[$word] = 0;
                }
                $wordFrequency[$word]++;
            }
        }

        // Store result
        $allNGOResults[] = [
            'date' => $rowDateStr,
            'date_obj' => $rowDate,
            'title' => $rowTitle,
            'party' => $rowPartyCode,
            'answered' => $answerInfo['answered'],
            'answer_number' => $answerInfo['answer_number'],
            'link' => 'https://www.parlament.gv.at' . $rowLink,
            'number' => $rowNumber
        ];
    }
}

// Sort results by date (newest first)
usort($allNGOResults, function($a, $b) {
    return $b['date_obj'] <=> $a['date_obj'];
});

// Sort monthly data
ksort($monthlyData);

// Sort word frequency
arsort($wordFrequency);
$topWords = array_slice($wordFrequency, 0, 50, true);

// ==========================================
// DATA FOR NEW VISUALIZATIONS
// ==========================================

// 1. FLOOD WALL: Cumulative inquiry count per party over time
$floodWallData = [];
$partyDailyCounts = ['S' => [], 'V' => [], 'F' => [], 'G' => [], 'N' => [], 'OTHER' => []];

// Group by date first
foreach ($allNGOResults as $result) {
    $dateKey = $result['date_obj']->format('Y-m-d');
    if (!isset($partyDailyCounts[$result['party']][$dateKey])) {
        $partyDailyCounts[$result['party']][$dateKey] = 0;
    }
    $partyDailyCounts[$result['party']][$dateKey]++;
}

// Get all unique dates sorted
$allDates = [];
foreach ($allNGOResults as $result) {
    $dateKey = $result['date_obj']->format('Y-m-d');
    if (!isset($allDates[$dateKey])) {
        $allDates[$dateKey] = $result['date_obj'];
    }
}
ksort($allDates);

// Calculate cumulative sums for each party
foreach (['S', 'V', 'F', 'G', 'N', 'OTHER'] as $party) {
    $cumulative = 0;
    $floodWallData[$party] = [];
    foreach ($allDates as $dateKey => $dateObj) {
        $count = $partyDailyCounts[$party][$dateKey] ?? 0;
        $cumulative += $count;
        $floodWallData[$party][] = [
            'date' => $dateObj->format('d.m.Y'),
            'cumulative' => $cumulative
        ];
    }
}

// 2. KAMPFBEGRIFFE: Keyword ownership by party (NO stopwords!)
$keywordPartyUsage = [];
foreach ($allNGOResults as $result) {
    $words = preg_split('/\s+/', mb_strtolower($result['title']));
    foreach ($words as $word) {
        $word = preg_replace('/[^\p{L}\p{N}\-]/u', '', $word);
        $word = trim($word, '-');

        // Same filter as above
        if (mb_strlen($word) >= 5 &&
            !in_array($word, STOPWORDS) &&
            !is_numeric($word)) {

            if (!isset($keywordPartyUsage[$word])) {
                $keywordPartyUsage[$word] = ['S' => 0, 'V' => 0, 'F' => 0, 'G' => 0, 'N' => 0, 'OTHER' => 0];
            }
            $keywordPartyUsage[$word][$result['party']]++;
        }
    }
}

// Build Kampfbegriffe list with party dominance
$kampfbegriffeData = [];
foreach ($wordFrequency as $word => $count) {
    if (isset($keywordPartyUsage[$word])) {
        $partyUsage = $keywordPartyUsage[$word];
        $maxParty = array_keys($partyUsage, max($partyUsage))[0];
        $kampfbegriffeData[] = [
            'word' => $word,
            'count' => $count,
            'party' => $maxParty,
            'partyBreakdown' => $partyUsage
        ];
    }
}

// Top Kampfbegriffe with full party breakdown - show top 20
$topKampfbegriffe = array_slice($kampfbegriffeData, 0, 20, true);

// Debug logging
error_log("Total words in frequency: " . count($wordFrequency));
error_log("Total kampfbegriffe: " . count($kampfbegriffeData));
error_log("Top 5 words: " . json_encode(array_slice(array_keys($wordFrequency), 0, 5)));

// 3. SPAM CALENDAR: Daily intensity heatmap
$spamCalendarData = [];
foreach (['S', 'V', 'F', 'G', 'N', 'OTHER'] as $party) {
    $spamCalendarData[$party] = [];
    foreach ($allDates as $dateKey => $dateObj) {
        $count = $partyDailyCounts[$party][$dateKey] ?? 0;
        if ($count > 0) { // Only include days with activity for efficiency
            $spamCalendarData[$party][] = [
                'date' => $dateKey,
                'displayDate' => $dateObj->format('d.m.Y'),
                'count' => $count
            ];
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 25;
$totalResults = count($allNGOResults);
$totalPages = ceil($totalResults / $perPage);
$offset = ($page - 1) * $perPage;
$displayResults = array_slice($allNGOResults, $offset, $perPage);

$totalCount = count($allNGOResults);

// Find earliest inquiry date (for hero section)
$earliestDate = null;
$earliestDateFormatted = '';
if (!empty($allNGOResults)) {
    // Since results are sorted newest first, last element is the earliest
    $earliestInquiry = end($allNGOResults);
    if (isset($earliestInquiry['date_obj'])) {
        $earliestDate = $earliestInquiry['date_obj'];
        // Format as "MM YYYY" (e.g., "01 2024")
        $earliestDateFormatted = $earliestDate->format('m Y');
    }
}

// Party name mapping (German)
$partyMap = [
    'S' => 'SPÖ',
    'V' => 'ÖVP',
    'F' => 'FPÖ',
    'G' => 'GRÜNE',
    'N' => 'NEOS',
    'OTHER' => 'ANDERE'
];

?>

<!DOCTYPE html>
<html lang="de" prefix="og: https://ogp.me/ns#">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
    // SEO-Optimized Dynamic Title
    $seoTitle = "\"NGO Business\" Tracker Österreich | " . $rangeLabel . " | Parlamentarische Anfragen Live";
    $seoDescription = "Analyse des Begriffs 'NGO Business' im Parlament. Tracking der Strategie, Ressourcenbindung und Skandalisierung durch parlamentarische Anfragen in Österreich.";
    $seoKeywords = "ngo business, ngo business österreich, ngo anfragen, parlamentarische anfragen ngo, ngo business tracker, framing ngo, ngo business strategie, parlamentsanfragen";
    $currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $canonicalUrl = "https://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER["REQUEST_URI"], '?');
    ?>

    <title><?php echo htmlspecialchars($seoTitle); ?></title>

    <meta name="title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta name="description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($seoKeywords); ?>">
    <meta name="author" content="&quot;NGO Business&quot; Tracker - Anfragen Dashboard">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="language" content="German">
    <meta name="revisit-after" content="1 days">
    <meta name="distribution" content="global">
    <meta name="rating" content="general">
    <meta name="geo.region" content="AT">
    <meta name="geo.placename" content="Österreich">
    <meta name="geo.position" content="47.516231;14.550072">
    <meta name="ICBM" content="47.516231, 14.550072">

    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="&quot;NGO Business&quot; Tracker - Anfragen Dashboard">
    <meta property="og:url" content="<?php echo htmlspecialchars($currentUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta property="og:locale" content="de_AT">
    <meta property="og:updated_time" content="<?php echo date('c'); ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo htmlspecialchars($currentUrl); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seoDescription); ?>">

    <meta name="theme-color" content="#050505">
    <meta name="msapplication-TileColor" content="#050505">
    <meta name="application-name" content="&quot;NGO Business&quot; Tracker">
    <meta name="apple-mobile-web-app-title" content="&quot;NGO Business&quot; Tracker">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">

    <link rel="alternate" hreflang="de-at" href="<?php echo htmlspecialchars($currentUrl); ?>">
    <link rel="alternate" hreflang="de" href="<?php echo htmlspecialchars($currentUrl); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($canonicalUrl); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://www.parlament.gv.at">

    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@300;400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Organization",
                "name": "\"NGO Business\" Tracker - Anfragen Dashboard",
                "url": "<?php echo htmlspecialchars($canonicalUrl); ?>",
                "logo": "<?php echo htmlspecialchars($canonicalUrl); ?>",
                "description": "Echtzeit-Tracking und Analyse von NGO-bezogenen parlamentarischen Anfragen im österreichischen Parlament",
                "areaServed": {
                    "@type": "Country",
                    "name": "Österreich"
                },
                "knowsAbout": ["NGO Business", "Parlamentarische Anfragen", "Transparenz", "Politisches Monitoring", "NGO Tracking"],
                "keywords": "ngo business, ngo business österreich, parlamentarische anfragen, ngo transparenz"
            },
            {
                "@type": "WebSite",
                "name": "\"NGO Business\" Tracker",
                "url": "<?php echo htmlspecialchars($canonicalUrl); ?>",
                "description": "<?php echo htmlspecialchars($seoDescription); ?>",
                "inLanguage": "de-AT",
                "isAccessibleForFree": true,
                "keywords": "<?php echo htmlspecialchars($seoKeywords); ?>"
            },
            {
                "@type": "WebPage",
                "name": "<?php echo htmlspecialchars($seoTitle); ?>",
                "url": "<?php echo htmlspecialchars($currentUrl); ?>",
                "description": "<?php echo htmlspecialchars($seoDescription); ?>",
                "inLanguage": "de-AT",
                "isPartOf": {
                    "@type": "WebSite",
                    "url": "<?php echo htmlspecialchars($canonicalUrl); ?>"
                },
                "about": {
                    "@type": "Thing",
                    "name": "NGO Business Tracking",
                    "description": "Monitoring und Analyse von NGO-bezogenen parlamentarischen Anfragen in Österreich"
                },
                "datePublished": "<?php echo date('c', strtotime('-1 year')); ?>",
                "dateModified": "<?php echo date('c'); ?>",
                "keywords": "<?php echo htmlspecialchars($seoKeywords); ?>"
            },
            {
                "@type": "Dataset",
                "name": "NGO Business Parlamentarische Anfragen <?php echo $rangeLabel; ?>",
                "description": "Echtzeit-Datensatz von <?php echo $totalCount; ?> NGO-bezogenen parlamentarischen Anfragen aus dem österreichischen Parlament (<?php echo $rangeLabel; ?>)",
                "url": "<?php echo htmlspecialchars($currentUrl); ?>",
                "keywords": "<?php echo htmlspecialchars($seoKeywords); ?>",
                "creator": {
                    "@type": "Organization",
                    "name": "\"NGO Business\" Tracker"
                },
                "datePublished": "<?php echo date('c', strtotime('-1 year')); ?>",
                "dateModified": "<?php echo date('c'); ?>",
                "temporalCoverage": "<?php echo $cutoffDate->format('Y-m-d'); ?>/<?php echo $now->format('Y-m-d'); ?>",
                "distribution": {
                    "@type": "DataDownload",
                    "contentUrl": "<?php echo htmlspecialchars($currentUrl); ?>",
                    "encodingFormat": "text/html"
                },
                "includedInDataCatalog": {
                    "@type": "DataCatalog",
                    "name": "Parlament Österreich Daten"
                },
                "spatialCoverage": {
                    "@type": "Place",
                    "name": "Österreich"
                }
            },
            {
                "@type": "BreadcrumbList",
                "itemListElement": [
                    {
                        "@type": "ListItem",
                        "position": 1,
                        "name": "Home",
                        "item": "<?php echo htmlspecialchars($canonicalUrl); ?>"
                    },
                    {
                        "@type": "ListItem",
                        "position": 2,
                        "name": "NGO Business Anfragen <?php echo $rangeLabel; ?>",
                        "item": "<?php echo htmlspecialchars($currentUrl); ?>"
                    }
                ]
            }
        ]
    }
    </script>
    
    <style>
        :root {
            --bg-color: #050505;
            --text-color: #f0f0f0;
            --grid-line: rgba(255, 255, 255, 0.1);
            --accent: #ffffff;
            --font-head: 'Bebas Neue', display;
            --font-body: 'Manrope', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;

            /* Party Colors Dark Mode */
            --color-s: #ef4444;
            --color-v: #22d3ee;
            --color-f: #3b82f6;
            --color-g: #22c55e;
            --color-n: #e879f9;
            --color-other: #9ca3af;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: var(--font-body);
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3 { font-family: var(--font-head); letter-spacing: 1px; text-transform: uppercase; }
        
        .container-custom {
            width: 95%; max-width: 1600px; margin: 0 auto; padding: 2rem 1rem;
        }

        /* GRID AESTHETIC */
        .mono-box {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--grid-line);
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .mono-box:hover {
            border-color: rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.04);
        }

        .mono-box::before {
            content: '';
            position: absolute; top: -1px; left: -1px;
            width: 10px; height: 10px;
            border-top: 2px solid var(--accent);
            border-left: 2px solid var(--accent);
            opacity: 0.5;
        }

        /* TYPOGRAPHY OVERRIDES */
        .stat-value { font-size: 3.5rem; line-height: 1; font-family: var(--font-head); color: var(--accent); }
        .stat-label { font-size: 0.85rem; text-transform: uppercase; color: #888; letter-spacing: 2px; margin-bottom: 0.5rem; }

        /* FORM ELEMENTS - Updated for more space */
        select {
            background: #000;
            color: #fff;
            border: 1px solid #333;
            /* Increased Padding */
            padding: 0.75rem 3rem 0.75rem 1.25rem;
            font-family: var(--font-mono);
            font-size: 0.9rem;
            text-transform: uppercase;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23FFFFFF%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 1em top 50%;
            background-size: .65em auto;
            transition: all 0.2s ease;
        }
        select:hover { border-color: #666; }
        select:focus { outline: 1px solid #fff; border-color: #fff; }

        /* LIST ITEMS */
        .result-item {
            border-bottom: 1px solid var(--grid-line);
            padding: 1.25rem 0;
            transition: all 0.2s;
        }
        .result-item:hover {
            background: rgba(255,255,255,0.03);
            padding-left: 1rem;
            border-left: 2px solid var(--accent);
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #333; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }

        /* Screen Reader Only - Accessibility for SEO */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        /* PAGINATION */
        .pag-btn {
            border: 1px solid var(--grid-line);
            padding: 0.5rem 1rem;
            color: #888;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            transition: 0.3s;
        }
        .pag-btn:hover, .pag-btn.active {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        /* PARTY COLORS */
        .border-S { border-color: var(--color-s) !important; color: var(--color-s); }
        .border-V { border-color: var(--color-v) !important; color: var(--color-v); }
        .border-F { border-color: var(--color-f) !important; color: var(--color-f); }
        .border-G { border-color: var(--color-g) !important; color: var(--color-g); }
        .border-N { border-color: var(--color-n) !important; color: var(--color-n); }
        
        .bg-S { background-color: var(--color-s); }
        .bg-V { background-color: var(--color-v); }
        .bg-F { background-color: var(--color-f); }
        .bg-G { background-color: var(--color-g); }
        .bg-N { background-color: var(--color-n); }
        .bg-OTHER { background-color: var(--color-other); }
    </style>
</head>
<body>

    <header class="w-full border-b border-[rgba(255,255,255,0.1)] bg-black">
        <div class="container-custom">
            <div class="flex flex-col md:flex-row justify-between items-center py-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 border border-white flex items-center justify-center">
                        <span class="text-2xl font-bold font-mono text-white">N</span>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold" style="font-family: var(--font-head); letter-spacing: 2px;">
                            "NGO BUSINESS" TRACKER
                        </h1>
                        <p class="text-xs font-mono text-gray-500 uppercase tracking-wider">Anfragen Dashboard</p>
                    </div>
                </div>
                <div class="mt-4 md:mt-0">
                    <div class="flex items-center gap-3">
                        <div class="text-xs font-mono text-gray-600 text-right">
                            <div class="text-gray-400">QUELLE:</div>
                            <div class="text-gray-500">PARLAMENT.GV.AT</div>
                        </div>
                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                        <div class="text-xs font-mono text-green-500">LIVE</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Opener Section - Full Screen -->
    <section class="min-h-screen flex flex-col justify-between items-center text-center px-6 py-12 -mt-6"
             style="background: #000;">

        <!-- Main Content - Centered -->
        <div class="flex-1 flex flex-col justify-center items-center max-w-5xl mx-auto">
            <article itemscope itemtype="https://schema.org/Article">
                <header>
                    <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold mb-8 md:mb-12 text-white leading-tight"
                        style="font-family: var(--font-head); letter-spacing: 2px;"
                        itemprop="headline">
                        DAS "NGO-BUSINESS"-NARRATIV DER FPÖ
                    </h1>
                </header>

                <div class="text-lg md:text-xl lg:text-2xl text-gray-300 space-y-6 leading-relaxed max-w-4xl mx-auto"
                     itemprop="articleBody">
                    <p>
                        Die FPÖ flutet das Parlament zum Thema "NGO-Business". Seit <?php echo $earliestDateFormatted; ?>
                        sind <?php echo number_format($totalCount); ?> Anfragen mit dem Begriff "NGO-Business" eingegangen.
                    </p>

                    <p class="pt-4">
                        Warum? Wichtige NGO-Arbeit wird ganz bewusst in den Kontext von Steuergeld-Verschwendung gerückt,
                        um die Arbeit von Non-Profit-Organisationen zu verunglimpfen.
                    </p>

                    <p class="pt-6 font-semibold text-white">
                        Wir decken auf, was es mit den Anfragen auf sich hat.
                    </p>
                </div>
            </article>
        </div>

        <!-- Arrow Down - Bottom of Screen -->
        <a href="#tracker" class="pb-8 cursor-pointer group">
            <div class="text-xs md:text-sm font-mono text-gray-400 uppercase tracking-widest mb-4 group-hover:text-white transition-colors">
                Zum Anfragen-Tracker
            </div>
            <div class="flex justify-center">
                <svg class="w-12 h-12 text-white animate-bounce group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                </svg>
            </div>
        </a>
    </section>

    <div class="container-custom">

        <header id="tracker" class="flex flex-col md:flex-row justify-between items-end border-b border-[rgba(255,255,255,0.1)] pb-6 mb-10">
            <div>
                <div class="text-xs font-mono text-gray-500 mb-2">SYSTEM: PARLAMENT_WATCH // TRACKING: NGO_INTERACTIONS</div>
                <h2 class="text-5xl md:text-7xl text-white leading-none">Anfragen<br><span style="color: #666;">Tracker</span></h2>
            </div>
            
            <form method="GET" class="mt-6 md:mt-0">
                <div class="flex items-center gap-4">
                    <span class="text-xs uppercase tracking-widest text-gray-500 font-bold">Zeitraum</span>
                    <select name="range" onchange="this.form.submit()" class="px-4 py-2 bg-black border border-gray-700 text-white font-mono text-xs rounded hover:border-gray-500 focus:outline-none focus:border-white transition-colors cursor-pointer">
                        <option value="1week" <?php echo $timeRange === '1week' ? 'selected' : ''; ?>>LETZTE WOCHE</option>
                        <option value="1month" <?php echo $timeRange === '1month' ? 'selected' : ''; ?>>LETZTER MONAT</option>
                        <option value="3months" <?php echo $timeRange === '3months' ? 'selected' : ''; ?>>3 MONATE</option>
                        <option value="6months" <?php echo $timeRange === '6months' ? 'selected' : ''; ?>>6 MONATE</option>
                        <option value="12months" <?php echo $timeRange === '12months' ? 'selected' : ''; ?>>12 MONATE</option>
                        <option value="1year" <?php echo $timeRange === '1year' ? 'selected' : ''; ?>>LETZTES JAHR</option>
                        <option value="3years" <?php echo $timeRange === '3years' ? 'selected' : ''; ?>>3 JAHRE</option>
                        <option value="5years" <?php echo $timeRange === '5years' ? 'selected' : ''; ?>>5 JAHRE</option>
                    </select>
                </div>
            </form>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-3 gap-4 mb-8">
            <div class="mono-box">
                <div class="stat-label">Gesamtanzahl</div>
                <div class="stat-value"><?php echo number_format($totalCount); ?></div>
                <div class="text-xs font-mono text-gray-600 mt-2">ANFRAGEN IM ZEITRAUM</div>
            </div>

            <div class="mono-box lg:col-span-2 flex flex-col justify-between">
                <div class="stat-label">Verteilung nach Parteien</div>
                
                <div class="flex h-full items-end gap-3 mt-4 pb-2" style="min-height: 120px;">
                    <?php 
                    $maxVal = max($partyStats) ?: 1; 
                    // Explicitly define order or iterate current stats (which contains all keys)
                    // The CSS flex-1 ensures they spread evenly.
                    foreach ($partyStats as $code => $count): 
                        // Even if count is 0, we show it to display all parties
                        $height = ($count > 0) ? ($count / $maxVal) * 100 : 0;
                        // Min height just for visual marker
                        $visualHeight = $height == 0 ? 1 : $height; 
                    ?>
                        <div class="relative group flex-1 h-full flex flex-col justify-end">
                            <div class="w-full bg-<?php echo $code; ?> opacity-70 group-hover:opacity-100 transition-all relative" 
                                 style="height: <?php echo $visualHeight; ?>%; min-height: 2px;">
                                 <?php if($count > 0): ?>
                                    <div class="absolute -top-6 w-full text-center text-xs font-mono text-white opacity-0 group-hover:opacity-100 transition-opacity">
                                        <?php echo $count; ?>
                                    </div>
                                 <?php endif; ?>
                            </div>
                            <div class="mt-3 text-xs font-mono text-gray-400 border-t border-gray-800 pt-2 flex flex-col items-center gap-1">
                                <span class="font-bold text-gray-300"><?php echo isset($partyMap[$code]) ? $partyMap[$code] : $code; ?></span>
                                <span class="text-[10px] text-gray-600"><?php echo $count; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <section class="mono-box mb-8" aria-labelledby="timeline-heading">
            <div class="flex justify-between items-center mb-6">
                <h2 id="timeline-heading" class="text-2xl text-white">Zeitlicher Verlauf NGO Business Anfragen</h2>
                <div class="h-px bg-white w-10"></div>
            </div>
            <div style="height: 300px; width: 100%;">
                <canvas id="timelineChart"
                        role="img"
                        aria-label="Liniendiagramm: Zeitlicher Verlauf der NGO Business Anfragen im österreichischen Parlament über <?php echo $rangeLabel; ?>"
                        aria-describedby="timeline-desc"></canvas>
                <p id="timeline-desc" class="sr-only">
                    Dieses Diagramm zeigt die Anzahl der NGO-bezogenen parlamentarischen Anfragen im Zeitverlauf für den Zeitraum <?php echo $rangeLabel; ?>.
                    Insgesamt wurden <?php echo $totalCount; ?> NGO Business Anfragen erfasst.
                </p>
            </div>
        </section>

        <section class="mono-box mb-8" aria-labelledby="keywords-heading">
            <div class="flex justify-between items-center mb-6">
                <h2 id="keywords-heading" class="text-2xl text-white">Top Kampfbegriffe im NGO Business</h2>
                <div class="h-px bg-white w-10"></div>
            </div>
            <div class="text-sm text-gray-400 mb-6 font-mono">
                Die häufigsten INHALTLICHEN Begriffe aus NGO Business Anfragetiteln (ohne Füllwörter). Zeigt welche Partei den Begriff am meisten nutzt.
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($topKampfbegriffe as $item): ?>
                    <?php
                    // Find the dominant party
                    arsort($item['partyBreakdown']);
                    $dominantParty = array_key_first($item['partyBreakdown']);
                    $dominantCount = $item['partyBreakdown'][$dominantParty];
                    ?>
                    <div class="border border-gray-800 bg-black bg-opacity-40 p-4 hover:bg-opacity-60 hover:border-gray-600 transition-all">
                        <div class="flex justify-between items-start mb-3">
                            <div class="text-lg font-bold font-mono text-white uppercase">
                                <?php echo htmlspecialchars($item['word']); ?>
                            </div>
                            <div class="text-xs font-mono text-gray-500">
                                <?php echo $item['count']; ?>×
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-gray-800">
                            <div class="text-xs font-mono text-gray-400">
                                Meist genutzt von:
                            </div>
                            <div class="text-sm font-bold font-mono text-white">
                                <?php echo $partyMap[$dominantParty]; ?> <span class="text-gray-500">(<?php echo $dominantCount; ?>×)</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mono-box mb-8" aria-labelledby="floodwall-heading">
            <div class="flex justify-between items-center mb-6">
                <h2 id="floodwall-heading" class="text-2xl text-white">The "Flood Wall" – Kumulative NGO Business Belastungskurve</h2>
                <div class="h-px bg-white w-10"></div>
            </div>
            <div class="text-sm text-gray-400 mb-4 font-mono">
                Zeigt die kumulative Gesamtlast der NGO Business Anfragen: Wenn eine Partei das Parlament flutet, wird ihre Linie steil nach oben gehen.
            </div>
            <div style="height: 400px; width: 100%;">
                <canvas id="floodWallChart"
                        role="img"
                        aria-label="Kumulative Belastungskurve: NGO Business Anfragen pro Partei im Zeitverlauf"
                        aria-describedby="floodwall-desc"></canvas>
                <p id="floodwall-desc" class="sr-only">
                    Dieses Diagramm zeigt die kumulative Anzahl der NGO Business Anfragen pro Partei über den Zeitraum <?php echo $rangeLabel; ?>.
                    Die Steigung der Linien zeigt die Intensität der parlamentarischen NGO Business Aktivitäten jeder Partei.
                </p>
            </div>
        </section>

        <section class="mono-box mb-8" aria-labelledby="calendar-heading">
            <div class="flex justify-between items-center mb-6">
                <h2 id="calendar-heading" class="text-2xl text-white">The "Spam Calendar" – Heatmap NGO Business Intensität</h2>
                <div class="h-px bg-white w-10"></div>
            </div>
            <div class="text-sm text-gray-400 mb-4 font-mono">
                NGO Business Anfragen kommen in Wellen: Hellere Farben = mehr Anfragen an diesem Tag
            </div>
            <div style="height: 400px; width: 100%; overflow-x: auto;">
                <canvas id="spamCalendarChart"
                        role="img"
                        aria-label="Heatmap: Tägliche Intensität der NGO Business Anfragen pro Partei"
                        aria-describedby="calendar-desc"></canvas>
                <p id="calendar-desc" class="sr-only">
                    Diese Heatmap visualisiert die tägliche Intensität der NGO Business Anfragen jeder Partei.
                    Hellere Farben repräsentieren Tage mit höherer Aktivität in parlamentarischen NGO-bezogenen Anfragen.
                </p>
            </div>
        </section>

        <section class="mono-box" aria-labelledby="results-heading">
            <div class="flex justify-between items-center border-b border-gray-800 pb-4 mb-4">
                <h2 id="results-heading" class="text-2xl text-white">Gefundene NGO Business Anfragen</h2>
                <div class="text-xs font-mono text-gray-500">
                    SEITE <?php echo $page; ?> / <?php echo $totalPages; ?>
                </div>
            </div>

            <?php if (empty($displayResults)): ?>
                <div class="py-12 text-center">
                    <h3 class="text-gray-500">KEINE DATEN IN DIESEM BEREICH GEFUNDEN</h3>
                </div>
            <?php else: ?>
                <div class="flex flex-col">
                    <div class="hidden md:grid grid-cols-12 gap-4 text-xs font-mono text-gray-600 pb-2 uppercase tracking-wider">
                        <div class="col-span-2">Datum / ID</div>
                        <div class="col-span-1">Partei</div>
                        <div class="col-span-7">Betreff</div>
                        <div class="col-span-2 text-right">Status</div>
                    </div>

                    <?php foreach ($displayResults as $result): ?>
                        <div class="result-item grid grid-cols-1 md:grid-cols-12 gap-2 md:gap-4 items-center group">
                            
                            <div class="md:col-span-2 font-mono text-xs text-gray-400">
                                <div class="text-white"><?php echo $result['date']; ?></div>
                                <div class="text-gray-600"><?php echo $result['number']; ?></div>
                            </div>

                            <div class="md:col-span-1">
                                <span class="border-<?php echo $result['party']; ?> border px-2 py-1 text-xs font-bold font-mono">
                                    <?php echo $partyMap[$result['party']]; ?>
                                </span>
                            </div>

                            <div class="md:col-span-7">
                                <a href="<?php echo htmlspecialchars($result['link']); ?>" target="_blank" class="text-lg text-gray-300 group-hover:text-white transition-colors leading-tight block">
                                    <?php echo htmlspecialchars($result['title']); ?>
                                </a>
                            </div>

                            <div class="md:col-span-2 text-left md:text-right">
                                <?php if ($result['answered']): ?>
                                    <?php
                                    // Extract GP code from inquiry link to construct answer link
                                    preg_match('/\/gegenstand\/([^\/]+)\//', $result['link'], $gpMatch);
                                    $gpCode = $gpMatch[1] ?? 'XXVIII';
                                    $answerLink = "https://www.parlament.gv.at/gegenstand/{$gpCode}/AB/{$result['answer_number']}";
                                    ?>
                                    <a href="<?php echo htmlspecialchars($answerLink); ?>" target="_blank" class="text-xs font-mono text-green-500 hover:text-green-400 transition-colors">
                                        [ BEANTWORTET ]<br>
                                        <span class="opacity-50"><?php echo $result['answer_number']; ?>/AB</span>
                                    </a>
                                <?php else: ?>
                                    <span class="text-xs font-mono text-red-500 animate-pulse">
                                        [ OFFEN ]
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <div class="flex flex-wrap justify-center gap-2 mt-8 pt-4 border-t border-gray-800">
                    <?php if ($page > 1): ?>
                        <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page - 1; ?>" class="pag-btn">&lt; ZURÜCK</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $i; ?>" class="pag-btn <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page + 1; ?>" class="pag-btn">WEITER &gt;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>


        <section class="mono-box mt-16" itemscope itemtype="https://schema.org/FAQPage" aria-labelledby="faq-heading">
            <h2 id="faq-heading" class="text-3xl text-white mb-8" style="font-family: var(--font-head); letter-spacing: 1px;">
                Häufig gestellte Fragen zu NGO Business Anfragen
            </h2>

            <div class="space-y-6">
                <div class="border-b border-gray-800 pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Was sind NGO Business Anfragen?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            <strong>NGO Business Anfragen</strong> sind parlamentarische Anfragen im österreichischen Nationalrat, die sich mit Nicht-Regierungsorganisationen (NGOs), deren Finanzierung, Aktivitäten und Transparenz befassen. Diese Anfragen werden von Abgeordneten gestellt und vom zuständigen Ministerium beantwortet. Unser NGO Business Tracker ermöglicht Ihnen das Echtzeit-Monitoring aller solcher Anfragen.
                        </p>
                    </div>
                </div>

                <div class="border-b border-gray-800 pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Wie funktioniert der NGO Business Tracker?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            Unser NGO Business Tracking System greift in Echtzeit auf die offizielle API des österreichischen Parlaments (parlament.gv.at) zu und filtert automatisch alle Anfragen, die NGO-relevante Begriffe enthalten. Die Daten werden visualisiert und nach Parteien, Zeiträumen und Themen kategorisiert. Sie erhalten so einen vollständigen Überblick über alle NGO Business Aktivitäten im Parlament.
                        </p>
                    </div>
                </div>

                <div class="border-b border-gray-800 pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Welche Parteien stellen NGO Business Anfragen?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            Alle im österreichischen Parlament vertretenen Parteien können parlamentarische Anfragen zu NGO Business stellen. Unser Tracker zeigt Anfragen von SPÖ (Sozialdemokratische Partei), ÖVP (Österreichische Volkspartei), FPÖ (Freiheitliche Partei), GRÜNE (Die Grünen) und NEOS (NEOS – Das Neue Österreich). Die Visualisierungen zeigen, welche Partei am aktivsten im Bereich NGO Business ist.
                        </p>
                    </div>
                </div>

                <div class="border-b border-gray-800 pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Warum ist NGO Business Tracking wichtig?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            NGO Business Tracking schafft <strong>Transparenz</strong> über die parlamentarische Kontrolle von Nicht-Regierungsorganisationen in Österreich. Bürger, Journalisten und NGOs selbst können nachvollziehen, welche Fragen zu NGO-Finanzierung, NGO-Aktivitäten und NGO-Transparenz gestellt werden. Dies fördert die demokratische Kontrolle und öffentliche Debatte über die Rolle von NGOs in der Gesellschaft.
                        </p>
                    </div>
                </div>

                <div class="border-b border-gray-800 pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Wie aktuell sind die NGO Business Daten?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            Die Daten werden in <strong>Echtzeit</strong> von der offiziellen Parlament-API abgerufen. Sobald eine neue NGO-bezogene Anfrage im österreichischen Parlament eingebracht wird, erscheint sie auch in unserem NGO Business Tracker. Die Aktualität ist einer der Hauptvorteile unseres Systems gegenüber traditionellen Recherchemethoden.
                        </p>
                    </div>
                </div>

                <div class="pb-6" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-3" itemprop="name">Was zeigen die Visualisierungen im NGO Business Tracker?</h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            Unser NGO Business Tracker bietet drei Hauptvisualisierungen: 1) <strong>Zeitlicher Verlauf</strong> - zeigt die Anzahl der NGO Anfragen über Zeit, 2) <strong>Flood Wall</strong> - kumulative Belastungskurve pro Partei, 3) <strong>Spam Calendar</strong> - Heatmap der täglichen Intensität. Zusätzlich analysieren wir die häufigsten Kampfbegriffe in NGO Business Anfragen und zeigen, welche Partei welche Begriffe verwendet.
                        </p>
                    </div>
                </div>
            </div>
        </section>

    </div>

    <footer class="w-full border-t border-[rgba(255,255,255,0.1)] bg-black mt-16">
        <div class="container-custom">
            <div class="py-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
                    <div>
                        <h3 class="text-sm font-bold font-mono text-white mb-3 uppercase tracking-wider">Über das NGO Business System</h3>
                        <p class="text-xs font-mono text-gray-500 leading-relaxed">
                            Der führende <strong>"NGO Business" Tracker</strong> für Österreich. Echtzeit-Tracking von NGO-bezogenen parlamentarischen Anfragen aus dem österreichischen Parlament.
                            Professionelle Datenanalyse und Visualisierung für maximale <strong>NGO Transparenz</strong> und demokratische Kontrolle.
                        </p>
                        <div class="mt-4 text-xs font-mono text-gray-600">
                            <strong class="text-gray-400">Keywords:</strong> NGO Business Österreich, Parlamentarische Anfragen NGO, NGO Transparenz, NGO Monitoring, NGO Watch
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold font-mono text-white mb-3 uppercase tracking-wider">Datenquelle</h3>
                        <div class="text-xs font-mono text-gray-500 space-y-2">
                            <div class="flex items-center gap-2">
                                <div class="w-1 h-1 bg-green-500"></div>
                                <span>Parlament.gv.at API</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-1 h-1 bg-green-500"></div>
                                <span>Live-Datenabfrage</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-1 h-1 bg-green-500"></div>
                                <span>Sichere HTTPS-Verbindung</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold font-mono text-white mb-3 uppercase tracking-wider">Technologie</h3>
                        <div class="text-xs font-mono text-gray-500 space-y-1">
                            <div>PHP <?php echo phpversion(); ?></div>
                            <div>Chart.js 4.4.0</div>
                            <div>Tailwind CSS</div>
                            <div class="pt-2 text-gray-600">
                                <?php
                                $timestamp = date('d.m.Y H:i:s');
                                echo "Zuletzt aktualisiert: {$timestamp}";
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 border-t border-[rgba(255,255,255,0.05)] flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="text-xs font-mono text-gray-700">
                        © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER - Anfragen Dashboard
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-xs font-mono text-gray-700">
                            <strong>SEO:</strong> #1 für NGO Business Tracking
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                            <span class="text-xs font-mono text-green-600">SYSTEM AKTIV</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Error logging to console
        console.log('=== NGO TRACKER DEBUG START ===');
        console.log('Page loaded successfully');

        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.error('ERROR: Chart.js not loaded!');
        } else {
            console.log('Chart.js loaded successfully');
        }

        // CRITICAL: Wait for DOM to be fully loaded before initializing charts
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded - initializing charts...');

            // Check if all canvas elements exist
            const timelineCanvas = document.getElementById('timelineChart');
            const floodWallCanvas = document.getElementById('floodWallChart');
            const spamCalendarCanvas = document.getElementById('spamCalendarChart');

            console.log('Canvas elements found:');
            console.log('- timelineChart:', timelineCanvas ? 'YES' : 'NO');
            console.log('- floodWallChart:', floodWallCanvas ? 'YES' : 'NO');
            console.log('- spamCalendarChart:', spamCalendarCanvas ? 'YES' : 'NO');

            // --- CHART CONFIG ---
            Chart.defaults.color = '#666';
            Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
            Chart.defaults.font.family = "'Manrope', sans-serif";

            // Timeline Chart
            try {
            console.log('Initializing Timeline Chart...');
            const monthLabels = <?php echo json_encode(array_values(array_map(fn($m) => $m['label'], $monthlyData))); ?>;
            const monthCounts = <?php echo json_encode(array_values(array_map(fn($m) => $m['count'], $monthlyData))); ?>;

            console.log('Month labels:', monthLabels);
            console.log('Month counts:', monthCounts);

            const ctx = document.getElementById('timelineChart');
            if (!ctx) {
                throw new Error('Timeline chart canvas not found!');
            }

            // Create Gradient
            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(255, 255, 255, 0.2)');
            gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: monthLabels,
                        datasets: [{
                            label: 'ANFRAGEN',
                            data: monthCounts,
                            borderColor: '#ffffff',
                            backgroundColor: gradient,
                            borderWidth: 1,
                            fill: true,
                            tension: 0,
                            pointRadius: 3,
                            pointBackgroundColor: '#000',
                            pointBorderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#000',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#333',
                                borderWidth: 1,
                                cornerRadius: 0,
                                displayColors: false,
                                titleFont: { family: 'Bebas Neue', size: 16 }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { stepSize: 1, font: { family: 'JetBrains Mono', size: 10 } }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { font: { family: 'JetBrains Mono', size: 10 } }
                            }
                        }
                    }
                });
                console.log('Timeline Chart initialized successfully');
            } catch (error) {
                console.error('ERROR initializing Timeline Chart:', error);
                alert('FEHLER beim Laden des Timeline Charts: ' + error.message);
            }

        // ==========================================
        // VISUALIZATIONS
        // ==========================================

        // Party color mapping
        const partyColors = {
            'S': '#ef4444',
            'V': '#22d3ee',
            'F': '#3b82f6',
            'G': '#22c55e',
            'N': '#e879f9',
            'OTHER': '#9ca3af'
        };

        const partyNames = {
            'S': 'SPÖ',
            'V': 'ÖVP',
            'F': 'FPÖ',
            'G': 'GRÜNE',
            'N': 'NEOS',
            'OTHER': 'ANDERE'
        };

        // 1. FLOOD WALL CHART (Cumulative Line Chart with Step Interpolation)
        try {
            console.log('Initializing Flood Wall Chart...');
            const floodWallData = <?php echo json_encode($floodWallData); ?>;
            const dateLabels = <?php echo json_encode(array_values(array_map(fn($d) => $d->format('d.m.Y'), $allDates))); ?>;

            console.log('Flood wall data:', floodWallData);
            console.log('Date labels count:', dateLabels.length);

            const floodWallCtx = document.getElementById('floodWallChart');
            if (!floodWallCtx) {
                throw new Error('Flood Wall chart canvas not found!');
            }

                new Chart(floodWallCtx, {
                    type: 'line',
                    data: {
                        labels: dateLabels,
                        datasets: Object.keys(floodWallData).map(party => ({
                            label: partyNames[party],
                            data: floodWallData[party].map(d => d.cumulative),
                            borderColor: partyColors[party],
                            backgroundColor: partyColors[party] + '20',
                            borderWidth: 2,
                            fill: false,
                            stepped: 'before',
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            tension: 0
                        }))
                    },
                    options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: '#fff',
                            font: { family: 'JetBrains Mono', size: 11 },
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: '#000',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#333',
                        borderWidth: 1,
                        cornerRadius: 0,
                        titleFont: { family: 'Bebas Neue', size: 14 },
                        bodyFont: { family: 'JetBrains Mono', size: 12 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: {
                            stepSize: 10,
                            font: { family: 'JetBrains Mono', size: 10 },
                            color: '#666'
                        },
                        title: {
                            display: true,
                            text: 'KUMULATIVE ANFRAGEN',
                            color: '#999',
                            font: { family: 'Bebas Neue', size: 12 }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { family: 'JetBrains Mono', size: 9 },
                            color: '#666',
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                    }
                }
                });
                console.log('Flood Wall Chart initialized successfully');
            } catch (error) {
                console.error('ERROR initializing Flood Wall Chart:', error);
                alert('FEHLER beim Laden des Flood Wall Charts: ' + error.message);
            }

        // 2. SPAM CALENDAR (Matrix Heatmap)
        try {
            console.log('Initializing Spam Calendar Chart...');
            const spamCalendarData = <?php echo json_encode($spamCalendarData); ?>;
            const allDatesForCalendar = <?php echo json_encode(array_keys($allDates)); ?>;

            console.log('Spam calendar data:', spamCalendarData);
            console.log('Calendar dates count:', allDatesForCalendar.length);

            // Build matrix data
            const matrixData = [];
            const partyOrder = ['S', 'V', 'F', 'G', 'N', 'OTHER'];

            partyOrder.forEach((party, partyIndex) => {
                const partyData = spamCalendarData[party] || [];
                const dateMap = {};
                partyData.forEach(item => {
                    dateMap[item.date] = item.count;
                });

                allDatesForCalendar.forEach((date, dateIndex) => {
                    const count = dateMap[date] || 0;
                    if (count > 0) { // Only plot active days
                        matrixData.push({
                            x: dateIndex,
                            y: partyIndex,
                            v: count,
                            party: party,
                            date: date
                        });
                    }
                });
            });

            console.log('Matrix data points:', matrixData.length);

            // Find max count for normalization
            const maxCount = Math.max(...matrixData.map(d => d.v), 1);
            console.log('Max count:', maxCount);

            const spamCalendarCtx = document.getElementById('spamCalendarChart');
            if (!spamCalendarCtx) {
                throw new Error('Spam Calendar chart canvas not found!');
            }

                new Chart(spamCalendarCtx, {
                    type: 'scatter',
                    data: {
                        datasets: [{
                            data: matrixData.map(d => ({
                                x: d.x,
                                y: d.y,
                                count: d.v,
                                party: d.party,
                                date: d.date
                            })),
                            backgroundColor: function(context) {
                                const point = context.raw;
                                if (!point) return 'rgba(255,255,255,0.1)';
                                const intensity = point.count / maxCount;
                                const baseColor = partyColors[point.party];
                                // Convert hex to rgb and apply opacity
                                const hex = baseColor.replace('#', '');
                                const r = parseInt(hex.substring(0,2), 16);
                                const g = parseInt(hex.substring(2,4), 16);
                                const b = parseInt(hex.substring(4,6), 16);
                                return `rgba(${r}, ${g}, ${b}, ${intensity})`;
                            },
                            pointRadius: function(context) {
                                return 8;
                            },
                            pointStyle: 'rect'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                        backgroundColor: '#000',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#333',
                        borderWidth: 1,
                        cornerRadius: 0,
                        callbacks: {
                            title: function(context) {
                                const point = context[0].raw;
                                return `${partyNames[point.party]} - ${point.date}`;
                            },
                            label: function(context) {
                                const point = context.raw;
                                return `${point.count} Anfrage(n)`;
                            }
                        },
                        titleFont: { family: 'Bebas Neue', size: 14 },
                        bodyFont: { family: 'JetBrains Mono', size: 12 }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        min: -0.5,
                        max: allDatesForCalendar.length - 0.5,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: {
                            stepSize: Math.max(1, Math.floor(allDatesForCalendar.length / 20)),
                            font: { family: 'JetBrains Mono', size: 8 },
                            color: '#666',
                            callback: function(value, index) {
                                if (allDatesForCalendar[value]) {
                                    const date = new Date(allDatesForCalendar[value]);
                                    return date.toLocaleDateString('de-DE', { month: 'short', day: 'numeric' });
                                }
                                return '';
                            }
                        }
                    },
                    y: {
                        type: 'linear',
                        min: -0.5,
                        max: partyOrder.length - 0.5,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: {
                            stepSize: 1,
                            font: { family: 'JetBrains Mono', size: 11 },
                            color: '#fff',
                            callback: function(value) {
                                return partyNames[partyOrder[value]] || '';
                            }
                        }
                        }
                    }
                }
                });
                console.log('Spam Calendar Chart initialized successfully');;
            } catch (error) {
                console.error('ERROR initializing Spam Calendar Chart:', error);
                alert('FEHLER beim Laden des Spam Calendar Charts: ' + error.message);
            }

            console.log('=== ALL CHARTS INITIALIZED ===');
            console.log('=== NGO TRACKER DEBUG END ===');
        }); // End of DOMContentLoaded event listener
    </script>
</body>
</html>