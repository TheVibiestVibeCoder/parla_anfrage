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

// PERFORMANCE OPTIMIZATION: Load Cache Manager
require_once __DIR__ . '/CacheManager.php';

// Initialize cache with 15-minute TTL (900 seconds)
// This ensures data is fresh while dramatically improving load times
$cache = new CacheManager(__DIR__ . '/cache', 900);

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

// CACHE KEY: Unique identifier for this specific data request
// Includes GP codes and cutoff date to ensure different time ranges are cached separately
$cacheKey = 'ngo_data_' . md5(serialize($gpCodes) . $cutoffDate->format('Y-m-d'));

// Try to get cached data first - this dramatically speeds up repeated requests
$cachedData = $cache->get($cacheKey);

if ($cachedData !== null) {
    // Cache hit! Use the cached data instead of fetching and processing
    $allNGOResults = $cachedData['allNGOResults'];
    $wordFrequency = $cachedData['wordFrequency'];
    $monthlyData = $cachedData['monthlyData'];
    $partyStats = $cachedData['partyStats'];
    $answeredCount = $cachedData['answeredCount'];
    $pendingCount = $cachedData['pendingCount'];

    // Log cache hit for debugging
    error_log("Cache HIT for key: $cacheKey");
} else {
    // Cache miss - fetch and process data, then cache the result
    error_log("Cache MISS for key: $cacheKey - fetching fresh data");

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

    // CACHE THE PROCESSED DATA
    // Store all processed results so subsequent requests are lightning fast
    $cache->set($cacheKey, [
        'allNGOResults' => $allNGOResults,
        'wordFrequency' => $wordFrequency,
        'monthlyData' => $monthlyData,
        'partyStats' => $partyStats,
        'answeredCount' => $answeredCount,
        'pendingCount' => $pendingCount
    ]);

    error_log("Data cached successfully for key: $cacheKey");
}

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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://www.parlament.gv.at">

    <link rel="preload" href="https://fonts.gstatic.com/s/bebasneue/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuI6fMZhrib2Bg-4.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v24/tDbv2o-flEEny0FZhsfKu5WU5zr3E_BX0zS8.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">

    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet"></noscript>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="styles.css">

    <script>
        // Lazy load Chart.js with minimal overhead
        (function() {
            var loaded = false;
            var loading = false;

            window.loadChartJS = function() {
                if (loaded || loading) return Promise.resolve();
                loading = true;

                return new Promise(function(resolve, reject) {
                    var s = document.createElement('script');
                    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
                    s.onload = function() { loaded = true; resolve(); };
                    s.onerror = reject;
                    document.head.appendChild(s);
                });
            };

            // Lightweight intersection observer - only set up when idle
            function setupObserver() {
                if (!('IntersectionObserver' in window)) {
                    loadChartJS();
                    return;
                }

                var observer = new IntersectionObserver(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i].isIntersecting) {
                            loadChartJS();
                            observer.disconnect();
                            break;
                        }
                    }
                }, { rootMargin: '100px' });

                var canvases = document.querySelectorAll('canvas');
                for (var i = 0; i < canvases.length; i++) {
                    observer.observe(canvases[i]);
                }
            }

            // Defer observer setup to idle time
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if ('requestIdleCallback' in window) {
                        requestIdleCallback(setupObserver, { timeout: 2000 });
                    } else {
                        setTimeout(setupObserver, 1);
                    }
                });
            } else {
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(setupObserver, { timeout: 2000 });
                } else {
                    setTimeout(setupObserver, 1);
                }
            }
        })();
    </script>

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
     
