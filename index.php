<?php
// ==========================================
// CONFIG & SETUP
// ==========================================
ini_set('display_errors', 0); 
error_reporting(E_ALL);
ini_set('memory_limit', '256M');

// >>> HIER DEINEN KEY EINTRAGEN <<<
define('GEMINI_API_KEY', 'AIzaSyBYRUhq_7ra0oyqU18RSmCUNEpG1QJeIjg'); 

define('PARL_API_URL', 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true');

$debugInfo = [
    'ai_raw' => null,
    'stats' => ['api_rows' => 0, 'matches' => 0, 'total_before_pagination' => 0, 'answered' => 0, 'pending' => 0],
    'search_keywords' => [],
    'selected_parties' => [],
    'raw_get_parties' => $_GET['parties'] ?? 'not set'
];

// ==========================================
// HELPER: PARTEI CODE ERKENNUNG
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

// ==========================================
// HELPER: BEANTWORTUNG EXTRAHIEREN
// ==========================================
function extractAnswerInfo($rowTitle) {
    if (preg_match('/beantwortet durch (\d+)\/AB/i', $rowTitle, $matches)) {
        return [
            'answered' => true,
            'answer_number' => $matches[1]
        ];
    }
    return ['answered' => false, 'answer_number' => null];
}

// ==========================================
// AI LOGIK
// ==========================================
function translateQueryWithAI($userQuery) {
    global $debugInfo;
    if (empty(trim($userQuery))) return [];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . GEMINI_API_KEY;
    
    $systemPrompt = "Du bist ein intelligenter Such-Assistent für das Parlament.\n";
    $systemPrompt .= "1. 'GP_CODE': NICHT SETZEN (wird vom User gewählt).\n";
    $systemPrompt .= "2. 'FILTER_DATUM_VON' / 'FILTER_DATUM_BIS' (dd.mm.yyyy).\n";
    $systemPrompt .= "3. 'SEARCH_KEYWORDS': Array von Strings.\n";
    $systemPrompt .= "   - Korrigiere Tippfehler.\n";
    $systemPrompt .= "   - Füge 3-5 Synonyme hinzu (z.B. 'Teurung' -> ['Inflation', 'Preise', 'VPI']).\n";
    $systemPrompt .= "User Query: " . $userQuery . "\nOutput JSON only.";

    $data = [ "contents" => [ ["parts" => [["text" => $systemPrompt]]] ] ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $json = json_decode($response, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $text = str_replace(['```json', '```'], '', $text);
    
    $parsed = json_decode(trim($text), true);
    $debugInfo['ai_raw'] = $parsed;
    return $parsed;
}

// ==========================================
// API CALL
// ==========================================
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

// ==========================================
// MAIN PHP LOGIC
// ==========================================
$displayResults = [];
$searchQuery = $_GET['q'] ?? '';

// FIX: Properly handle party array from GET parameters
$selectedParties = [];
if (isset($_GET['parties']) && is_array($_GET['parties'])) {
    $selectedParties = $_GET['parties'];
} elseif (isset($_GET['parties'])) {
    $selectedParties = [$_GET['parties']];
}

// DEFAULT: Alle Parteien wenn keine ausgewählt
if (empty($selectedParties)) {
    $selectedParties = ['S', 'V', 'F', 'G', 'N'];
}

$debugInfo['selected_parties'] = $selectedParties;

$gpFrom = $_GET['gp_from'] ?? 'XXVII';
$gpTo = $_GET['gp_to'] ?? 'XXVIII';

$availableGPs = [
    'XXVIII' => '2024-2029',
    'XXVII' => '2019-2024',
    'XXVI' => '2017-2019',
    'XXV' => '2013-2017',
    'XXIV' => '2008-2013',
    'XXIII' => '2006-2008',
    'XXII' => '2002-2006',
    'XXI' => '1999-2002',
    'XX' => '1996-1999'
];

$gpKeys = array_keys($availableGPs);
$fromIndex = array_search($gpFrom, $gpKeys);
$toIndex = array_search($gpTo, $gpKeys);

if ($fromIndex !== false && $toIndex !== false) {
    $start = min($fromIndex, $toIndex);
    $end = max($fromIndex, $toIndex);
    $selectedGPs = array_slice($gpKeys, $start, $end - $start + 1);
} else {
    $selectedGPs = ['XXVII', 'XXVIII'];
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;

$isExactMode = isset($_GET['exact']) && $_GET['exact'] == '1';
$error = null;

$allFilteredResults = [];

if ($searchQuery) {
    if (GEMINI_API_KEY === '') {
        $error = "API Key fehlt!";
    } else {
        
        $keywords = [];
        $dStart = null; 
        $dEnd = null;

        if ($isExactMode) {
            $keywords = [$searchQuery];
            $debugInfo['ai_raw'] = "EXACT MODE ACTIVATED (AI BYPASSED)";
        } else {
            $aiData = translateQueryWithAI($searchQuery);
            
            if (!empty($aiData['FILTER_DATUM_VON'])) $dStart = DateTime::createFromFormat('d.m.Y', $aiData['FILTER_DATUM_VON']);
            if (!empty($aiData['FILTER_DATUM_BIS'])) $dEnd = DateTime::createFromFormat('d.m.Y', $aiData['FILTER_DATUM_BIS']);

            $keywords = $aiData['SEARCH_KEYWORDS'] ?? [];
            if (empty($keywords)) $keywords = [$searchQuery];
        }

        $keywords = array_map('mb_strtolower', $keywords);
        $debugInfo['search_keywords'] = $keywords;
        
        // OVERRIDE: Visualisierungs-Datumsfilter haben Vorrang
        if (!empty($_GET['viz_date_from'])) {
            $dStart = DateTime::createFromFormat('d.m.Y', $_GET['viz_date_from']);
            $debugInfo['viz_filter_applied'] = true;
        }
        if (!empty($_GET['viz_date_to'])) {
            $dEnd = DateTime::createFromFormat('d.m.Y', $_GET['viz_date_to']);
            $debugInfo['viz_filter_applied'] = true;
        }

        $apiResponse = fetchAllRows($selectedGPs);
        
        if (isset($apiResponse['rows'])) {
            $allRows = $apiResponse['rows'];
            $debugInfo['stats']['api_rows'] = count($allRows);

            foreach ($allRows as $row) {
                $rowDateStr = $row[4] ?? '';   
                $rowTitle   = $row[6] ?? '';   
                $rowTopics  = $row[22] ?? '[]'; 
                $rowPartyCode = getPartyCode($row[21] ?? '[]');

                // FILTER 1: Datum
                if ($dStart || $dEnd) {
                    $rDate = DateTime::createFromFormat('d.m.Y', $rowDateStr);
                    if ($rDate) {
                        if ($dStart && $rDate < $dStart) continue;
                        if ($dEnd && $rDate > $dEnd) continue;
                    }
                }

                // FILTER 2: Partei (SERVER-SIDE)
                if (!in_array($rowPartyCode, $selectedParties)) {
                    continue;
                }

                // FILTER 3: Keywords
                if (!empty($keywords)) {
                    $blob = mb_strtolower($rowTitle . ' ' . $rowTopics);
                    $foundKeyword = false;
                    
                    foreach ($keywords as $kw) {
                        if (strpos($blob, $kw) !== false) {
                            $foundKeyword = true; 
                            break;
                        }
                    }
                    
                    if (!$foundKeyword) continue;
                }

                $answerInfo = extractAnswerInfo($rowTitle);
                $row['answer_info'] = $answerInfo;
                $row['party_code'] = $rowPartyCode;
                
                if ($answerInfo['answered']) {
                    $debugInfo['stats']['answered']++;
                } else {
                    $debugInfo['stats']['pending']++;
                }

                $allFilteredResults[] = $row;
            }
            
            usort($allFilteredResults, function($a, $b) {
                $dateA = DateTime::createFromFormat('d.m.Y', $a[4]);
                $dateB = DateTime::createFromFormat('d.m.Y', $b[4]);
                if ($dateA == $dateB) return 0;
                return ($dateA < $dateB) ? 1 : -1;
            });

            $debugInfo['stats']['total_before_pagination'] = count($allFilteredResults);

            $offset = ($page - 1) * $perPage;
            $displayResults = array_slice($allFilteredResults, $offset, $perPage);
            
            $debugInfo['stats']['matches'] = count($displayResults);

        } else {
            $error = "API Error: Keine Daten zurückbekommen.";
        }
    }
}

// Prepare visualization data
$vizData = [];
if (!empty($allFilteredResults)) {
    foreach ($allFilteredResults as $row) {
        $dateStr = $row[4] ?? '';
        $partyCode = $row['party_code'];
        
        $date = DateTime::createFromFormat('d.m.Y', $dateStr);
        if ($date) {
            $vizData[] = [
                'date' => $date->format('Y-m-d'),
                'year' => $date->format('Y'),
                'month' => $date->format('m'),
                'party' => $partyCode,
                'timestamp' => $date->getTimestamp()
            ];
        }
    }
}

// ==========================================
// DASHBOARD DATA - INDEPENDENT OF SEARCH
// ==========================================
$dashboardData = [];
$now = new DateTime();
$fourMonthsAgo = (clone $now)->modify('-4 months');

// Fetch last 4 months for dashboard (always, regardless of search)
$dashGPs = ['XXVII', 'XXVIII']; // Current periods
$dashApiResponse = fetchAllRows($dashGPs);

if (isset($dashApiResponse['rows'])) {
    $dashStats = [
        'total' => 0,
        'answered' => 0,
        'pending' => 0,
        'by_party' => ['S' => 0, 'V' => 0, 'F' => 0, 'G' => 0, 'N' => 0],
        'by_month' => [],
        'top_topics' => [],
        'recent_items' => []
    ];
    
    foreach ($dashApiResponse['rows'] as $row) {
        $dateStr = $row[4] ?? '';
        $date = DateTime::createFromFormat('d.m.Y', $dateStr);
        
        // Only include last 4 months
        if (!$date || $date < $fourMonthsAgo) continue;
        
        $rowTitle = $row[6] ?? '';
        $partyCode = getPartyCode($row[21] ?? '[]');
        
        $dashStats['total']++;
        
        if (isset($dashStats['by_party'][$partyCode])) {
            $dashStats['by_party'][$partyCode]++;
        }
        
        $answerInfo = extractAnswerInfo($rowTitle);
        if ($answerInfo['answered']) {
            $dashStats['answered']++;
        } else {
            $dashStats['pending']++;
        }
        
        $monthKey = $date->format('Y-m');
        if (!isset($dashStats['by_month'][$monthKey])) {
            $dashStats['by_month'][$monthKey] = [
                'label' => $date->format('M Y'),
                'S' => 0, 'V' => 0, 'F' => 0, 'G' => 0, 'N' => 0,
                'total' => 0
            ];
        }
        $dashStats['by_month'][$monthKey][$partyCode]++;
        $dashStats['by_month'][$monthKey]['total']++;
        
        // Topics
        $topicsRaw = json_decode($row[22] ?? '[]', true);
        if (is_array($topicsRaw)) {
            foreach ($topicsRaw as $topic) {
                if (!isset($dashStats['top_topics'][$topic])) {
                    $dashStats['top_topics'][$topic] = 0;
                }
                $dashStats['top_topics'][$topic]++;
            }
        }
        
        // Collect ALL items first (no limit yet!)
        $dashStats['recent_items'][] = [
            'date' => $dateStr,
            'date_obj' => $date,
            'party' => $partyCode,
            'title' => mb_substr($rowTitle, 0, 150),
            'answered' => $answerInfo['answered'],
            'link' => "https://www.parlament.gv.at" . ($row[14] ?? ''),
            'number' => $row[7] ?? ''
        ];
    }
    
    // NOW sort ALL items by date (newest first)
    usort($dashStats['recent_items'], function($a, $b) {
        return $b['date_obj'] <=> $a['date_obj'];
    });
    
    // THEN limit to 100 most recent
    $dashStats['recent_items'] = array_slice($dashStats['recent_items'], 0, 100);
    
    // Sort and limit
    ksort($dashStats['by_month']);
    arsort($dashStats['top_topics']);
    $dashStats['top_topics'] = array_slice($dashStats['top_topics'], 0, 10, true);
    
    $dashboardData = $dashStats;
}

$jsonDebugPayload = ['metadata' => $debugInfo, 'results' => $displayResults];
?>

<!DOCTYPE html>
<html lang="de" class="antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Anfragen Transparent – Parlamentarische Anfragen durchsuchen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #F8FAFC;
            overflow-x: hidden;
        }
        .glass-header {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #64748b; }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-down {
            animation: fadeInDown 0.3s ease-out;
        }
        
        @media (max-width: 640px) {
            .result-card { padding: 1rem; }
        }
        
        .toggle-wrapper { min-height: 2.5rem; }
        
        #viz-canvas {
            width: 100%;
            height: 100%;
            display: block;
            touch-action: none;
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
        }
        
        #viz-canvas:active {
            cursor: grabbing;
        }

        .answer-badge {
            animation: pulse-green 2s ease-in-out infinite;
        }
        
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        }
        
        /* Custom scrollbar for recent activity */
        .activity-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .activity-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .activity-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .activity-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
    <script>
        const VIZ_DATA = <?php echo json_encode($vizData); ?>;
        const SELECTED_PARTIES = <?php echo json_encode($selectedParties); ?>;
        const DASHBOARD_DATA = <?php echo json_encode($dashboardData); ?>;
        
        function updatePartyCheckboxes() {
            const checkboxes = document.querySelectorAll('input[name="parties[]"]');
            const allCheckbox = document.querySelector('input[onclick*="toggleAll"]');
            
            let checkedCount = 0;
            checkboxes.forEach((cb) => {
                if(cb.checked) checkedCount++;
            });
            
            if (allCheckbox) {
                allCheckbox.checked = (checkedCount === checkboxes.length);
            }
            
            updateResultCounter();
        }
        
        function updateResultCounter() {
            const cards = document.querySelectorAll('.result-card');
            const counterEl = document.getElementById('result-counter');
            if(counterEl && cards.length > 0) {
                counterEl.innerHTML = `<span class="font-bold text-slate-900">${cards.length}</span> Ergebnisse`;
            }
        }

        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('input[name="parties[]"]');
            for(let i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = source.checked;
            }
        }

        function clearDateFilter() {
            const form = document.querySelector('form');
            const fromInput = form.querySelector('input[name="viz_date_from"]');
            const toInput = form.querySelector('input[name="viz_date_to"]');
            
            if (fromInput) fromInput.value = '';
            if (toInput) toInput.value = '';
            
            form.submit();
        }

        function toggleDebug() {
            document.getElementById('debug-console').classList.toggle('hidden');
        }
        
        function copyDebug() {
            const content = document.getElementById('debug-content').innerText;
            navigator.clipboard.writeText(content);
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = '✓ Kopiert';
            setTimeout(() => { btn.textContent = originalText; }, 1500);
        }

        function openDashboard() {
            document.getElementById('dashboard-modal').classList.remove('hidden');
        }

        function closeDashboard() {
            document.getElementById('dashboard-modal').classList.add('hidden');
        }

        function openInfoModal() {
            document.getElementById('info-modal').classList.remove('hidden');
        }

        function closeInfoModal() {
            document.getElementById('info-modal').classList.add('hidden');
        }

        function openVisualization() {
            document.getElementById('viz-modal').classList.remove('hidden');
            setTimeout(() => initVisualization(), 100);
        }

        function closeVisualization() {
            document.getElementById('viz-modal').classList.add('hidden');
            if (window.vizScene) {
                if (window.vizScene.cleanup) {
                    window.vizScene.cleanup();
                }
                window.vizScene = null;
            }
        }

        function initVisualization() {
            const container = document.getElementById('viz-canvas');
            const width = container.clientWidth;
            const height = container.clientHeight;

            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0x0a0e1a);
            scene.fog = new THREE.Fog(0x0a0e1a, 80, 200);

            const camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1000);
            camera.position.set(0, 45, 85);
            camera.lookAt(0, 0, 0);

            const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            renderer.setSize(width, height);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            container.appendChild(renderer.domElement);

            // Beleuchtung
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
            scene.add(ambientLight);

            const topLight = new THREE.DirectionalLight(0xffffff, 0.8);
            topLight.position.set(0, 50, 0);
            scene.add(topLight);

            const partyColors = {
                'S': 0xef4444,
                'V': 0x0f766e,
                'F': 0x3b82f6,
                'G': 0x22c55e,
                'N': 0xec4899,
                'OTHER': 0x64748b
            };

            const partyNames = {
                'S': 'SPÖ',
                'V': 'ÖVP',
                'F': 'FPÖ',
                'G': 'GRÜNE',
                'N': 'NEOS',
                'OTHER': 'Andere'
            };

            // Daten vorbereiten - gruppiert nach Monat und Partei
            const monthlyData = {};
            VIZ_DATA.forEach(item => {
                const key = `${item.year}-${item.month}`;
                if (!monthlyData[key]) {
                    monthlyData[key] = { 
                        S: 0, V: 0, F: 0, G: 0, N: 0, OTHER: 0, 
                        timestamp: item.timestamp, 
                        year: item.year, 
                        month: item.month 
                    };
                }
                monthlyData[key][item.party]++;
            });

            const months = Object.keys(monthlyData).sort();
            const parties = ['S', 'V', 'F', 'G', 'N'];
            
            // Finde Maximum für Skalierung
            const maxCount = Math.max(...months.flatMap(m => 
                parties.map(p => monthlyData[m][p])
            ));

            // Grid-Parameter
            const cubeSize = 2.5;
            const spacing = 0.3;
            const cellSize = cubeSize + spacing;
            
            const gridWidth = months.length * cellSize;
            const gridDepth = parties.length * cellSize;
            const startX = -gridWidth / 2 + cubeSize / 2;
            const startZ = -gridDepth / 2 + cubeSize / 2;

            const clickableCubes = [];
            const gridLabels = [];

            // **HAUPT-GRID: Würfel für jede Kombination Monat/Partei**
            months.forEach((month, monthIndex) => {
                const data = monthlyData[month];
                
                parties.forEach((party, partyIndex) => {
                    const count = data[party];
                    if (count === 0) return;

                    const x = startX + (monthIndex * cellSize);
                    const z = startZ + (partyIndex * cellSize);
                    const height = Math.max(0.5, (count / maxCount) * 30);

                    // Würfel mit abgerundeten Ecken-Effekt (durch Bevel)
                    const geometry = new THREE.BoxGeometry(cubeSize, height, cubeSize);
                    const material = new THREE.MeshStandardMaterial({
                        color: partyColors[party],
                        emissive: partyColors[party],
                        emissiveIntensity: 0.3,
                        metalness: 0.3,
                        roughness: 0.4
                    });
                    
                    const cube = new THREE.Mesh(geometry, material);
                    cube.position.set(x, height / 2, z);
                    cube.castShadow = true;
                    cube.receiveShadow = true;
                    
                    cube.userData = {
                        party: party,
                        partyName: partyNames[party],
                        yearMonth: month,
                        year: data.year,
                        month: data.month,
                        count: count,
                        monthIndex: monthIndex,
                        partyIndex: partyIndex
                    };
                    
                    scene.add(cube);
                    clickableCubes.push(cube);
                });
            });

            // **ZEIT-LABELS (X-Achse - Monate)**
            const monthNames = ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
            
            months.forEach((month, i) => {
                const data = monthlyData[month];
                const x = startX + (i * cellSize);
                
                // Nur bei jedem 3. Monat oder Januar ein Label
                const isJanuary = data.month === '01';
                const isEvery3rd = i % 3 === 0;
                const isFirst = i === 0;
                const isLast = i === months.length - 1;
                
                if (isFirst || isLast || isJanuary || (isEvery3rd && months.length < 40)) {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = 256;
                    canvas.height = 128;
                    
                    ctx.fillStyle = '#94a3b8';
                    ctx.font = 'bold 56px Inter, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    
                    if (isJanuary || isFirst || isLast) {
                        ctx.fillText(data.year, 128, 50);
                        ctx.font = '36px Inter, sans-serif';
                        ctx.fillText(monthNames[parseInt(data.month)], 128, 100);
                    } else {
                        ctx.fillText(monthNames[parseInt(data.month)], 128, 64);
                    }
                    
                    const texture = new THREE.CanvasTexture(canvas);
                    const spriteMaterial = new THREE.SpriteMaterial({ 
                        map: texture, 
                        transparent: true,
                        opacity: 0.9
                    });
                    const sprite = new THREE.Sprite(spriteMaterial);
                    sprite.position.set(x, -4, gridDepth/2 + 8);
                    sprite.scale.set(6, 3, 1);
                    scene.add(sprite);
                    gridLabels.push(sprite);
                }
            });

            // **PARTEI-LABELS (Z-Achse)**
            parties.forEach((party, i) => {
                const z = startZ + (i * cellSize);
                
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = 256;
                canvas.height = 128;
                
                // Parteifarbe als Hintergrund-Highlight
                ctx.fillStyle = '#' + partyColors[party].toString(16).padStart(6, '0');
                ctx.globalAlpha = 0.15;
                ctx.fillRect(0, 0, 256, 128);
                ctx.globalAlpha = 1.0;
                
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 64px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(partyNames[party], 128, 64);
                
                const texture = new THREE.CanvasTexture(canvas);
                const spriteMaterial = new THREE.SpriteMaterial({ 
                    map: texture, 
                    transparent: true
                });
                const sprite = new THREE.Sprite(spriteMaterial);
                sprite.position.set(-gridWidth/2 - 8, 2, z);
                sprite.scale.set(6, 3, 1);
                scene.add(sprite);
                gridLabels.push(sprite);
            });

            // **BODEN-GRID für Orientierung**
            const gridSize = Math.max(gridWidth, gridDepth) * 1.5;
            const gridDivisions = 30;
            const gridHelper = new THREE.GridHelper(gridSize, gridDivisions, 0x1e293b, 0x0f172a);
            gridHelper.position.y = -0.1;
            scene.add(gridHelper);

            // **INTERAKTION**
            const raycaster = new THREE.Raycaster();
            const mouse = new THREE.Vector2();
            let hoveredCube = null;

            let isDragging = false;
            let hasMoved = false;
            let previousMousePosition = { x: 0, y: 0 };
            let cameraRotation = { theta: 0, phi: Math.PI / 4.5 };
            let cameraDistance = 85;
            const target = new THREE.Vector3(0, 5, 0);

            function updateCameraPosition() {
                camera.position.x = target.x + cameraDistance * Math.sin(cameraRotation.theta) * Math.cos(cameraRotation.phi);
                camera.position.y = target.y + cameraDistance * Math.sin(cameraRotation.phi);
                camera.position.z = target.z + cameraDistance * Math.cos(cameraRotation.theta) * Math.cos(cameraRotation.phi);
                camera.lookAt(target);
            }

            function handleClick(clientX, clientY) {
                if (hasMoved) return;

                const rect = renderer.domElement.getBoundingClientRect();
                mouse.x = ((clientX - rect.left) / rect.width) * 2 - 1;
                mouse.y = -((clientY - rect.top) / rect.height) * 2 + 1;

                raycaster.setFromCamera(mouse, camera);
                const intersects = raycaster.intersectObjects(clickableCubes);

                if (intersects.length > 0) {
                    const cube = intersects[0].object;
                    const data = cube.userData;
                    
                    // Flash-Effekt
                    cube.material.emissiveIntensity = 1.2;
                    setTimeout(() => { cube.material.emissiveIntensity = 0.3; }, 150);
                    
                    const currentUrl = new URL(window.location.href);
                    const year = parseInt(data.year);
                    const month = parseInt(data.month);
                    const firstDay = `01.${data.month}.${data.year}`;
                    const lastDayNum = new Date(year, month, 0).getDate();
                    const lastDay = `${String(lastDayNum).padStart(2, '0')}.${data.month}.${data.year}`;
                    
                    const newUrl = new URL(window.location.origin + window.location.pathname);
                    const originalQuery = currentUrl.searchParams.get('q') || '';
                    newUrl.searchParams.set('q', originalQuery);
                    newUrl.searchParams.set('parties[]', data.party);
                    
                    const gpFrom = currentUrl.searchParams.get('gp_from') || 'XXVII';
                    const gpTo = currentUrl.searchParams.get('gp_to') || 'XXVIII';
                    newUrl.searchParams.set('gp_from', gpFrom);
                    newUrl.searchParams.set('gp_to', gpTo);
                    
                    if (currentUrl.searchParams.get('exact') === '1') {
                        newUrl.searchParams.set('exact', '1');
                    }
                    
                    newUrl.searchParams.set('viz_date_from', firstDay);
                    newUrl.searchParams.set('viz_date_to', lastDay);
                    
                    const monthNamesShort = ['', 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
                    const monthName = monthNamesShort[month];
                    
                    const toast = document.createElement('div');
                    toast.className = 'fixed top-4 left-1/2 transform -translate-x-1/2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl shadow-2xl z-[60] animate-fade-in-down font-medium';
                    toast.innerHTML = `
                        <div class="flex items-center gap-3">
                            <div class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                            <div>
                                <div class="font-bold">${data.partyName} • ${monthName} ${year}</div>
                                <div class="text-xs text-white/90 mt-0.5">${data.count} Anfragen</div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(toast);
                    
                    setTimeout(() => {
                        window.location.href = newUrl.toString();
                    }, 350);
                }
            }

            // Mouse Events
            renderer.domElement.addEventListener('mousedown', (e) => {
                isDragging = true;
                hasMoved = false;
                previousMousePosition = { x: e.clientX, y: e.clientY };
            });

            renderer.domElement.addEventListener('mousemove', (e) => {
                if (isDragging) {
                    const deltaX = e.clientX - previousMousePosition.x;
                    const deltaY = e.clientY - previousMousePosition.y;
                    
                    if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) {
                        hasMoved = true;
                    }
                    
                    cameraRotation.theta += deltaX * 0.005;
                    cameraRotation.phi += deltaY * 0.005;
                    cameraRotation.phi = Math.max(0.1, Math.min(Math.PI / 2 - 0.1, cameraRotation.phi));
                    
                    previousMousePosition = { x: e.clientX, y: e.clientY };
                    updateCameraPosition();
                } else {
                    // Hover-Effekt
                    const rect = renderer.domElement.getBoundingClientRect();
                    mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                    mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

                    raycaster.setFromCamera(mouse, camera);
                    const intersects = raycaster.intersectObjects(clickableCubes);

                    if (hoveredCube && hoveredCube !== (intersects[0] ? intersects[0].object : null)) {
                        hoveredCube.material.emissiveIntensity = 0.3;
                        hoveredCube.scale.set(1, 1, 1);
                    }

                    if (intersects.length > 0) {
                        hoveredCube = intersects[0].object;
                        hoveredCube.material.emissiveIntensity = 0.8;
                        hoveredCube.scale.set(1.1, 1.05, 1.1);
                        renderer.domElement.style.cursor = 'pointer';
                    } else {
                        hoveredCube = null;
                        renderer.domElement.style.cursor = 'grab';
                    }
                }
            });

            renderer.domElement.addEventListener('mouseup', (e) => {
                if (isDragging && !hasMoved) {
                    handleClick(e.clientX, e.clientY);
                }
                isDragging = false;
            });

            renderer.domElement.addEventListener('mouseleave', () => {
                isDragging = false;
                if (hoveredCube) {
                    hoveredCube.material.emissiveIntensity = 0.3;
                    hoveredCube.scale.set(1, 1, 1);
                    hoveredCube = null;
                }
            });

            renderer.domElement.addEventListener('wheel', (e) => {
                e.preventDefault();
                cameraDistance += e.deltaY * 0.08;
                cameraDistance = Math.max(30, Math.min(150, cameraDistance));
                updateCameraPosition();
            }, { passive: false });

            // Touch Events
            let touchStartX = 0, touchStartY = 0, lastTouchX = 0, lastTouchY = 0;

            renderer.domElement.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1) {
                    isDragging = true;
                    hasMoved = false;
                    touchStartX = lastTouchX = e.touches[0].clientX;
                    touchStartY = lastTouchY = e.touches[0].clientY;
                    e.preventDefault();
                }
            }, { passive: false });

            renderer.domElement.addEventListener('touchmove', (e) => {
                if (e.touches.length === 1 && isDragging) {
                    const touch = e.touches[0];
                    const deltaX = touch.clientX - lastTouchX;
                    const deltaY = touch.clientY - lastTouchY;
                    
                    if (Math.abs(touch.clientX - touchStartX) > 5 || Math.abs(touch.clientY - touchStartY) > 5) {
                        hasMoved = true;
                    }
                    
                    cameraRotation.theta += deltaX * 0.008;
                    cameraRotation.phi += deltaY * 0.008;
                    cameraRotation.phi = Math.max(0.1, Math.min(Math.PI / 2 - 0.1, cameraRotation.phi));
                    
                    lastTouchX = touch.clientX;
                    lastTouchY = touch.clientY;
                    updateCameraPosition();
                    e.preventDefault();
                } else if (e.touches.length === 2) {
                    // Pinch-to-Zoom
                    const dx = e.touches[0].clientX - e.touches[1].clientX;
                    const dy = e.touches[0].clientY - e.touches[1].clientY;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    
                    if (window.lastPinchDistance > 0) {
                        const delta = distance - window.lastPinchDistance;
                        cameraDistance -= delta * 0.2;
                        cameraDistance = Math.max(30, Math.min(150, cameraDistance));
                        updateCameraPosition();
                    }
                    window.lastPinchDistance = distance;
                    e.preventDefault();
                }
            }, { passive: false });

            renderer.domElement.addEventListener('touchend', (e) => {
                if (isDragging && !hasMoved && e.changedTouches.length > 0) {
                    handleClick(e.changedTouches[0].clientX, e.changedTouches[0].clientY);
                }
                isDragging = false;
                if (e.touches.length < 2) {
                    window.lastPinchDistance = 0;
                }
            });

            // Animation Loop
            function animate() {
                requestAnimationFrame(animate);
                renderer.render(scene, camera);
            }
            animate();

            // Resize Handler
            const resizeHandler = () => {
                const w = container.clientWidth;
                const h = container.clientHeight;
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
                renderer.setSize(w, h);
            };
            window.addEventListener('resize', resizeHandler);

            window.vizScene = { 
                scene, 
                camera, 
                renderer, 
                cleanup: () => {
                    window.removeEventListener('resize', resizeHandler);
                    renderer.dispose();
                }
            };
        }

        window.onload = function() { 
            updatePartyCheckboxes();
            updateResultCounter();
        };
    </script>
</head>
<body class="text-slate-800 pb-24">

    <div class="glass-header border-b border-slate-200/60 sticky top-0 z-20 shadow-sm">
        <div class="max-w-5xl mx-auto px-3 sm:px-4 py-4 sm:py-6">
            
            <div class="flex items-start justify-between gap-3 mb-4 sm:mb-5">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl sm:text-2xl md:text-3xl font-bold tracking-tight text-slate-900 leading-tight">
                            Anfragen <span class="text-indigo-600">Transparent</span>
                        </h1>
                        <button onclick="openInfoModal()" class="flex-shrink-0 p-1.5 sm:p-2 rounded-full bg-indigo-50 hover:bg-indigo-100 text-indigo-600 transition-colors group" title="Was sind parlamentarische Anfragen?">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </button>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1 line-clamp-1">Alle parlamentarischen Anfragen – einfach durchsuchbar</p>
                </div>
                
                <div class="flex gap-2">
                    <!-- Dashboard Button - ALWAYS visible -->
                    <button onclick="openDashboard()" class="flex items-center gap-2 text-xs bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white px-3 sm:px-4 py-2 sm:py-2.5 rounded-full font-bold shadow-lg shadow-emerald-200 transition-all hover:scale-105 active:scale-95 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        <span class="hidden sm:inline">Statistiken</span>
                        <span class="sm:hidden">Stats</span>
                    </button>
                    
                    <!-- 3D Grid Button - Only when search results exist -->
                    <?php if ($searchQuery && !empty($allFilteredResults)): ?>
                    <button onclick="openVisualization()" class="flex items-center gap-2 text-xs bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-3 sm:px-4 py-2 sm:py-2.5 rounded-full font-bold shadow-lg shadow-indigo-200 transition-all hover:scale-105 active:scale-95 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"></path></svg>
                        <span class="hidden sm:inline">3D Ansicht</span>
                        <span class="sm:hidden">3D</span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if (!$searchQuery || empty($allFilteredResults)): ?>
                <div id="result-counter" class="hidden sm:flex text-xs text-slate-500 bg-slate-100 px-2.5 py-1.5 rounded-full whitespace-nowrap shrink-0">
                    Bereit
                </div>
                <?php endif; ?>
            </div>
            
            <form method="GET" class="space-y-3 sm:space-y-4">
                
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1 group">
                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none z-10">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-slate-400 group-focus-within:text-indigo-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               class="block w-full pl-10 sm:pl-12 pr-20 sm:pr-24 py-3 sm:py-3.5 bg-white border border-slate-200 rounded-xl sm:rounded-2xl text-base sm:text-lg placeholder-slate-400 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all" 
                               placeholder="z.B. Gesundheit, Klimaschutz, Verkehr..." autofocus autocomplete="off">
                        
                        <button type="submit" class="absolute right-1.5 top-1.5 bottom-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 sm:px-6 rounded-lg sm:rounded-xl text-sm sm:text-base font-medium transition-colors shadow-sm">
                            Go
                        </button>
                    </div>

                    <div class="flex items-center bg-white border border-slate-200 rounded-xl px-3 py-2 shadow-sm shrink-0 w-full sm:w-auto justify-between sm:justify-start">
                        <label class="relative inline-flex items-center cursor-pointer w-full sm:w-auto">
                            <input type="checkbox" name="exact" value="1" class="sr-only peer" <?php echo $isExactMode ? 'checked' : ''; ?>>
                            <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600 shrink-0"></div>
                            <span class="ml-2.5 text-xs sm:text-sm font-medium text-slate-600">Exakte Suche</span>
                        </label>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                    <span class="text-[10px] sm:text-xs font-bold text-slate-400 uppercase tracking-wider shrink-0">Zeitraum</span>
                    <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                        <div class="flex items-center gap-2">
                            <select name="gp_from" class="text-xs sm:text-sm bg-white border border-slate-200 rounded-lg px-2 sm:px-3 py-1.5 sm:py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-sm">
                                <?php foreach($availableGPs as $gp => $period): ?>
                                    <option value="<?php echo $gp; ?>" <?php echo $gpFrom === $gp ? 'selected' : ''; ?>>
                                        <?php echo $gp; ?>. GP (<?php echo $period; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-slate-400 text-sm">bis</span>
                            <select name="gp_to" class="text-xs sm:text-sm bg-white border border-slate-200 rounded-lg px-2 sm:px-3 py-1.5 sm:py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-sm">
                                <?php foreach($availableGPs as $gp => $period): ?>
                                    <option value="<?php echo $gp; ?>" <?php echo $gpTo === $gp ? 'selected' : ''; ?>>
                                        <?php echo $gp; ?>. GP (<?php echo $period; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                    <span class="text-[10px] sm:text-xs font-bold text-slate-400 uppercase tracking-wider shrink-0">Datum (Optional)</span>
                    <div class="flex flex-wrap gap-2 w-full sm:w-auto items-center">
                        <input type="text" name="viz_date_from" 
                               value="<?php echo htmlspecialchars($_GET['viz_date_from'] ?? ''); ?>" 
                               placeholder="TT.MM.JJJJ"
                               class="text-xs sm:text-sm bg-white border border-slate-200 rounded-lg px-2 sm:px-3 py-1.5 sm:py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-sm w-28 sm:w-32"
                               pattern="\d{2}\.\d{2}\.\d{4}">
                        <span class="text-slate-400 text-xs sm:text-sm">bis</span>
                        <input type="text" name="viz_date_to" 
                               value="<?php echo htmlspecialchars($_GET['viz_date_to'] ?? ''); ?>" 
                               placeholder="TT.MM.JJJJ"
                               class="text-xs sm:text-sm bg-white border border-slate-200 rounded-lg px-2 sm:px-3 py-1.5 sm:py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 shadow-sm w-28 sm:w-32"
                               pattern="\d{2}\.\d{2}\.\d{4}">
                        <?php if (!empty($_GET['viz_date_from']) || !empty($_GET['viz_date_to'])): ?>
                            <button type="button" onclick="clearDateFilter()" 
                                    class="text-xs text-slate-400 hover:text-red-600 transition-colors p-1" title="Datumsfilter entfernen">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="toggle-wrapper">
                    <div class="text-[10px] sm:text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Fraktionen</div>
                    
                    <div class="flex flex-wrap gap-1.5 sm:gap-2">
                        <label class="cursor-pointer select-none">
                            <input type="checkbox" onClick="toggleAll(this)" <?php echo (count($selectedParties) === 5) ? 'checked' : ''; ?> class="peer sr-only">
                            <div class="px-2.5 sm:px-3.5 py-1 sm:py-1.5 rounded-full text-xs sm:text-sm font-medium border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 peer-checked:bg-slate-800 peer-checked:text-white peer-checked:border-slate-800 transition-all shadow-sm active:scale-95">
                                Alle
                            </div>
                        </label>

                        <?php 
                        $parties = [
                            'S' => ['label' => 'SPÖ', 'color' => 'peer-checked:bg-red-600 peer-checked:border-red-600'], 
                            'V' => ['label' => 'ÖVP', 'color' => 'peer-checked:bg-teal-950 peer-checked:border-teal-950'], 
                            'F' => ['label' => 'FPÖ', 'color' => 'peer-checked:bg-blue-600 peer-checked:border-blue-600'], 
                            'G' => ['label' => 'GRÜNE', 'color' => 'peer-checked:bg-green-600 peer-checked:border-green-600'], 
                            'N' => ['label' => 'NEOS', 'color' => 'peer-checked:bg-pink-500 peer-checked:border-pink-500']
                        ];
                        foreach($parties as $code => $data): 
                            $isChecked = in_array($code, $selectedParties) ? 'checked' : '';
                        ?>
                        <label class="cursor-pointer select-none">
                            <input type="checkbox" name="parties[]" value="<?php echo $code; ?>" <?php echo $isChecked; ?> class="peer sr-only">
                            <div class="px-2.5 sm:px-3.5 py-1 sm:py-1.5 rounded-full text-xs sm:text-sm font-medium border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 peer-checked:text-white <?php echo $data['color']; ?> transition-all shadow-sm active:scale-95">
                                <?php echo $data['label']; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-3 sm:px-4 mt-4 sm:mt-6">

        <?php if ($searchQuery && !empty($debugInfo['search_keywords'])): ?>
            <?php if(isset($_GET['viz_date_from']) || isset($_GET['viz_date_to'])): ?>
                <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-purple-50/50 rounded-xl sm:rounded-2xl border border-purple-100 flex items-start gap-2 sm:gap-3 animate-fade-in-down">
                    <div class="mt-0.5 sm:mt-1 p-1 bg-purple-100 rounded text-purple-600 shrink-0">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] sm:text-xs font-bold text-purple-400 uppercase tracking-wide">Filter aus 3D Ansicht aktiv</span>
                        <div class="flex flex-wrap gap-1.5 sm:gap-2 mt-1 items-center">
                            <?php if (!empty($_GET['viz_date_from'])): ?>
                                <span class="text-xs sm:text-sm text-purple-900 bg-white px-2 py-0.5 rounded border border-purple-100 shadow-sm">
                                    📅 Ab <?php echo htmlspecialchars($_GET['viz_date_from']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($_GET['viz_date_to'])): ?>
                                <span class="text-xs sm:text-sm text-purple-900 bg-white px-2 py-0.5 rounded border border-purple-100 shadow-sm">
                                    📅 Bis <?php echo htmlspecialchars($_GET['viz_date_to']); ?>
                                </span>
                            <?php endif; ?>
                            <?php 
                            $partyCodeMap = ['S' => 'SPÖ', 'V' => 'ÖVP', 'F' => 'FPÖ', 'G' => 'GRÜNE', 'N' => 'NEOS'];
                            foreach($selectedParties as $partyCode):
                                if (isset($partyCodeMap[$partyCode])): 
                            ?>
                                <span class="text-xs sm:text-sm text-purple-900 bg-white px-2 py-0.5 rounded border border-purple-100 shadow-sm">
                                    🏛️ <?php echo $partyCodeMap[$partyCode]; ?>
                                </span>
                            <?php 
                                endif;
                            endforeach;
                            ?>
                            <a href="?q=<?php echo urlencode($searchQuery); ?>&gp_from=<?php echo $gpFrom; ?>&gp_to=<?php echo $gpTo; ?><?php echo $isExactMode ? '&exact=1' : ''; ?>&parties[]=S&parties[]=V&parties[]=F&parties[]=G&parties[]=N" 
                               class="text-xs text-purple-600 hover:text-purple-800 underline ml-2 font-medium">
                                Filter zurücksetzen
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif($isExactMode): ?>
                <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-slate-100 rounded-xl sm:rounded-2xl border border-slate-200 flex items-start gap-2 sm:gap-3 animate-fade-in-down">
                    <div class="mt-0.5 sm:mt-1 p-1 bg-white rounded text-slate-600 shadow-sm shrink-0">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] sm:text-xs font-bold text-slate-500 uppercase tracking-wide">Exakte Suche</span>
                        <div class="flex flex-wrap gap-1.5 sm:gap-2 mt-1">
                            <span class="text-xs sm:text-sm font-mono text-slate-800 bg-white px-2 py-0.5 rounded border border-slate-200 break-all">
                                "<?php echo htmlspecialchars($searchQuery); ?>"
                            </span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-indigo-50/50 rounded-xl sm:rounded-2xl border border-indigo-100 flex items-start gap-2 sm:gap-3 animate-fade-in-down">
                    <div class="mt-0.5 sm:mt-1 p-1 bg-indigo-100 rounded text-indigo-600 shrink-0">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] sm:text-xs font-bold text-indigo-400 uppercase tracking-wide">Intelligente Suche aktiviert</span>
                        <div class="flex flex-wrap gap-1.5 sm:gap-2 mt-1">
                            <?php foreach($debugInfo['search_keywords'] as $kw): ?>
                                <span class="text-xs sm:text-sm text-indigo-900 bg-white px-2 py-0.5 rounded border border-indigo-100 shadow-sm break-all">
                                    <?php echo htmlspecialchars($kw); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-indigo-600 mt-1.5">Die KI sucht automatisch nach verwandten Begriffen und Synonymen</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($searchQuery && !empty($displayResults)): ?>
            <div class="mb-4 text-sm text-slate-500 flex items-center justify-between flex-wrap gap-2">
                <span>
                    Zeige <?php echo count($displayResults); ?> von 
                    <span class="font-bold text-slate-700"><?php echo $debugInfo['stats']['total_before_pagination']; ?></span> Ergebnissen
                    <span class="text-slate-400">(Seite <?php echo $page; ?>)</span>
                </span>
                <div class="flex gap-3 text-xs">
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        <span class="font-medium text-green-700"><?php echo $debugInfo['stats']['answered']; ?> beantwortet</span>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        <span class="font-medium text-amber-700"><?php echo $debugInfo['stats']['pending']; ?> offen</span>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-3 sm:p-4 rounded-xl border border-red-100 font-medium mb-4 sm:mb-6 flex items-center gap-2 sm:gap-3 text-sm">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($searchQuery && empty($displayResults) && !$error): ?>
            <div class="text-center py-16 sm:py-24">
                <div class="inline-block p-3 sm:p-4 rounded-full bg-slate-100 mb-3 sm:mb-4">
                    <svg class="w-6 h-6 sm:w-8 sm:h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <h3 class="text-base sm:text-lg font-medium text-slate-900">Keine Anfragen gefunden</h3>
                <p class="text-sm text-slate-500 mt-1 px-4">
                    <?php 
                    if (!empty($_GET['viz_date_from']) || !empty($_GET['viz_date_to'])) {
                        echo 'Versuchen Sie einen anderen Zeitraum oder entfernen Sie den Datumsfilter';
                    } else {
                        echo $isExactMode ? 'Deaktivieren Sie die "Exakte Suche" für bessere Ergebnisse' : 'Versuchen Sie andere Suchbegriffe oder prüfen Sie die Filter';
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="space-y-3 sm:space-y-4" id="results-container">
            <?php foreach ($displayResults as $row): 
                $datum = $row[4]; 
                $titel = $row[6]; 
                $zahl = $row[7];
                $gpCode = $row[0];
                $inr = $row[2];
                $link = "https://www.parlament.gv.at" . ($row[14] ?? '');
                $partyCode = $row['party_code'];
                $parteiRaw = json_decode($row[21] ?? '[]', true); 
                $partei = implode(', ', $parteiRaw);
                $themenRaw = json_decode($row[22] ?? '[]', true); 
                $themen = array_slice($themenRaw, 0, 5);
                
                $answerInfo = $row['answer_info'];
                $isAnswered = $answerInfo['answered'];
                $answerNumber = $answerInfo['answer_number'];

                $partyStyle = match($partyCode) {
                    'S' => ['bg' => 'bg-red-50 text-red-700 border-red-100', 'dot' => 'bg-red-600'],
                    'V' => ['bg' => 'bg-teal-50 text-teal-800 border-teal-100', 'dot' => 'bg-teal-950'],
                    'F' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-100', 'dot' => 'bg-blue-600'],
                    'G' => ['bg' => 'bg-green-50 text-green-700 border-green-100', 'dot' => 'bg-green-600'],
                    'N' => ['bg' => 'bg-pink-50 text-pink-700 border-pink-100', 'dot' => 'bg-pink-500'],
                    default => ['bg' => 'bg-slate-50 text-slate-600 border-slate-200', 'dot' => 'bg-slate-400']
                };
            ?>
            <div class="result-card group block bg-white rounded-xl sm:rounded-2xl p-4 sm:p-6 border border-slate-200 hover:border-indigo-300 hover:shadow-lg transition-all duration-200"
                 data-party="<?php echo $partyCode; ?>">
                
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-2 sm:gap-4 mb-3">
                    <div class="flex items-center gap-2 text-[10px] sm:text-xs font-semibold uppercase tracking-wide flex-wrap">
                        <span class="text-slate-400 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <?php echo $datum; ?>
                        </span>
                        <a href="<?php echo $link; ?>" target="_blank" class="text-indigo-600 bg-indigo-50 px-1.5 sm:px-2 py-0.5 rounded border border-indigo-100 hover:bg-indigo-100 transition-colors">
                            <?php echo $zahl; ?>
                        </a>
                        
                        <?php if ($isAnswered): ?>
                            <a href="https://www.parlament.gv.at/gegenstand/<?php echo $gpCode; ?>/AB/<?php echo $answerNumber; ?>" 
                               target="_blank"
                               class="answer-badge inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 transition-all font-bold">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span>Beantwortet</span>
                                <span class="text-[9px] opacity-75">(<?php echo $answerNumber; ?>/AB)</span>
                            </a>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-medium">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span>Offen</span>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if($partei): ?>
                        <span class="inline-flex items-center gap-1 sm:gap-1.5 px-2 sm:px-2.5 py-1 rounded-full text-[10px] sm:text-xs font-bold border <?php echo $partyStyle['bg']; ?> self-start">
                            <span class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full <?php echo $partyStyle['dot']; ?>"></span>
                            <?php echo $partei; ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <a href="<?php echo $link; ?>" target="_blank" class="block">
                    <h2 class="text-base sm:text-lg md:text-xl font-semibold text-slate-800 group-hover:text-indigo-700 leading-snug mb-3 sm:mb-4 line-clamp-3">
                        <?php echo $titel; ?>
                    </h2>
                </a>

                <?php if(!empty($themen)): ?>
                <div class="flex flex-wrap gap-1.5 sm:gap-2">
                    <?php foreach($themen as $t): ?>
                        <span class="inline-flex items-center px-2 sm:px-2.5 py-0.5 sm:py-1 rounded-md text-[10px] sm:text-[11px] font-medium bg-slate-50 text-slate-500 border border-slate-100 group-hover:bg-white group-hover:border-slate-200 transition-colors line-clamp-1">
                            #<?php echo $t; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($searchQuery && !empty($displayResults)): 
            $totalResults = $debugInfo['stats']['total_before_pagination'];
            $totalPages = ceil($totalResults / $perPage);
            
            $baseUrl = "?q=" . urlencode($searchQuery);
            $baseUrl .= "&gp_from=" . urlencode($gpFrom);
            $baseUrl .= "&gp_to=" . urlencode($gpTo);
            
            if ($isExactMode) {
                $baseUrl .= "&exact=1";
            }
            
            if (!empty($_GET['viz_date_from'])) {
                $baseUrl .= "&viz_date_from=" . urlencode($_GET['viz_date_from']);
            }
            if (!empty($_GET['viz_date_to'])) {
                $baseUrl .= "&viz_date_to=" . urlencode($_GET['viz_date_to']);
            }
            
            foreach($selectedParties as $p) {
                $baseUrl .= "&parties[]=" . urlencode($p);
            }
            
            $pageRange = 3;
            $startPage = max(1, $page - $pageRange);
            $endPage = min($totalPages, $page + $pageRange);
            
            if ($totalPages > 1):
        ?>
            <div class="mt-8 mb-12">
                <div class="flex flex-col items-center gap-4">
                    
                    <div class="text-sm text-slate-500">
                        Seite <span class="font-bold text-slate-900"><?php echo $page; ?></span> von 
                        <span class="font-bold text-slate-900"><?php echo $totalPages; ?></span>
                    </div>
                    
                    <div class="flex flex-wrap items-center justify-center gap-1 sm:gap-2">
                        
                        <?php if ($page > 1): ?>
                            <a href="<?php echo $baseUrl . '&page=' . ($page - 1); ?>" 
                               class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 hover:border-indigo-300 transition-all active:scale-95 group">
                                <svg class="w-4 h-4 text-slate-600 group-hover:text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </a>
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($startPage > 1): ?>
                            <a href="<?php echo $baseUrl . '&page=1'; ?>" 
                               class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 hover:border-indigo-300 text-sm font-medium text-slate-700 hover:text-indigo-600 transition-all active:scale-95">
                                1
                            </a>
                            <?php if ($startPage > 2): ?>
                                <span class="text-slate-400 px-1">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border-2 border-indigo-600 bg-indigo-600 text-white text-sm font-bold shadow-lg shadow-indigo-200">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="<?php echo $baseUrl . '&page=' . $i; ?>" 
                                   class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 hover:border-indigo-300 text-sm font-medium text-slate-700 hover:text-indigo-600 transition-all active:scale-95">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="text-slate-400 px-1">...</span>
                            <?php endif; ?>
                            <a href="<?php echo $baseUrl . '&page=' . $totalPages; ?>" 
                               class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 hover:border-indigo-300 text-sm font-medium text-slate-700 hover:text-indigo-600 transition-all active:scale-95">
                                <?php echo $totalPages; ?>
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo $baseUrl . '&page=' . ($page + 1); ?>" 
                               class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 hover:border-indigo-300 transition-all active:scale-95 group">
                                <svg class="w-4 h-4 text-slate-600 group-hover:text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </a>
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center w-9 h-9 sm:w-10 sm:h-10 rounded-lg border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </span>
                        <?php endif; ?>
                        
                    </div>
                    
                    <div class="hidden sm:flex items-center gap-2 text-sm">
                        <span class="text-slate-500">Gehe zu Seite:</span>
                        <form method="GET" class="inline-flex gap-1">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>">
                            <input type="hidden" name="gp_from" value="<?php echo $gpFrom; ?>">
                            <input type="hidden" name="gp_to" value="<?php echo $gpTo; ?>">
                            <?php if ($isExactMode): ?>
                                <input type="hidden" name="exact" value="1">
                            <?php endif; ?>
                            <?php foreach($selectedParties as $p): ?>
                                <input type="hidden" name="parties[]" value="<?php echo $p; ?>">
                            <?php endforeach; ?>
                            <?php if (!empty($_GET['viz_date_from'])): ?>
                                <input type="hidden" name="viz_date_from" value="<?php echo htmlspecialchars($_GET['viz_date_from']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($_GET['viz_date_to'])): ?>
                                <input type="hidden" name="viz_date_to" value="<?php echo htmlspecialchars($_GET['viz_date_to']); ?>">
                            <?php endif; ?>
                            
                            <input type="number" name="page" min="1" max="<?php echo $totalPages; ?>" 
                                   placeholder="<?php echo $page; ?>"
                                   class="w-16 px-2 py-1 border border-slate-200 rounded-lg text-center text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            <button type="submit" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-colors active:scale-95">
                                Go
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- INFO MODAL - Was sind parlamentarische Anfragen? -->
    <div id="info-modal" class="hidden fixed inset-0 z-50 overflow-hidden bg-black/80 backdrop-blur-sm">
        <div class="absolute inset-4 sm:inset-20 lg:inset-x-[20%] lg:inset-y-[10%] bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col">
            <!-- Header -->
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 sm:px-6 py-4 sm:py-5 flex justify-between items-start border-b border-indigo-700/20">
                <div class="flex-1 pr-4">
                    <h2 class="text-white font-bold text-lg sm:text-xl mb-1">Was sind parlamentarische Anfragen?</h2>
                    <p class="text-white/80 text-xs sm:text-sm">Ein wichtiges Kontrollinstrument der Opposition</p>
                </div>
                <button onclick="closeInfoModal()" class="flex-shrink-0 text-white/80 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <!-- Content -->
            <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-3xl mx-auto space-y-4 sm:space-y-6">
                    
                    <!-- Definition -->
                    <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4 sm:p-5">
                        <h3 class="font-bold text-indigo-900 text-base sm:text-lg mb-2 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                            <span>Definition</span>
                        </h3>
                        <p class="text-slate-700 text-sm sm:text-base leading-relaxed">
                            Eine parlamentarische Anfrage (auch <strong>Interpellation</strong> genannt) ist ein formelles Instrument, mit dem Abgeordnete die Regierung zur Auskunft verpflichten können. 
                            Die Regierung muss innerhalb von zwei Monaten schriftlich antworten.
                        </p>
                    </div>

                    <!-- Why Important -->
                    <div>
                        <h3 class="font-bold text-slate-900 text-base sm:text-lg mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <span>Warum sind Anfragen wichtig?</span>
                        </h3>
                        <div class="space-y-3">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 font-bold text-sm">1</div>
                                <div>
                                    <h4 class="font-semibold text-slate-900 text-sm mb-1">Kontrolle der Regierung</h4>
                                    <p class="text-slate-600 text-sm">Abgeordnete können die Regierung zu Rechenschaft ziehen und kritische Themen ansprechen.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm">2</div>
                                <div>
                                    <h4 class="font-semibold text-slate-900 text-sm mb-1">Transparenz schaffen</h4>
                                    <p class="text-slate-600 text-sm">Alle Anfragen und Antworten sind öffentlich – Bürger:innen können nachvollziehen, was im Parlament diskutiert wird.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 font-bold text-sm">3</div>
                                <div>
                                    <h4 class="font-semibold text-slate-900 text-sm mb-1">Strategisches Instrument</h4>
                                    <p class="text-slate-600 text-sm">Anfragen werden auch genutzt, um politische Themen in die Öffentlichkeit zu bringen und Druck aufzubauen.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Facts -->
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 sm:p-5">
                        <h3 class="font-bold text-slate-900 text-base sm:text-lg mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            <span>Interessante Fakten</span>
                        </h3>
                        <ul class="space-y-2 text-sm sm:text-base">
                            <li class="flex items-start gap-2">
                                <span class="text-amber-600 mt-1">→</span>
                                <span class="text-slate-700">Anfragen machen einen <strong>großen Teil des politischen Alltags</strong> aus – oft mehrere hundert pro Monat!</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-amber-600 mt-1">→</span>
                                <span class="text-slate-700">Sie geben einen <strong>einzigartigen Einblick</strong> in politische Prioritäten und Strategien der Parteien.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-amber-600 mt-1">→</span>
                                <span class="text-slate-700">Die Themen reichen von <strong>Gesundheit und Wirtschaft</strong> bis zu <strong>Umwelt und Innenpolitik</strong>.</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Strategic Use -->
                    <div class="bg-gradient-to-r from-orange-50 to-amber-50 border-2 border-orange-200 rounded-xl p-4 sm:p-5">
                        <h3 class="font-bold text-orange-900 text-base sm:text-lg mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <span>Die strategische Dimension</span>
                        </h3>
                        <p class="text-orange-900 text-sm sm:text-base leading-relaxed mb-3">
                            Anfragen sind nicht nur ein Werkzeug zur Informationsbeschaffung – sie werden auch gezielt strategisch eingesetzt:
                        </p>
                        <ul class="space-y-2.5 text-sm sm:text-base">
                            <li class="flex items-start gap-2.5 bg-white/60 p-3 rounded-lg">
                                <div class="flex-shrink-0 w-6 h-6 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 font-bold text-xs mt-0.5">1</div>
                                <div>
                                    <span class="font-semibold text-orange-900">Eigene Themen präsentieren:</span>
                                    <span class="text-orange-800"> Parteien nutzen Anfragen, um ihre politischen Schwerpunkte in die Öffentlichkeit zu bringen.</span>
                                </div>
                            </li>
                            <li class="flex items-start gap-2.5 bg-white/60 p-3 rounded-lg">
                                <div class="flex-shrink-0 w-6 h-6 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 font-bold text-xs mt-0.5">2</div>
                                <div>
                                    <span class="font-semibold text-orange-900">Ministerien beschäftigen:</span>
                                    <span class="text-orange-800"> Durch viele Anfragen werden Ressourcen gebunden – die Regierung muss jede Anfrage beantworten.</span>
                                </div>
                            </li>
                            <li class="flex items-start gap-2.5 bg-white/60 p-3 rounded-lg">
                                <div class="flex-shrink-0 w-6 h-6 rounded-full bg-orange-100 flex items-center justify-center text-orange-600 font-bold text-xs mt-0.5">3</div>
                                <div>
                                    <span class="font-semibold text-orange-900">Informationsraum beeinflussen:</span>
                                    <span class="text-orange-800"> Gezielte Anfragen zu bestimmten Themen können die öffentliche Debatte lenken.</span>
                                </div>
                            </li>
                        </ul>
                        <div class="mt-4 pt-4 border-t border-orange-200">
                            <div class="flex items-start gap-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white p-4 rounded-lg">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                <div>
                                    <p class="font-bold text-sm sm:text-base mb-1">Das Schöne an dieser Website:</p>
                                    <p class="text-white/90 text-sm leading-relaxed">
                                        Hier können Sie <strong>all das transparent nachvollziehen!</strong> Sehen Sie selbst, welche Parteien zu welchen Themen wie viele Anfragen stellen 
                                        und erkennen Sie politische Muster und Strategien auf einen Blick.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- About This Tool -->
                    <div class="border-t border-slate-200 pt-4 sm:pt-6">
                        <h3 class="font-bold text-slate-900 text-base sm:text-lg mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <span>Über dieses Tool</span>
                        </h3>
                        <p class="text-slate-700 text-sm sm:text-base leading-relaxed mb-3">
                            <strong>Anfragen Transparent</strong> macht alle parlamentarischen Anfragen einfach durchsuchbar und verständlich. 
                            Unsere intelligente KI-Suche findet auch verwandte Begriffe und Synonyme, sodass Sie nichts verpassen.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-xs font-medium">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Deep Search
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 text-xs font-medium">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                KI-gestützt
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-purple-50 text-purple-700 border border-purple-100 text-xs font-medium">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                100% Transparent
                            </span>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Footer -->
            <div class="border-t border-slate-200 px-4 sm:px-6 py-3 sm:py-4 bg-slate-50">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-xs text-slate-500">Daten direkt vom österreichischen Parlament</p>
                    <button onclick="closeInfoModal()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Verstanden
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- DASHBOARD MODAL -->
    <div id="dashboard-modal" class="hidden fixed inset-0 z-50 overflow-hidden bg-black/80 backdrop-blur-sm">
        <div class="absolute inset-4 sm:inset-8 bg-gradient-to-br from-slate-50 to-slate-100 rounded-2xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col">
            <!-- Header -->
            <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-4 sm:px-6 py-4 flex justify-between items-center border-b border-emerald-700/20">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-white/10 rounded-lg">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-white font-bold text-lg">Statistik-Übersicht</h2>
                        <p class="text-white/80 text-xs">Alle Anfragen der letzten 4 Monate • <?php echo number_format($dashboardData['total'] ?? 0, 0, ',', '.'); ?> Anfragen gesamt</p>
                    </div>
                </div>
                <button onclick="closeDashboard()" class="text-white/80 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <!-- Dashboard Content -->
            <div class="flex-1 overflow-auto p-4 sm:p-6">
                <div class="max-w-7xl mx-auto space-y-4 sm:space-y-6">
                    
                    <?php if (!empty($dashboardData) && $dashboardData['total'] > 0): ?>
                    <!-- Info Banner -->
                    <div class="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-xl p-4 flex items-start gap-3">
                        <div class="flex-shrink-0 p-2 bg-emerald-100 rounded-lg">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-bold text-emerald-900 text-sm mb-1">Überblick über alle parlamentarischen Anfragen</h4>
                            <p class="text-emerald-700 text-xs leading-relaxed">
                                Diese Statistiken zeigen <strong>alle parlamentarischen Anfragen der letzten 4 Monate</strong> – unabhängig davon, ob Sie gerade etwas suchen oder nicht. 
                                So sehen Sie auf einen Blick, welche Themen die Politik gerade beschäftigen und welche Parteien besonders aktiv sind.
                            </p>
                        </div>
                    </div>
                    
                    <!-- KPI Cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                        <!-- Total -->
                        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-slate-500 uppercase">Insgesamt</span>
                                <div class="p-2 bg-blue-50 rounded-lg">
                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                </div>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-slate-900"><?php echo $dashboardData['total'] ?? 0; ?></div>
                            <p class="text-xs text-slate-500 mt-1">Alle Anfragen</p>
                        </div>
                        
                        <!-- Answered -->
                        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-slate-500 uppercase">Beantwortet</span>
                                <div class="p-2 bg-green-50 rounded-lg">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $dashboardData['answered'] ?? 0; ?></div>
                            <p class="text-xs text-slate-500 mt-1">
                                <?php 
                                $total = $dashboardData['total'] ?? 1;
                                $answered = $dashboardData['answered'] ?? 0;
                                $percentage = $total > 0 ? round(($answered / $total) * 100) : 0;
                                echo $percentage . '%';
                                ?> Quote
                            </p>
                        </div>
                        
                        <!-- Pending -->
                        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-slate-500 uppercase">Offen</span>
                                <div class="p-2 bg-amber-50 rounded-lg">
                                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-amber-600"><?php echo $dashboardData['pending'] ?? 0; ?></div>
                            <p class="text-xs text-slate-500 mt-1">
                                <?php 
                                $pending = $dashboardData['pending'] ?? 0;
                                $pendingPercentage = $total > 0 ? round(($pending / $total) * 100) : 0;
                                echo $pendingPercentage . '%';
                                ?> offen
                            </p>
                        </div>
                        
                        <!-- Most Active Party -->
                        <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-slate-500 uppercase">Am aktivsten</span>
                                <div class="p-2 bg-purple-50 rounded-lg">
                                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                                </div>
                            </div>
                            <?php 
                            $partyMap = ['S' => 'SPÖ', 'V' => 'ÖVP', 'F' => 'FPÖ', 'G' => 'GRÜNE', 'N' => 'NEOS'];
                            $maxParty = 'S';
                            $maxCount = 0;
                            foreach ($dashboardData['by_party'] ?? [] as $party => $count) {
                                if ($count > $maxCount) {
                                    $maxCount = $count;
                                    $maxParty = $party;
                                }
                            }
                            ?>
                            <div class="text-2xl sm:text-3xl font-bold text-slate-900"><?php echo $partyMap[$maxParty] ?? '-'; ?></div>
                            <p class="text-xs text-slate-500 mt-1"><?php echo $maxCount; ?> Anfragen gestellt</p>
                        </div>
                    </div>
                    
                    <!-- Charts Row -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                        <!-- Party Distribution -->
                        <div class="bg-white rounded-xl p-4 sm:p-6 border border-slate-200 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                <span class="text-lg">Anfragen pro Partei</span>
                            </h3>
                            <div class="space-y-3">
                                <?php 
                                $partyColors = [
                                    'S' => ['color' => 'bg-red-500', 'label' => 'SPÖ'],
                                    'V' => ['color' => 'bg-teal-900', 'label' => 'ÖVP'],
                                    'F' => ['color' => 'bg-blue-500', 'label' => 'FPÖ'],
                                    'G' => ['color' => 'bg-green-500', 'label' => 'GRÜNE'],
                                    'N' => ['color' => 'bg-pink-500', 'label' => 'NEOS']
                                ];
                                $totalQueries = array_sum($dashboardData['by_party'] ?? []);
                                foreach ($partyColors as $code => $data):
                                    $count = $dashboardData['by_party'][$code] ?? 0;
                                    $percent = $totalQueries > 0 ? round(($count / $totalQueries) * 100) : 0;
                                ?>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm font-medium text-slate-700"><?php echo $data['label']; ?></span>
                                        <span class="text-sm font-bold text-slate-900"><?php echo $count; ?> (<?php echo $percent; ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-100 rounded-full h-2.5">
                                        <div class="<?php echo $data['color']; ?> h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Timeline -->
                        <div class="bg-white rounded-xl p-4 sm:p-6 border border-slate-200 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                <span class="text-lg">Anfragen pro Monat</span>
                            </h3>
                            <div class="space-y-3">
                                <?php 
                                $maxMonthCount = 0;
                                foreach ($dashboardData['by_month'] ?? [] as $month => $data) {
                                    if ($data['total'] > $maxMonthCount) $maxMonthCount = $data['total'];
                                }
                                foreach ($dashboardData['by_month'] ?? [] as $month => $data): 
                                    $percentage = $maxMonthCount > 0 ? round(($data['total'] / $maxMonthCount) * 100) : 0;
                                ?>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm font-medium text-slate-700"><?php echo $data['label']; ?></span>
                                        <span class="text-sm font-bold text-slate-900"><?php echo $data['total']; ?></span>
                                    </div>
                                    <div class="flex gap-0.5 h-2.5">
                                        <?php foreach (['S', 'V', 'F', 'G', 'N'] as $p): 
                                            $pCount = $data[$p] ?? 0;
                                            $pPercent = $data['total'] > 0 ? ($pCount / $data['total']) * $percentage : 0;
                                            $pColor = $partyColors[$p]['color'] ?? 'bg-slate-300';
                                        ?>
                                        <?php if ($pCount > 0): ?>
                                        <div class="<?php echo $pColor; ?> rounded-sm transition-all duration-500" style="width: <?php echo $pPercent; ?>%"></div>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bottom Row -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Top Topics - now takes 1 column -->
                        <div class="bg-white rounded-xl p-4 sm:p-6 border border-slate-200 shadow-sm">
                            <h3 class="font-bold text-slate-900 mb-4 flex items-center gap-2">
                                <span class="text-lg">Häufigste Themen</span>
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                <?php 
                                $topicsList = $dashboardData['top_topics'] ?? [];
                                $maxTopicCount = max(array_values($topicsList ?: [1]));
                                foreach ($topicsList as $topic => $count): 
                                    $size = $count === $maxTopicCount ? 'text-base' : 'text-sm';
                                    $weight = $count === $maxTopicCount ? 'font-bold' : 'font-medium';
                                ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 <?php echo $size . ' ' . $weight; ?>">
                                    <span><?php echo htmlspecialchars($topic); ?></span>
                                    <span class="text-xs opacity-75"><?php echo $count; ?></span>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Recent Activity - now takes 2 columns -->
                        <div class="bg-white rounded-xl p-4 sm:p-6 border border-slate-200 shadow-sm lg:col-span-2">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-bold text-slate-900 flex items-center gap-2">
                                    <span class="text-lg">Neueste Anfragen</span>
                                    <span class="text-xs text-slate-500 font-normal">(Letzte 100)</span>
                                </h3>
                                <div class="text-xs text-slate-400 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                    Scrollbar
                                </div>
                            </div>
                            <div class="space-y-2 max-h-96 overflow-y-auto pr-2 activity-scroll">
                                <?php foreach ($dashboardData['recent_items'] ?? [] as $item): 
                                    $itemPartyColor = $partyColors[$item['party']]['color'] ?? 'bg-slate-400';
                                    $itemPartyLabel = $partyMap[$item['party']] ?? '';
                                ?>
                                <a href="<?php echo $item['link']; ?>" target="_blank" class="flex items-start gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors border border-slate-100 hover:border-slate-200 group">
                                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full <?php echo $itemPartyColor; ?>"></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-slate-900 line-clamp-2 group-hover:text-indigo-600 transition-colors"><?php echo htmlspecialchars($item['title']); ?></p>
                                        <div class="flex items-center gap-2 mt-1.5 text-xs text-slate-500 flex-wrap">
                                            <span class="font-medium"><?php echo $item['date']; ?></span>
                                            <span>•</span>
                                            <span class="font-medium"><?php echo $itemPartyLabel; ?></span>
                                            <span>•</span>
                                            <span class="text-indigo-600 font-medium"><?php echo $item['number']; ?></span>
                                            <?php if ($item['answered']): ?>
                                            <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700 font-medium">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                <span class="hidden sm:inline">Beantwortet</span>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <!-- Empty State -->
                    <div class="flex items-center justify-center h-96">
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4">
                                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                            </div>
                            <h3 class="text-lg font-semibold text-slate-900 mb-2">Keine Daten verfügbar</h3>
                            <p class="text-sm text-slate-500 max-w-md mx-auto">
                                Dashboard-Daten konnten nicht geladen werden. Bitte prüfen Sie Ihre API-Verbindung oder versuchen Sie es später erneut.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                </div>
            </div>
        </div>
    </div>

    <!-- 3D GRID VISUALIZATION MODAL -->
    <div id="viz-modal" class="hidden fixed inset-0 z-50 overflow-hidden bg-black/80 backdrop-blur-sm">
        <div class="absolute inset-4 sm:inset-8 bg-slate-900 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden flex flex-col">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-4 sm:px-6 py-4 flex justify-between items-center border-b border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-white/10 rounded-lg">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"></path></svg>
                    </div>
                    <div>
                        <h2 class="text-white font-bold text-lg">3D Übersicht</h2>
                        <p class="text-white/70 text-xs">Zeitverlauf und Parteien-Aktivität im Überblick</p>
                    </div>
                </div>
                <button onclick="closeVisualization()" class="text-white/80 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <div class="flex-1 relative overflow-hidden">
                <div id="viz-canvas" class="w-full h-full"></div>
                
                <div class="absolute top-4 left-1/2 transform -translate-x-1/2 bg-slate-800/90 backdrop-blur-sm rounded-lg px-4 py-2 border border-slate-700 pointer-events-none">
                    <p class="text-white text-xs sm:text-sm font-medium text-center">
                        <span class="hidden sm:inline">🖱️ Ziehen = Drehen • Mausrad = Zoom • Klick = Filtern</span>
                        <span class="sm:hidden">👆 Ziehen = Drehen • Pinch = Zoom • Tippen = Filtern</span>
                    </p>
                </div>
                
                <div class="absolute bottom-4 left-4 right-4 sm:bottom-6 sm:left-6 sm:right-6 bg-slate-800/90 backdrop-blur-sm rounded-xl p-4 border border-slate-700">
                    <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-3 sm:gap-4 justify-center items-center text-xs sm:text-sm">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-red-600 shadow-lg"></div>
                            <span class="text-white font-medium">SPÖ</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-teal-900 shadow-lg"></div>
                            <span class="text-white font-medium">ÖVP</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-blue-600 shadow-lg"></div>
                            <span class="text-white font-medium">FPÖ</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-green-600 shadow-lg"></div>
                            <span class="text-white font-medium">GRÜNE</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-pink-500 shadow-lg"></div>
                            <span class="text-white font-medium">NEOS</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-slate-700 text-center">
                        <p class="text-slate-400 text-[10px] sm:text-xs">
                            💡 <span class="font-semibold">Höhe</span> = Anzahl der Anfragen • 
                            <span class="font-semibold">Von links nach rechts</span> = Zeitverlauf • 
                            <span class="font-semibold">Vorne bis hinten</span> = Parteien
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="fixed bottom-4 right-3 sm:bottom-6 sm:right-6 z-50">
        <button onclick="toggleDebug()" class="bg-slate-900 hover:bg-black text-white p-3 sm:p-3.5 rounded-full shadow-xl shadow-slate-900/20 transition-transform hover:scale-105 active:scale-95 flex items-center justify-center" title="Debug">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
        </button>
    </div>

    <div id="debug-console" class="hidden fixed inset-x-2 bottom-16 sm:inset-x-auto sm:bottom-20 sm:right-6 sm:w-[600px] h-[70vh] sm:h-[500px] bg-slate-900 border border-slate-700 rounded-xl sm:rounded-2xl shadow-2xl z-50 overflow-hidden flex flex-col">
        <div class="bg-slate-800 px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-700 flex justify-between items-center text-slate-300">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-green-500"></div>
                <span class="font-mono text-[10px] sm:text-xs font-bold uppercase tracking-wider">Debug</span>
            </div>
            <div class="flex gap-2">
                <button onclick="copyDebug()" class="text-[10px] sm:text-xs bg-slate-700 hover:bg-slate-600 text-white px-2 sm:px-3 py-1 sm:py-1.5 rounded transition-colors active:scale-95">Copy</button>
                <button onclick="toggleDebug()" class="text-slate-400 hover:text-white px-1.5 sm:px-2 transition-colors">✕</button>
            </div>
        </div>
        <div class="flex-1 overflow-auto p-3 sm:p-4 bg-[#0d1117]">
            <pre id="debug-content" class="text-[10px] sm:text-[11px] font-mono leading-relaxed text-green-400/90 whitespace-pre-wrap break-words"><?php echo htmlspecialchars(json_encode($jsonDebugPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    </div>

</body>
</html>