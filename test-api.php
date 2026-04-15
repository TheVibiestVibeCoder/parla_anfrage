<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseUrl = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true';
$today = date('d.m.Y');

function callApi($url, $payload) {
    $ch = curl_init($url);
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
    $error = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $error];
}

function printResult($label, $response, $httpCode, $error) {
    global $today;
    echo "--- $label ---\n";
    echo "HTTP: $httpCode | Size: " . strlen($response) . " bytes\n";
    if ($error) { echo "CURL Error: $error\n"; return; }

    $data = json_decode($response, true);
    if (!$data) { echo "JSON Error: " . json_last_error_msg() . "\n"; return; }

    $totalPages = $data['pages'] ?? '?';
    $totalCount = $data['count'] ?? '?';
    $rowsReturned = count($data['rows'] ?? []);

    echo "pages (total): $totalPages | count (total): $totalCount | rows in response: $rowsReturned\n";

    if ($rowsReturned > 0) {
        $ngo = 0;
        $todayRows = 0;
        foreach ($data['rows'] as $row) {
            $dateStr = $row[4] ?? '';
            $title = mb_strtolower($row[6] ?? '');
            if ($dateStr === $today) $todayRows++;
            if (strpos($title, 'ngo') !== false || strpos($title, 'nonprofit') !== false ||
                strpos($title, 'nicht-regierungsorganisation') !== false) $ngo++;
        }
        echo "Rows from today ($today): $todayRows | NGO rows (all time): $ngo\n";
    }
    echo "\n";
}

echo "=== PARLIAMENT API DIAGNOSTIC ===\n";
echo "Date: $today\n\n";

// TEST 1: Current GP only (XXVIII) - what the old test used
[$r, $c, $e] = callApi($baseUrl, ["GP_CODE" => ["XXVIII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]]);
printResult("Test 1: GP=XXVIII only (current period)", $r, $c, $e);

// TEST 2: All GP codes like production (XXVIII + XXVII + XXVI + XXV)
[$r, $c, $e] = callApi($baseUrl, ["GP_CODE" => ["XXVIII", "XXVII", "XXVI", "XXV"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]]);
printResult("Test 2: GP=XXVIII+XXVII+XXVI+XXV (same as production)", $r, $c, $e);

// TEST 3: No DOKTYP filter - see if that changes the count
[$r, $c, $e] = callApi($baseUrl, ["GP_CODE" => ["XXVIII", "XXVII", "XXVI", "XXV"], "VHG" => ["J_JPR_M"]]);
printResult("Test 3: No DOKTYP filter (all types)", $r, $c, $e);

// TEST 4: Today only via DATUM_VON filter (useful for email sender!)
[$r, $c, $e] = callApi($baseUrl, [
    "GP_CODE" => ["XXVIII", "XXVII", "XXVI", "XXV"],
    "VHG" => ["J_JPR_M"],
    "DOKTYP" => ["J"],
    "DATUM_VON" => [$today, $today]
]);
printResult("Test 4: Today only via DATUM_VON=$today", $r, $c, $e);

// TEST 5: Try XXVII alone (previous period - should have many entries)
[$r, $c, $e] = callApi($baseUrl, ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]]);
printResult("Test 5: GP=XXVII only (previous period)", $r, $c, $e);
