<?php
// ==========================================
// NGO ANFRAGEN TRACKER
// Single Purpose: Track NGO-related parliamentary inquiries
// ==========================================

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');

define('PARL_API_URL', 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true');

// NGO-related keywords for filtering
define('NGO_KEYWORDS', [
    'ngo',
    'ngos',
    'nicht-regierungsorganisation',
    'nicht regierungsorganisation',
    'nichtregierungsorganisation',
    'non-governmental',
    'zivilgesellschaft',
    'bürgerinitiative',
    'verein',
    'gemeinnützig',
    'civic organization',
    'civil society',
    'nonprofit',
    'non-profit',
    'ehrenamtlich'
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

        // Monthly data for graph
        $monthKey = $rowDate->format('Y-m');
        if (!isset($monthlyData[$monthKey])) {
            $monthlyData[$monthKey] = [
                'count' => 0,
                'label' => $rowDate->format('M Y'),
                'timestamp' => $rowDate->getTimestamp()
            ];
        }
        $monthlyData[$monthKey]['count']++;

        // Word frequency for word cloud
        $words = preg_split('/\s+/', mb_strtolower($rowTitle));
        foreach ($words as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (mb_strlen($word) > 4) { // Only words longer than 4 chars
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

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 25;
$totalResults = count($allNGOResults);
$totalPages = ceil($totalResults / $perPage);
$offset = ($page - 1) * $perPage;
$displayResults = array_slice($allNGOResults, $offset, $perPage);

$totalCount = count($allNGOResults);

// Party name mapping
$partyMap = [
    'S' => 'SPÖ',
    'V' => 'ÖVP',
    'F' => 'FPÖ',
    'G' => 'GRÜNE',
    'N' => 'NEOS',
    'OTHER' => 'Andere'
];

$partyColors = [
    'S' => ['color' => 'bg-red-500', 'text' => 'text-red-700', 'bg' => 'bg-red-50', 'hex' => '#ef4444'],
    'V' => ['color' => 'bg-black', 'text' => 'text-gray-700', 'bg' => 'bg-gray-50', 'hex' => '#000000'],
    'F' => ['color' => 'bg-blue-600', 'text' => 'text-blue-700', 'bg' => 'bg-blue-50', 'hex' => '#2563eb'],
    'G' => ['color' => 'bg-green-600', 'text' => 'text-green-700', 'bg' => 'bg-green-50', 'hex' => '#16a34a'],
    'N' => ['color' => 'bg-pink-500', 'text' => 'text-pink-700', 'bg' => 'bg-pink-50', 'hex' => '#ec4899'],
    'OTHER' => ['color' => 'bg-gray-400', 'text' => 'text-gray-700', 'bg' => 'bg-gray-50', 'hex' => '#9ca3af']
];

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NGO Anfragen Tracker – Parlamentarische Anfragen zu NGOs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/wordcloud@1.2.2/src/wordcloud2.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="glass rounded-2xl shadow-2xl p-6 md:p-8 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                        🏛️ NGO Anfragen Tracker
                    </h1>
                    <p class="text-gray-600">
                        Wer spricht im Parlament über NGOs? Wie? Warum?
                    </p>
                </div>
                <form method="GET" class="flex items-center gap-3">
                    <label class="text-sm font-medium text-gray-700">Zeitraum:</label>
                    <select name="range" onchange="this.form.submit()"
                            class="px-4 py-2 rounded-lg border-2 border-purple-200 focus:border-purple-500 focus:outline-none bg-white font-medium text-gray-900">
                        <option value="6months" <?php echo $timeRange === '6months' ? 'selected' : ''; ?>>Letzte 6 Monate</option>
                        <option value="12months" <?php echo $timeRange === '12months' ? 'selected' : ''; ?>>Letzte 12 Monate</option>
                        <option value="1year" <?php echo $timeRange === '1year' ? 'selected' : ''; ?>>Letztes Jahr</option>
                        <option value="3years" <?php echo $timeRange === '3years' ? 'selected' : ''; ?>>Letzte 3 Jahre</option>
                        <option value="5years" <?php echo $timeRange === '5years' ? 'selected' : ''; ?>>Letzte 5 Jahre</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-6">
            <!-- Total Count -->
            <div class="glass rounded-xl shadow-lg p-6 stat-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider">Total Anfragen</h3>
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="text-4xl font-bold text-gray-900"><?php echo $totalCount; ?></div>
                <div class="text-sm text-gray-500 mt-1"><?php echo $rangeLabel; ?></div>
            </div>

            <!-- Party Distribution -->
            <div class="glass rounded-xl shadow-lg p-6 stat-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider">Nach Partei</h3>
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="space-y-2">
                    <?php foreach ($partyStats as $code => $count): ?>
                        <?php if ($count > 0): ?>
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full <?php echo $partyColors[$code]['color']; ?>"></div>
                                    <span class="font-medium text-gray-700"><?php echo $partyMap[$code]; ?></span>
                                </span>
                                <span class="font-bold text-gray-900"><?php echo $count; ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Answered -->
            <div class="glass rounded-xl shadow-lg p-6 stat-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider">Beantwortet</h3>
                    <div class="p-2 bg-green-100 rounded-lg">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="text-4xl font-bold text-green-600"><?php echo $answeredCount; ?></div>
                <div class="text-sm text-gray-500 mt-1">
                    <?php echo $totalCount > 0 ? round(($answeredCount / $totalCount) * 100) : 0; ?>% der Anfragen
                </div>
            </div>

            <!-- Pending -->
            <div class="glass rounded-xl shadow-lg p-6 stat-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wider">Ausstehend</h3>
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="text-4xl font-bold text-orange-600"><?php echo $pendingCount; ?></div>
                <div class="text-sm text-gray-500 mt-1">
                    <?php echo $totalCount > 0 ? round(($pendingCount / $totalCount) * 100) : 0; ?>% der Anfragen
                </div>
            </div>
        </div>

        <!-- Graph and Word Cloud -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Development Graph -->
            <div class="glass rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                    </svg>
                    Entwicklung über Zeit
                </h2>
                <canvas id="timelineChart" class="w-full" style="max-height: 300px;"></canvas>
            </div>

            <!-- Word Cloud -->
            <div class="glass rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                    </svg>
                    Meist verwendete Begriffe
                </h2>
                <canvas id="wordCloud" class="w-full" style="height: 300px;"></canvas>
            </div>
        </div>

        <!-- Results List -->
        <div class="glass rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <svg class="w-7 h-7 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    Alle Anfragen
                </h2>
                <div class="text-sm text-gray-600">
                    Seite <?php echo $page; ?> von <?php echo $totalPages; ?> (<?php echo $totalResults; ?> Gesamt)
                </div>
            </div>

            <?php if (empty($displayResults)): ?>
                <div class="text-center py-12">
                    <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">Keine Anfragen gefunden</h3>
                    <p class="text-gray-600">Im gewählten Zeitraum wurden keine NGO-bezogenen Anfragen gefunden.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2">
                    <?php foreach ($displayResults as $result): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:border-purple-300 hover:shadow-md transition-all">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-1">
                                    <div class="w-3 h-3 rounded-full <?php echo $partyColors[$result['party']]['color']; ?>"></div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <a href="<?php echo htmlspecialchars($result['link']); ?>" target="_blank"
                                       class="text-gray-900 hover:text-purple-600 font-medium line-clamp-2 mb-2 block">
                                        <?php echo htmlspecialchars($result['title']); ?>
                                    </a>
                                    <div class="flex items-center gap-3 text-xs text-gray-600 flex-wrap">
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <?php echo $result['date']; ?>
                                        </span>
                                        <span>•</span>
                                        <span class="font-semibold"><?php echo $partyMap[$result['party']]; ?></span>
                                        <span>•</span>
                                        <span class="text-purple-600 font-mono"><?php echo htmlspecialchars($result['number']); ?></span>
                                        <?php if ($result['answered']): ?>
                                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-1 rounded-full bg-green-100 text-green-700 font-medium">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                Beantwortet (<?php echo $result['answer_number']; ?>/AB)
                                            </span>
                                        <?php else: ?>
                                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-1 rounded-full bg-orange-100 text-orange-700 font-medium">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Ausstehend
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-center gap-2 mt-6 pt-6 border-t border-gray-200">
                        <?php if ($page > 1): ?>
                            <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page - 1; ?>"
                               class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors font-medium">
                                ← Zurück
                            </a>
                        <?php endif; ?>

                        <div class="flex items-center gap-1">
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);

                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $i; ?>"
                                   class="px-3 py-2 rounded-lg font-medium transition-colors <?php echo $i === $page ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="?range=<?php echo $timeRange; ?>&page=<?php echo $page + 1; ?>"
                               class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors font-medium">
                                Weiter →
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="mt-6 text-center text-white text-sm">
            <p>Daten: <a href="https://www.parlament.gv.at" target="_blank" class="underline hover:text-purple-200">Parlament Österreich</a></p>
        </div>
    </div>

    <script>
        // Timeline Chart
        const monthLabels = <?php echo json_encode(array_values(array_map(fn($m) => $m['label'], $monthlyData))); ?>;
        const monthCounts = <?php echo json_encode(array_values(array_map(fn($m) => $m['count'], $monthlyData))); ?>;

        const ctx = document.getElementById('timelineChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'NGO-Anfragen',
                    data: monthCounts,
                    borderColor: '#9333ea',
                    backgroundColor: 'rgba(147, 51, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#9333ea'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Word Cloud
        const wordList = <?php echo json_encode(array_map(fn($word, $count) => [$word, $count], array_keys($topWords), array_values($topWords))); ?>;

        if (wordList.length > 0) {
            WordCloud(document.getElementById('wordCloud'), {
                list: wordList,
                gridSize: 8,
                weightFactor: 3,
                fontFamily: 'Inter, sans-serif',
                color: function() {
                    const colors = ['#9333ea', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95'];
                    return colors[Math.floor(Math.random() * colors.length)];
                },
                rotateRatio: 0.3,
                backgroundColor: 'transparent'
            });
        }
    </script>
</body>
</html>
