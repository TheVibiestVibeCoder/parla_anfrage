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
    'bzw', 'etc', 'usw', 'dass', 'daß', 'damit', 'dazu', 'davon',
    // Neutrale administrative & geografische Begriffe (sollten nicht als Kampfbegriffe erscheinen)
    'österreich', 'verein', 'vereine', 'vereinen', 'förderung', 'förderungen', 'finanzierung',
    'ihres', 'ressort', 'ressorts', 'bundeskanzler', 'bundesminister', 'ministerin',
    'bereich', 'bereiche', 'bereichs', 'thema', 'themen',
    'männer', 'frauen', 'personen', 'person',
    // Weitere neutrale Begriffe
    'projekt', 'projekte', 'projekts', 'maßnahme', 'maßnahmen',
    'zeitraum', 'jahr', 'jahre', 'jahren', 'monat', 'monate', 'monaten'
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
        // Format as "DD.MM.YYYY" (e.g., "15.01.2024")
        $earliestDateFormatted = $earliestDate->format('d.m.Y');
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

    <meta name="theme-color" content="#111111">
    <meta name="msapplication-TileColor" content="#111111">
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

    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

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
            --bg-color: #111111;
            --paper-color: #1a1a1a;
            --text-color: #e5e5e5;
            --text-muted: #a3a3a3;
            --border-color: #333333;
            
            --font-head: 'Bebas Neue', sans-serif;
            --font-body: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;

            /* Party Colors - Kept simpler */
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
            line-height: 1.6;
        }

        h1, h2, h3 { 
            font-family: var(--font-head); 
            font-weight: 400; /* Bebas Neue is bold by default */
            letter-spacing: 1px;
        }
        
        .container-custom {
            width: 100%; 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 0 1.5rem;
        }

        /* INVESTIGATIVE DOSSIER STYLE */
        .investigative-box {
            background: var(--bg-color);
            border-top: 4px solid var(--text-color);
            border-bottom: 1px solid var(--border-color);
            padding: 2rem 0;
            margin-bottom: 3rem;
        }

        .investigative-header {
            font-family: var(--font-head);
            font-size: 2.2rem;
            color: #fff;
            margin-bottom: 1.5rem;
            line-height: 1;
        }

        /* TYPOGRAPHY */
        .stat-value { 
            font-size: 4rem; 
            line-height: 1; 
            font-family: var(--font-head); 
            color: #fff; 
        }
        .stat-label { 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            font-family: var(--font-body);
            font-weight: 600;
            letter-spacing: 0.05em;
            color: var(--text-muted); 
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }

        /* FORM ELEMENTS */
        select {
            background: transparent;
            color: #fff;
            border: none;
            border-bottom: 2px solid #fff;
            padding: 0.5rem 2rem 0.5rem 0;
            font-family: var(--font-head);
            font-size: 1.5rem;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23FFFFFF%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 0 top 50%;
            background-size: .5em auto;
            border-radius: 0;
        }
        select:focus { outline: none; border-color: var(--text-muted); }

        /* LIST ITEMS - Editorial Style */
        .result-item {
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem 0;
            transition: background 0.2s;
        }
        .result-item:hover {
            background: #161616;
        }

        /* PAGINATION */
        .pag-btn {
            font-family: var(--font-body);
            font-weight: 600;
            padding: 0.5rem 1rem;
            color: var(--text-muted);
            border: 1px solid transparent;
        }
        .pag-btn:hover, .pag-btn.active {
            color: #fff;
            border-bottom: 1px solid #fff;
        }

        /* PARTY ACCENTS */
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

        .text-S { color: var(--color-s); }
        .text-V { color: var(--color-v); }
        .text-F { color: var(--color-f); }
        .text-G { color: var(--color-g); }
        .text-N { color: var(--color-n); }

        /* Screen Reader Only */
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0;
        }

        /* Info Button */
        .info-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border: 1px solid var(--border-color);
            border-radius: 50%;
            background: transparent;
            color: var(--text-muted);
            font-family: var(--font-mono);
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 8px;
        }
        .info-btn:hover {
            border-color: #fff;
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }

        /* Modal/Popup */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: var(--paper-color);
            border: 2px solid var(--text-color);
            max-width: 600px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 2rem;
            position: relative;
            animation: modalSlideIn 0.3s ease-out;
        }
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
        }
        .modal-close:hover {
            color: #fff;
        }
        .modal-title {
            font-family: var(--font-head);
            font-size: 2rem;
            color: #fff;
            margin-bottom: 1rem;
            padding-right: 2rem;
        }
        .modal-body {
            font-family: var(--font-body);
            color: var(--text-muted);
            line-height: 1.8;
        }
        .modal-body p {
            margin-bottom: 1rem;
        }
        .modal-body ul {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }
        .modal-body li {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <section class="h-[100vh] min-h-[600px] flex flex-col justify-between items-center text-center bg-black border-b border-white px-4">
        
        <div class="w-full pt-6 flex justify-between items-center max-w-[1200px]">
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 bg-white"></div>
                <span class="tracking-widest text-lg font-head text-white">NGO-Business</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 bg-red-600 rounded-full animate-pulse"></span>
                <span class="text-xs font-mono text-red-600 uppercase">Live Update</span>
            </div>
        </div>

        <div class="flex-grow flex flex-col justify-center max-w-4xl mx-auto w-full">
            <article>
                <header class="mb-8">
                    <span class="inline-block border-b border-gray-600 pb-1 mb-4 text-xs font-mono text-gray-400 uppercase tracking-[0.2em]">Die Analyse</span>
                    <h1 class="text-6xl md:text-8xl lg:text-9xl text-white leading-[0.9] mb-6" style="font-family: 'Bebas Neue', sans-serif;">
                        Das "NGO-Business"<br>Narrativ der FPÖ
                    </h1>
                </header>

                <div class="space-y-6 max-w-2xl mx-auto text-left md:text-center">
                    <p class="text-lg md:text-xl text-gray-300 font-sans leading-relaxed">
                        <span class="text-white font-bold border-b border-gray-600">Datenjournalismus:</span> Die FPÖ flutet das Parlament mit dem Begriff "NGO-Business". Seit <?php echo $earliestDateFormatted; ?>
                        sind <?php echo number_format($totalCount); ?> Anfragen zum Thema NGOs, fast immer mit dem Begriff "NGO-Business" versehen, eingegangen.
                    </p>

                    <p class="text-base md:text-lg text-gray-400 font-sans leading-relaxed">
                        Warum? Wichtige NGO-Arbeit wird ganz bewusst in den Kontext von Steuergeld-Verschwendung gerückt,
                        um die Arbeit von Non-Profit-Organisationen zu verunglimpfen.
                        <br><br>
                        <span class="text-white">Wir decken auf, was es mit den Anfragen auf sich hat.</span>
                    </p>
                </div>
            </article>
        </div>

        <div class="pb-8 w-full flex flex-col items-center justify-center">
             <a href="#tracker" class="group flex flex-col items-center gap-4 cursor-pointer hover:opacity-80 transition-opacity">
                <span class="text-xs font-mono uppercase tracking-widest text-gray-500 group-hover:text-white transition-colors">Zum Anfragen-Tracker</span>
                <div class="w-10 h-10 border border-white flex items-center justify-center rounded-full">
                    <svg class="w-4 h-4 text-white animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                    </svg>
                </div>
            </a>
        </div>
    </section>

    <div id="tracker" class="container-custom pt-16 md:pt-24">

        <header class="flex flex-col md:flex-row justify-between items-start md:items-end mb-16 border-b-2 border-white pb-6">
            <div>
                <div class="text-xs font-mono text-gray-500 mb-2">SYSTEM: PARLAMENT_WATCH // TRACKING: NGO_INTERACTIONS</div>
                <h2 class="text-6xl md:text-8xl text-white leading-none">Anfragen Tracker</h2>
            </div>
            
            <form method="GET" class="mt-8 md:mt-0 w-full md:w-auto">
                <div class="flex flex-col items-start">
                    <span class="text-[10px] uppercase tracking-widest text-gray-500 mb-1">Zeitraum wählen</span>
                    <select name="range" onchange="this.form.submit()" class="w-full md:w-auto hover:text-gray-300 transition-colors">
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 mb-20">
            
            <div class="lg:col-span-4 flex flex-col gap-12">
                <div class="border-l-4 border-white pl-6 py-2">
                    <div class="stat-label">Gesamtanzahl</div>
                    <div class="stat-value"><?php echo number_format($totalCount); ?></div>
                    <div class="text-sm font-sans text-gray-400 mt-2 italic">Anfragen im gewählten Zeitraum erfasst.</div>
                </div>

                <div>
                    <div class="stat-label mb-6">Verteilung nach Parteien</div>
                    <div class="space-y-4">
                        <?php 
                        // Sort party stats high to low for better visual list
                        arsort($partyStats);
                        foreach ($partyStats as $code => $count): 
                            if ($count === 0 && $code !== 'OTHER') continue;
                            $percentage = $totalCount > 0 ? ($count / $totalCount) * 100 : 0;
                        ?>
                        <div class="flex items-center gap-4 group">
                            <div class="w-12 text-sm font-bold text-gray-300"><?php echo isset($partyMap[$code]) ? $partyMap[$code] : $code; ?></div>
                            <div class="flex-grow h-8 bg-gray-900 relative overflow-hidden">
                                <div class="h-full bg-<?php echo $code; ?> transition-all duration-1000" style="width: <?php echo $percentage; ?>%;"></div>
                            </div>
                            <div class="w-12 text-right font-mono text-xs text-gray-500"><?php echo $count; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-8">
                <div class="investigative-box !border-t-2 !py-0 !border-b-0">
                    <div class="flex justify-between items-end mb-6">
                        <div class="flex items-center">
                            <h2 class="text-3xl text-white">Zeitlicher Verlauf</h2>
                            <button class="info-btn" onclick="openModal('timeline')" aria-label="Information zum zeitlichen Verlauf">i</button>
                        </div>
                    </div>
                    <div style="height: 350px; width: 100%;">
                        <canvas id="timelineChart"
                                role="img"
                                aria-label="Liniendiagramm: Zeitlicher Verlauf der NGO Business Anfragen"
                                aria-describedby="timeline-desc"></canvas>
                        <p id="timeline-desc" class="sr-only">Diagramm zeigt Verlauf der Anfragen über <?php echo $rangeLabel; ?>.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 mb-20">
            
            <div class="investigative-box">
                <div class="flex items-start mb-4">
                    <h2 class="investigative-header mb-0">Kampfbegriffe<br><span class="text-gray-500 text-lg font-sans font-normal">Die Sprache der Anfragen</span></h2>
                    <button class="info-btn" onclick="openModal('kampfbegriffe')" aria-label="Information zu Kampfbegriffen">i</button>
                </div>
                
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($topKampfbegriffe as $index => $item): ?>
                        <?php if ($index >= 10) break; // Only show top 10 for cleaner look ?>
                        <?php
                        arsort($item['partyBreakdown']);
                        $dominantParty = array_key_first($item['partyBreakdown']);
                        ?>
                        <div class="flex items-baseline justify-between border-b border-gray-800 pb-2 group hover:border-gray-600 transition-colors">
                            <div class="flex items-baseline gap-3">
                                <span class="text-xs font-mono text-gray-600">0<?php echo $index + 1; ?></span>
                                <span class="text-lg md:text-xl font-bold text-white group-hover:text-<?php echo $dominantParty; ?> transition-colors">
                                    <?php echo htmlspecialchars($item['word']); ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-mono text-gray-500 uppercase">Dominanz:</span>
                                <span class="text-xs font-bold px-1 bg-<?php echo $dominantParty; ?> text-black">
                                    <?php echo $partyMap[$dominantParty]; ?>
                                </span>
                                <span class="text-sm font-mono text-gray-400 ml-2"><?php echo $item['count']; ?>×</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="investigative-box">
                <div class="flex items-start mb-4">
                    <h2 class="investigative-header mb-0">The Flood Wall<br><span class="text-gray-500 text-lg font-sans font-normal">Kumulative Belastung</span></h2>
                    <button class="info-btn" onclick="openModal('floodwall')" aria-label="Information zur Flood Wall">i</button>
                </div>
                <div style="height: 400px; width: 100%;">
                    <canvas id="floodWallChart"
                            role="img"
                            aria-label="Kumulative Belastungskurve"
                            aria-describedby="floodwall-desc"></canvas>
                    <p id="floodwall-desc" class="sr-only">Diagramm zeigt die kumulative Anzahl der Anfragen.</p>
                </div>
            </div>
        </div>

        <div class="investigative-box mb-20">
            <div class="flex items-start mb-4">
                <h2 class="investigative-header mb-0">Der Kalender<br><span class="text-gray-500 text-lg font-sans font-normal">Intensität nach Tagen</span></h2>
                <button class="info-btn" onclick="openModal('calendar')" aria-label="Information zum Kalender">i</button>
            </div>
             <div style="height: 300px; width: 100%;">
                <canvas id="spamCalendarChart"
                        role="img"
                        aria-label="Heatmap der Anfragen"
                        aria-describedby="calendar-desc"></canvas>
                <p id="calendar-desc" class="sr-only">Heatmap der täglichen Anfragen.</p>
            </div>
        </div>

        <div class="mb-24">
            <div class="flex justify-between items-end border-b-4 border-white pb-4 mb-8">
                <h2 class="text-4xl md:text-6xl text-white">Die Akten</h2>
                <div class="text-sm font-mono text-gray-500">
                    SEITE <?php echo $page; ?> / <?php echo $totalPages; ?>
                </div>
            </div>

            <?php if (empty($displayResults)): ?>
                <div class="py-20 text-center border-b border-gray-800">
                    <h3 class="text-gray-500 font-sans italic text-xl">Keine Daten in diesem Bereich gefunden.</h3>
                </div>
            <?php else: ?>
                <div class="flex flex-col">
                    <div class="hidden md:grid grid-cols-12 gap-6 text-xs font-mono text-gray-500 pb-2 uppercase tracking-widest border-b border-gray-800 mb-2">
                        <div class="col-span-2">Datum</div>
                        <div class="col-span-1">Partei</div>
                        <div class="col-span-7">Betreff</div>
                        <div class="col-span-2 text-right">Status</div>
                    </div>

                    <?php foreach ($displayResults as $result): ?>
                        <div class="result-item grid grid-cols-1 md:grid-cols-12 gap-3 md:gap-6 items-start group">
                            
                            <div class="flex justify-between md:hidden mb-1">
                                <span class="text-xs font-mono text-gray-400"><?php echo $result['date']; ?></span>
                                <span class="text-xs font-bold text-<?php echo $result['party']; ?>"><?php echo $partyMap[$result['party']]; ?></span>
                            </div>

                            <div class="hidden md:block md:col-span-2 font-mono text-sm text-gray-400">
                                <?php echo $result['date']; ?>
                                <div class="text-xs text-gray-600 mt-1"><?php echo $result['number']; ?></div>
                            </div>

                            <div class="hidden md:block md:col-span-1">
                                <span class="text-sm font-bold text-<?php echo $result['party']; ?>">
                                    <?php echo $partyMap[$result['party']]; ?>
                                </span>
                            </div>

                            <div class="md:col-span-7">
                                <a href="<?php echo htmlspecialchars($result['link']); ?>" target="_blank" class="text-base md:text-lg text-white font-sans leading-snug hover:underline decoration-1 underline-offset-4 decoration-gray-500">
                                    <?php echo htmlspecialchars($result['title']); ?>
                                </a>
                            </div>

                            <div class="md:col-span-2 flex justify-between md:block md:text-right mt-2 md:mt-0">
                                <?php if ($result['answered']): ?>
                                    <?php
                                    preg_match('/\/gegenstand\/([^\/]+)\//', $result['link'], $gpMatch);
                                    $gpCode = $gpMatch[1] ?? 'XXVIII';
                                    $answerLink = "https://www.parlament.gv.at/gegenstand/{$gpCode}/AB/{$result['answer_number']}";
                                    ?>
                                    <a href="<?php echo htmlspecialchars($answerLink); ?>" target="_blank" class="inline-block border border-green-900 text-green-500 px-2 py-1 text-xs font-mono uppercase hover:bg-green-900 hover:text-white transition-colors">
                                        Beantwortet
                                    </a>
                                <?php else: ?>
                                    <span class="inline-block bg-red-900/20 text-red-500 px-2 py-1 text-xs font-mono uppercase">
                                        Offen
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <div class="flex flex-wrap justify-center gap-4 mt-16">
                    <?php if ($page > 1): ?>
                        <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page - 1; ?>" class="pag-btn">&larr; Zurück</a>
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
                        <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page + 1; ?>" class="pag-btn">Weiter &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <section class="mb-24 pt-12 border-t border-gray-800" itemscope itemtype="https://schema.org/FAQPage">
            <h2 class="text-3xl text-white mb-12 font-head text-center">Hintergrund</h2>

            <div class="max-w-4xl mx-auto space-y-8">
                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was sind parlamentarische Anfragen?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-400 leading-relaxed font-sans" itemprop="text">
                            Parlamentarische Anfragen sind ein offizielles Kontrollinstrument im österreichischen Nationalrat.
                            Abgeordnete können damit schriftliche Fragen an Ministerien richten, die verpflichtend beantwortet werden müssen.
                            Sie dienen grundsätzlich der demokratischen Kontrolle der Regierung.
                        </p>
                    </div>
                </div>

                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was könnte die Strategie dahinter sein?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-400 leading-relaxed font-sans" itemprop="text">
                            Die massenhafte Verwendung des Begriffs „NGO Business" deutet auf eine bewusste politische Strategie hin.
                            Durch hunderte nahezu identische Anfragen wird ein Narrativ erzeugt, das NGO Arbeit mit
                            Steuergeldverschwendung, Ideologie und Missbrauch öffentlicher Mittel verknüpft.
                            Ziel ist weniger Aufklärung als vielmehr Delegitimierung.
                        </p>
                    </div>
                </div>

                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Wieso ist das relevant?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-400 leading-relaxed font-sans" itemprop="text">
                            Parlamentarische Anfragen erzeugen öffentliche Dokumente, Schlagzeilen und Suchtreffer.
                            Werden sie strategisch geflutet, entsteht der Eindruck eines systemischen Problems,
                            selbst wenn keine Rechtswidrigkeit vorliegt.
                            So kann Vertrauen in Zivilgesellschaft, Wissenschaft und soziale Arbeit gezielt untergraben werden.
                        </p>
                    </div>
                </div>

                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was kannst du tun?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-400 leading-relaxed font-sans" itemprop="text">
                            Red darüber. Teile die Daten. Hinterfrage Schlagworte.
                            Je sichtbarer solche Muster werden, desto schwerer wird es,
                            parlamentarische Instrumente für politische Stimmungsmache zu missbrauchen.
                        </p>
                    </div>
                </div>

                <div class="border-t border-gray-700 pt-8" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was ist Keyword-Squatting?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-300 leading-relaxed" itemprop="text">
                            Keyword-Squatting beschreibt die gezielte Besetzung eines Begriffs durch massenhafte Wiederholung,
                            um dessen Bedeutung langfristig zu prägen.
                            Der Begriff wird so häufig verwendet, dass er in Suchmaschinen,
                            Medienberichten und öffentlichen Dokumenten automatisch mit einem bestimmten Narrativ verknüpft wird.
                        </p>

                        <p class="text-gray-300 leading-relaxed mt-4" itemprop="text">
                            Im Fall von „NGO-Business" entsteht durch hunderte parlamentarische Anfragen eine künstliche Verbindung
                            zwischen NGOs und negativ konnotierten Begriffen wie Steuergeld, Ideologie oder Missbrauch,
                            unabhängig davon, ob es reale Probleme gibt.
                        </p>

                        <p class="text-gray-300 leading-relaxed mt-4" itemprop="text">
                            Parlamentsseiten eignen sich dafür besonders gut.
                            Sie gelten als staatliche Primärquelle, besitzen hohe Glaubwürdigkeit
                            und werden von Suchmaschinen stark priorisiert.
                            Jeder dort verwendete Begriff erhält dadurch Sichtbarkeit, Autorität und Dauerhaftigkeit.
                        </p>

                        <p class="text-gray-300 leading-relaxed mt-4" itemprop="text">
                            Wird ein Schlagwort systematisch über parlamentarische Dokumente verbreitet,
                            entsteht ein digitales Archiv politischer Narrative,
                            das weit über tagespolitische Debatten hinaus wirkt.
                        </p>
                    </div>
                </div>
            </div>
        </section>

    </div>

    <!-- Modals für Graph-Informationen -->
    <div id="modal-timeline" class="modal-overlay" onclick="closeModalOnOverlay(event, 'timeline')">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal('timeline')" aria-label="Schließen">&times;</button>
            <h3 class="modal-title">Zeitlicher Verlauf</h3>
            <div class="modal-body">
                <p><strong>Was zeigt diese Grafik?</strong></p>
                <p>Diese Grafik zeigt, wie viele NGO-bezogene parlamentarische Anfragen im gewählten Zeitraum gestellt wurden.</p>
                <p><strong>Wie wird sie berechnet?</strong></p>
                <p>Für jeden Tag oder Monat (je nach gewähltem Zeitraum) werden alle Anfragen gezählt, die NGO-relevante Begriffe enthalten. Die Linie zeigt die Entwicklung über die Zeit.</p>
                <p><strong>Was bedeutet das?</strong></p>
                <p>Spitzen in der Kurve zeigen Phasen besonders intensiver Anfrage-Aktivität. So wird sichtbar, wann das Thema "NGO Business" verstärkt im Parlament thematisiert wurde.</p>
            </div>
        </div>
    </div>

    <div id="modal-kampfbegriffe" class="modal-overlay" onclick="closeModalOnOverlay(event, 'kampfbegriffe')">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal('kampfbegriffe')" aria-label="Schließen">&times;</button>
            <h3 class="modal-title">Kampfbegriffe</h3>
            <div class="modal-body">
                <p><strong>Was zeigt diese Grafik?</strong></p>
                <p>Diese Liste zeigt die häufigsten politisch aufgeladenen Begriffe aus den Anfragen und welche Partei diese am meisten verwendet.</p>
                <p><strong>Wie wird sie berechnet?</strong></p>
                <p>Alle Wörter aus den Anfragen werden analysiert. Neutrale Begriffe wie "Österreich", "Verein" oder "Förderung" werden herausgefiltert. Übrig bleiben gezielte Schlagwörter wie "Steuergeldmillionen", "LGBTIQ-Maßnahmen" oder "NGO-Business". Für jedes Wort wird gezählt, welche Partei es wie oft verwendet hat.</p>
                <p><strong>Was bedeutet das?</strong></p>
                <p>Die Liste zeigt, mit welchen Begriffen NGOs gezielt in einen bestimmten Kontext gerückt werden. Die "Dominanz" zeigt, welche Partei ein Wort besonders häufig nutzt, um ihr Narrativ zu formen.</p>
            </div>
        </div>
    </div>

    <div id="modal-floodwall" class="modal-overlay" onclick="closeModalOnOverlay(event, 'floodwall')">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal('floodwall')" aria-label="Schließen">&times;</button>
            <h3 class="modal-title">The Flood Wall</h3>
            <div class="modal-body">
                <p><strong>Was zeigt diese Grafik?</strong></p>
                <p>Diese Grafik zeigt die kumulative (aufaddierte) Anzahl der Anfragen jeder Partei über die Zeit.</p>
                <p><strong>Wie wird sie berechnet?</strong></p>
                <p>Für jede Partei wird täglich gezählt: Wie viele Anfragen hat diese Partei insgesamt bis zu diesem Tag gestellt? Die Linien steigen also nur an, nie ab. Je steiler die Linie, desto mehr Anfragen wurden in diesem Zeitraum gestellt.</p>
                <p><strong>Was bedeutet das?</strong></p>
                <p>Die "Flood Wall" macht sichtbar, wie systematisch und massiv einzelne Parteien das Parlament mit Anfragen zu einem Thema "überfluten". Eine steil ansteigende Linie bedeutet: Hier wird intensiv und kontinuierlich Druck aufgebaut.</p>
            </div>
        </div>
    </div>

    <div id="modal-calendar" class="modal-overlay" onclick="closeModalOnOverlay(event, 'calendar')">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="modal-close" onclick="closeModal('calendar')" aria-label="Schließen">&times;</button>
            <h3 class="modal-title">Der Kalender</h3>
            <div class="modal-body">
                <p><strong>Was zeigt diese Grafik?</strong></p>
                <p>Diese Heatmap zeigt für jeden Tag, wie viele Anfragen jede Partei gestellt hat. Je intensiver die Farbe, desto mehr Anfragen wurden an diesem Tag eingereicht.</p>
                <p><strong>Wie wird sie berechnet?</strong></p>
                <p>Jeder Tag wird als Punkt dargestellt. Die Farbe entspricht der jeweiligen Partei. Die Farbintensität (Helligkeit) zeigt die Anzahl der Anfragen: Dunkel = wenige Anfragen, Hell/Leuchtend = viele Anfragen an diesem Tag.</p>
                <p><strong>Was bedeutet das?</strong></p>
                <p>Der Kalender macht "Bulk-Tage" sichtbar - Tage, an denen besonders viele Anfragen auf einmal eingereicht wurden. Solche koordinierten Massen-Einreichungen sind ein Zeichen für strategisches, geplantes Vorgehen.</p>
            </div>
        </div>
    </div>

    <footer class="bg-black border-t border-white py-12 mt-auto">
        <div class="container-custom">
            <div class="flex flex-col md:flex-row justify-between items-start gap-8">
                <div class="max-w-md">
                    <h3 class="text-sm font-bold text-white mb-4 uppercase tracking-wider">Über das Projekt</h3>
                    <p class="text-xs text-gray-500 leading-relaxed font-sans mb-4">
                        Der NGO Business Tracker analysiert parlamentarische Anfragen im österreichischen Nationalrat, die gezielt zum Thema NGOs gestellt werden.
                        <br><br>
                        Er macht sichtbar, wie oft, von wem und in welchen Mustern das Framing gepusht wird.
                    </p>
                    <div class="text-xs font-mono text-gray-600">
                         © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-xs font-mono text-gray-500 mb-2">QUELLE: PARLAMENT.GV.AT</div>
                    <div class="text-xs font-mono text-gray-500 mb-2">LAST UPDATE: <?php echo date('d.m.Y H:i'); ?></div>
                    <div class="flex items-center justify-end gap-2 mt-4">
                        <div class="w-2 h-2 bg-green-600 rounded-full"></div>
                        <span class="text-xs font-mono text-green-600">SYSTEM OPERATIONAL</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <script>
        console.log('=== NGO TRACKER DEBUG START ===');
        
        document.addEventListener('DOMContentLoaded', function() {
            // Chart Config - Cleaner, less "techy" more editorial
            Chart.defaults.color = '#555';
            Chart.defaults.borderColor = 'rgba(255,255,255,0.1)';
            Chart.defaults.font.family = "'Inter', sans-serif";

            // Data Prep
            const monthlyData = <?php echo json_encode($monthlyData); ?>;
            const floodData = <?php echo json_encode($floodWallData); ?>;
            const spamData = <?php echo json_encode($spamCalendarData); ?>;
            const dates = <?php echo json_encode(array_values(array_map(fn($d) => $d->format('d.m.Y'), $allDates))); ?>;
            const allDateKeys = <?php echo json_encode(array_keys($allDates)); ?>;
            
            const partyColors = {
                'S': '#ef4444', 'V': '#22d3ee', 'F': '#3b82f6',
                'G': '#22c55e', 'N': '#e879f9', 'OTHER': '#9ca3af'
            };
            const partyNames = {
                'S': 'SPÖ', 'V': 'ÖVP', 'F': 'FPÖ',
                'G': 'GRÜNE', 'N': 'NEOS', 'OTHER': 'ANDERE'
            };

            // 1. TIMELINE
            const ctx1 = document.getElementById('timelineChart');
            if (ctx1) {
                const labels = Object.values(monthlyData).map(m => m.label);
                const counts = Object.values(monthlyData).map(m => m.count);
                
                new Chart(ctx1, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Anfragen',
                            data: counts,
                            borderColor: '#ffffff',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { display: true, drawBorder: false } },
                            x: { 
                                grid: { display: false },
                                display: true,
                                ticks: {
                                    color: '#666',
                                    font: { family: 'JetBrains Mono', size: 10 },
                                    autoSkip: true,
                                    maxRotation: 0
                                }
                            }
                        }
                    }
                });
            }

            // 2. FLOOD WALL
            const ctx2 = document.getElementById('floodWallChart');
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: Object.keys(floodData).map(party => ({
                            label: partyNames[party],
                            data: floodData[party].map(d => d.cumulative),
                            borderColor: partyColors[party],
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            pointRadius: 0,
                            stepped: true
                        }))
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { 
                            legend: { labels: { color: '#aaa', font: { family: 'Inter' } } }
                        },
                        scales: {
                            x: { 
                                display: true,
                                grid: { display: false },
                                ticks: {
                                    color: '#666',
                                    font: { family: 'JetBrains Mono', size: 10 },
                                    autoSkip: true,
                                    maxRotation: 0
                                }
                            },
                            y: { grid: { color: '#222' } }
                        }
                    }
                });
            }

            // 3. SPAM CALENDAR
            const ctx3 = document.getElementById('spamCalendarChart');
            if (ctx3) {
                const matrixData = [];
                const pOrder = ['S', 'V', 'F', 'G', 'N', 'OTHER'];
                
                pOrder.forEach((party, pIdx) => {
                    const pData = spamData[party] || [];
                    const dMap = {};
                    pData.forEach(i => dMap[i.date] = i.count);
                    
                    allDateKeys.forEach((d, dIdx) => {
                        if (dMap[d]) {
                            matrixData.push({
                                x: dIdx, y: pIdx, v: dMap[d], party: party, date: d
                            });
                        }
                    });
                });

                const maxVal = Math.max(...matrixData.map(d => d.v), 1);

                new Chart(ctx3, {
                    type: 'scatter',
                    data: {
                        datasets: [{
                            data: matrixData.map(d => ({ x: d.x, y: d.y, v: d.v, p: d.party, date: d.date })),
                            backgroundColor: ctx => {
                                const v = ctx.raw;
                                if (!v) return '#333';
                                const c = partyColors[v.p];
                                // Improved color intensity: wider range from 0.2 to 1.0 for better contrast
                                // Using exponential curve to make differences more pronounced
                                const normalizedValue = v.v / maxVal;
                                const alpha = 0.2 + Math.pow(normalizedValue, 0.7) * 0.8;
                                return c + Math.floor(alpha * 255).toString(16).padStart(2,'0');
                            },
                            pointRadius: 5,
                            pointStyle: 'rect'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => `${partyNames[ctx.raw.p]}: ${ctx.raw.v} Anfragen am ${ctx.raw.date}`
                                }
                            }
                        },
                        scales: {
                            x: { 
                                display: true,
                                grid: { display: false },
                                ticks: {
                                    callback: function(value, index) {
                                        // Ensure we have a date for this index
                                        const dateKey = allDateKeys[value];
                                        if(dateKey) {
                                            const parts = dateKey.split('-');
                                            return `${parts[2]}.${parts[1]}.`;
                                        }
                                        return '';
                                    },
                                    color: '#666',
                                    font: { family: 'JetBrains Mono', size: 10 },
                                    autoSkip: true,
                                    maxRotation: 0
                                }
                            },
                            y: { 
                                min: -0.5, max: 5.5,
                                ticks: { callback: v => partyNames[pOrder[v]] },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }

            // Modal Functions
            window.openModal = function(modalId) {
                const modal = document.getElementById('modal-' + modalId);
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            };

            window.closeModal = function(modalId) {
                const modal = document.getElementById('modal-' + modalId);
                if (modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            };

            window.closeModalOnOverlay = function(event, modalId) {
                if (event.target.classList.contains('modal-overlay')) {
                    closeModal(modalId);
                }
            };

            // Close modal on ESC key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    const activeModal = document.querySelector('.modal-overlay.active');
                    if (activeModal) {
                        const modalId = activeModal.id.replace('modal-', '');
                        closeModal(modalId);
                    }
                }
            });
        });
    </script>
</body>
</html>