</head>
<body class="flex flex-col min-h-screen">

    <section class="min-h-screen flex flex-col justify-between items-center text-center bg-black border-b border-white px-4 py-6 md:px-6 md:py-8 lg:py-12">
        
        <div class="w-full flex justify-between items-center max-w-[1200px]">
            <div class="flex items-center gap-2 md:gap-3">
                <div class="w-2 h-2 md:w-3 md:h-3 bg-white"></div>
                <span class="tracking-widest text-sm md:text-lg font-head text-white">NGO-Business</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-1.5 h-1.5 md:w-2 md:h-2 bg-red-600 rounded-full animate-pulse"></span>
                <span class="text-[10px] md:text-xs font-mono text-red-600 uppercase">Live Update</span>
            </div>
        </div>

        <div class="flex-grow flex flex-col justify-center max-w-5xl mx-auto w-full py-4 md:py-8 lg:py-0">
            <article>
                <header class="mb-6 md:mb-8">
                    <span class="inline-block border-b border-gray-600 pb-1 mb-4 md:mb-6 text-[10px] md:text-xs font-mono text-gray-400 uppercase tracking-[0.2em]">Die Analyse</span>
                    <h1 class="text-5xl sm:text-6xl md:text-6xl lg:text-7xl xl:text-8xl 2xl:text-9xl text-white leading-[0.9] mb-4 md:mb-6 break-words tracking-tight" style="font-family: 'Bebas Neue', sans-serif;">
                        Das "NGO-Business"<br>Narrativ der FPÖ
                    </h1>
                </header>

                <div class="space-y-4 md:space-y-6 max-w-3xl mx-auto text-left md:text-center px-4">
                    <p class="text-sm md:text-base lg:text-lg text-gray-300 font-sans leading-relaxed">
                        Die FPÖ flutet das Parlament mit dem Begriff "NGO-Business". Seit <?php echo $earliestDateFormatted; ?>
                        sind <?php echo number_format($totalCount); ?> Anfragen zum Thema NGOs, fast immer mit dem Begriff "NGO-Business" versehen, eingegangen.
                    </p>

                    <p class="text-xs md:text-sm lg:text-base text-gray-400 font-sans leading-relaxed">
                        Warum? Wichtige NGO-Arbeit wird ganz bewusst in den Kontext von Steuergeld-Verschwendung gerückt,
                        um die Arbeit von Non-Profit-Organisationen zu verunglimpfen.
                        <br><br>
                        <span class="text-white">Wir decken auf, was es mit den Anfragen auf sich hat.</span>
                    </p>
                </div>
            </article>
        </div>

        <div class="w-full flex flex-col items-center justify-center pb-6 md:pb-0">
             <a href="#tracker" class="group flex flex-col items-center gap-4 cursor-pointer hover:opacity-80 transition-opacity p-2">
                <span class="text-[10px] md:text-xs font-mono uppercase tracking-widest text-gray-500 group-hover:text-white transition-colors">Zum Anfragen-Tracker</span>
                <div class="w-8 h-8 md:w-10 md:h-10 border border-white flex items-center justify-center rounded-full">
                    <svg class="w-3 h-3 md:w-4 md:h-4 text-white animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                    </svg>
                </div>
            </a>
        </div>
    </section>

    <main id="tracker" class="container-custom pt-16 md:pt-24 lg:pt-20 xl:pt-24">

        <header class="flex flex-col lg:flex-row justify-between items-start lg:items-end mb-12 lg:mb-16 xl:mb-20 border-b-2 border-white pb-8">
            <div class="mb-8 lg:mb-0">
                <h2 class="text-5xl md:text-6xl lg:text-7xl xl:text-8xl text-white leading-none">Anfragen Tracker</h2>
            </div>
            
            <form method="GET" class="w-full lg:w-auto">
                <div class="flex flex-col items-start w-full">
                    <label for="time-range-select" class="text-[10px] uppercase tracking-widest text-gray-500 mb-1">Zeitraum wählen</label>
                    <select id="time-range-select" name="range" onchange="this.form.submit()" class="w-full lg:w-auto hover:text-gray-300 transition-colors" aria-label="Zeitraum für Anfragen auswählen">
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-10 xl:gap-16 mb-16 lg:mb-20">
            
            <div class="lg:col-span-4 flex flex-col gap-8 lg:gap-10 xl:gap-12">
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
                    <div class="flex justify-between items-end mb-4 md:mb-6 pt-4">
                        <div class="flex items-center">
                            <h2 class="text-2xl md:text-3xl text-white">Zeitlicher Verlauf</h2>
                            <button class="info-btn" onclick="openModal('timeline')" aria-label="Information zum zeitlichen Verlauf">i</button>
                        </div>
                    </div>
                    <div class="h-[250px] sm:h-[300px] md:h-[350px] w-full relative">
                        <canvas id="timelineChart" 
                                role="img" 
                                aria-label="Liniendiagramm: Zeitlicher Verlauf der NGO Business Anfragen"
                                aria-describedby="timeline-desc"></canvas>
                        <p id="timeline-desc" class="sr-only">Diagramm zeigt Verlauf der Anfragen über <?php echo $rangeLabel; ?>.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-10 xl:gap-16 mb-16 lg:mb-20">
            
            <div class="investigative-box">
                <div class="flex items-start mb-4">
                    <h2 class="investigative-header mb-0">Kampfbegriffe<br><span class="text-gray-500 text-base md:text-lg font-sans font-normal">Die Sprache der Anfragen</span></h2>
                    <button class="info-btn" onclick="openModal('kampfbegriffe')" aria-label="Information zu Kampfbegriffen">i</button>
                </div>
                
                <div class="grid grid-cols-1 gap-3 md:gap-4">
                    <?php foreach ($topKampfbegriffe as $index => $item): ?>
                        <?php if ($index >= 10) break; // Only show top 10 for cleaner look ?>
                        <?php 
                        arsort($item['partyBreakdown']);
                        $dominantParty = array_key_first($item['partyBreakdown']);
                        ?>
                        <div class="flex flex-wrap items-baseline justify-between border-b border-gray-800 pb-2 group hover:border-gray-600 transition-colors gap-2">
                            <div class="flex items-baseline gap-3">
                                <span class="text-xs font-mono text-gray-600">0<?php echo $index + 1; ?></span>
                                <span class="text-base md:text-lg lg:text-xl font-bold text-white group-hover:text-<?php echo $dominantParty; ?> transition-colors break-all">
                                    <?php echo htmlspecialchars($item['word']); ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2 ml-auto">
                                <span class="hidden sm:inline text-xs font-mono text-gray-500 uppercase">Dominanz:</span>
                                <span class="text-[10px] md:text-xs font-bold px-1 bg-<?php echo $dominantParty; ?> text-black">
                                    <?php echo $partyMap[$dominantParty]; ?>
                                </span>
                                <span class="text-xs md:text-sm font-mono text-gray-400 ml-2"><?php echo $item['count']; ?>×</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="investigative-box">
                <div class="flex items-start mb-4">
                    <h2 class="investigative-header mb-0">The Flood Wall<br><span class="text-gray-500 text-base md:text-lg font-sans font-normal">Kumulative Belastung</span></h2>
                    <button class="info-btn" onclick="openModal('floodwall')" aria-label="Information zur Flood Wall">i</button>
                </div>
                <div class="h-[300px] md:h-[400px] w-full relative">
                    <canvas id="floodWallChart" 
                            role="img" 
                            aria-label="Kumulative Belastungskurve" 
                            aria-describedby="floodwall-desc"></canvas>
                    <p id="floodwall-desc" class="sr-only">Diagramm zeigt die kumulative Anzahl der Anfragen.</p>
                </div>
            </div>
        </div>

        <div class="investigative-box mb-20 lg:mb-24">
            <div class="flex items-start mb-4">
                <h2 class="investigative-header mb-0">Der Kalender<br><span class="text-gray-500 text-base md:text-lg font-sans font-normal">Intensität nach Tagen</span></h2>
                <button class="info-btn" onclick="openModal('calendar')" aria-label="Information zum Kalender">i</button>
            </div>
             <div class="h-[250px] sm:h-[300px] w-full relative">
                <canvas id="spamCalendarChart" 
                        role="img" 
                        aria-label="Heatmap der Anfragen" 
                        aria-describedby="calendar-desc"></canvas>
                <p id="calendar-desc" class="sr-only">Heatmap der täglichen Anfragen.</p>
            </div>
        </div>

        <div class="mb-24">
            <div class="flex justify-between items-end border-b-4 border-white pb-4 mb-8">
                <h2 class="text-4xl md:text-5xl lg:text-5xl xl:text-6xl text-white">Die Akten</h2>
                <div class="text-xs md:text-sm font-mono text-gray-500">
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
                            
                            <div class="flex justify-between items-baseline md:hidden mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono text-gray-400"><?php echo $result['date']; ?></span>
                                    <span class="text-[10px] text-gray-600"><?php echo $result['number']; ?></span>
                                </div>
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
                                <a href="<?php echo htmlspecialchars($result['link']); ?>" target="_blank" class="text-base md:text-lg text-white font-sans leading-snug hover:underline decoration-1 underline-offset-4 decoration-gray-500 block">
                                    <?php echo htmlspecialchars($result['title']); ?>
                                </a>
                            </div>

                            <div class="md:col-span-2 flex justify-end md:block md:text-right mt-2 md:mt-0">
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
                <div class="flex flex-wrap justify-center gap-2 md:gap-4 mt-12 md:mt-16">
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
            <h2 class="text-2xl md:text-3xl text-white mb-12 font-head text-center">Hintergrund</h2>

            <div class="max-w-4xl mx-auto space-y-8 px-2 md:px-0">
                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-lg md:text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was sind parlamentarische Anfragen?
                    </h3>
                    <div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p class="text-gray-400 leading-relaxed font-sans" itemprop="text">
                            Parlamentarische Anfragen sind ein offizielles Kontrollinstrument im österreichischen Nationalrat. 
                            Abgeordnete können damit <a href="https://www.parlament.gv.at/recherchieren/gegenstaende/anfragen-und-beantwortungen/" class="text-white hover:text-gray-300 underline">schriftliche Fragen an Ministerien richten</a>, die verpflichtend beantwortet werden müssen. 
                            Sie dienen grundsätzlich der demokratischen Kontrolle der Regierung.
                        </p>
                    </div>
                </div>

                <div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="text-lg md:text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
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
                    <h3 class="text-lg md:text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
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
                    <h3 class="text-lg md:text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
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
                    <h3 class="text-lg md:text-xl font-bold text-white mb-4 border-l-2 border-white pl-4" itemprop="name">
                        Was ist <a href="https://mediamanipulation.org/definitions/keyword-squatting/" class="text-white hover:text-gray-300 underline">Keyword-Squatting</a>?
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

    </main>

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

    <footer class="bg-black border-t border-white py-8 md:py-12 mt-auto">
        <div class="container-custom">
            <div class="flex flex-col md:flex-row justify-between items-start gap-8">
                <div class="max-w-md">
                    <h3 class="text-sm font-bold text-white mb-4 uppercase tracking-wider">Über das Projekt</h3>
                    <p class="text-xs text-gray-500 leading-relaxed font-sans mb-4">
                        Der NGO Business Tracker analysiert parlamentarische Anfragen im österreichischen Nationalrat, die gezielt zum Thema NGOs gestellt werden.
                        <br><br>
                        Er macht sichtbar, wie oft, von wem und in welchen Mustern das Framing gepusht wird.
                    </p>
                    <div class="text-xs text-yellow-600 leading-relaxed font-sans mb-4 italic">
                        Hinweis: Diese Plattform ist eine experimentelle Idee. Fehler können vorkommen.
                    </div>
                    <div class="text-xs font-mono text-gray-600">
                          © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
                    </div>
                    <div class="mt-2 space-x-4">
                        <a href="impressum.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Impressum</a>
                        <a href="kontakt.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Kontakt</a>
                    </div>
                </div>

                <div class="text-left md:text-right w-full md:w-auto">
                    <div class="text-xs font-mono text-gray-500 mb-2">QUELLE: PARLAMENT.GV.AT</div>
                    <div class="text-xs font-mono text-gray-500 mb-2">LAST UPDATE: <?php echo date('d.m.Y H:i'); ?></div>
                    <div class="flex items-center justify-start md:justify-end gap-2 mt-4">
                        <div class="w-2 h-2 bg-green-600 rounded-full"></div>
                        <span class="text-xs font-mono text-green-600">SYSTEM OPERATIONAL</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <script>
        console.log('=== NGO TRACKER DEBUG START ===');

        // Initialize charts function - called after Chart.js loads
        function initializeCharts() {
            // Wait for Chart.js to be available
            if (typeof Chart === 'undefined') {
                console.log('Chart.js not loaded yet, waiting...');
                return;
            }

            // Chart Config - Cleaner, less "techy" more editorial
            Chart.defaults.color = '#555';
            Chart.defaults.borderColor = 'rgba(255,255,255,0.1)';
            Chart.defaults.font.family = "'Inter', sans-serif";

            // Performance optimization: reduce animation duration to minimize layout thrashing
            Chart.defaults.animation = {
                duration: 400, // Reduced from default 1000ms
                easing: 'easeOutQuart'
            };
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;

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
                            pointRadius: 3,
                            pointHoverRadius: 8,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#ffffff',
                            pointHoverBorderWidth: 3,
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: true,
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#fff',
                                borderWidth: 1,
                                padding: 12,
                                displayColors: false,
                                callbacks: {
                                    title: function(context) {
                                        return 'Datum: ' + context[0].label;
                                    },
                                    label: function(context) {
                                        return 'Anfragen: ' + context.parsed.y;
                                    }
                                }
                            }
                        },
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
                console.log('Timeline Chart initialized with tooltips');
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
                            pointRadius: 2,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: partyColors[party],
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2,
                            stepped: true
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
                                labels: { color: '#aaa', font: { family: 'Inter' } }
                            },
                            tooltip: {
                                enabled: true,
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#fff',
                                borderWidth: 1,
                                padding: 12,
                                callbacks: {
                                    title: function(context) {
                                        return 'Datum: ' + context[0].label;
                                    },
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' (kumulativ)';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                display: true,
                                grid: { display: false },
                                ticks: {
                                    color: '#666',
                                    font: { family: 'JetBrains Mono', size: 10 },
                                    autoSkip: true,
                                    maxTicksLimit: 10,
                                    maxRotation: 0,
                                    minRotation: 0
                                }
                            },
                            y: { grid: { color: '#222' } }
                        }
                    }
                });
                console.log('Flood Wall Chart initialized with tooltips');
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
                            pointRadius: 8,
                            pointHoverRadius: 12,
                            pointStyle: 'rect',
                            pointHoverBorderWidth: 2,
                            pointHoverBorderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'point',
                            intersect: true
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: true,
                                mode: 'point',
                                intersect: true,
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#fff',
                                borderWidth: 1,
                                padding: 12,
                                displayColors: false,
                                callbacks: {
                                    title: function(context) {
                                        const dateKey = context[0].raw.date;
                                        const parts = dateKey.split('-');
                                        return 'Datum: ' + parts[2] + '.' + parts[1] + '.' + parts[0];
                                    },
                                    label: function(context) {
                                        const partyName = partyNames[context.raw.p];
                                        const count = context.raw.v;
                                        return partyName + ': ' + count + ' Anfrage' + (count > 1 ? 'n' : '');
                                    }
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
                console.log('Spam Calendar Chart initialized with tooltips');
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
        }

        // Initialize charts when Chart.js is ready and DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Load Chart.js and initialize charts
            loadChartJS().then(() => {
                // Small delay to ensure Chart.js is fully initialized
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        initializeCharts();
                    });
                });
            }).catch(err => {
                console.error('Failed to load Chart.js:', err);
            });
        });
    </script>
</body>
</html>