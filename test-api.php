<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseUrl = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true';

$payload = [
    "GP_CODE" => ["XXVIII"],
    "VHG" => ["J_JPR_M"],
    "DOKTYP" => ["J"]
];

$pageSize = 25;
$allRows = [];
$page = 1;
$firstData = null;

echo "Fetching all pages from API...\n\n";

do {
    $url = $baseUrl . '&page=' . $page;
    echo "Fetching page $page ($url)...\n";

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

    echo "  HTTP Code: $httpCode | Response: " . strlen($response) . " bytes\n";

    if ($error) {
        echo "  CURL Error: $error\n";
        break;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  JSON Error: " . json_last_error_msg() . "\n";
        break;
    }

    if ($firstData === null) {
        $firstData = $data;
        echo "  Top-level keys: " . implode(', ', array_keys($data ?? [])) . "\n";
        echo "  pages: " . ($data['pages'] ?? 'N/A') . ", count: " . ($data['count'] ?? 'N/A') . "\n";
    }

    $rows = $data['rows'] ?? [];
    echo "  Rows on this page: " . count($rows) . "\n";

    $allRows = array_merge($allRows, $rows);

    if (count($rows) < $pageSize) {
        echo "  Last page reached (fewer than $pageSize rows).\n";
        break;
    }

    $page++;
} while (true);

echo "\n=== SUMMARY ===\n";
echo "Total pages fetched: " . ($page) . "\n";
echo "Total rows fetched: " . count($allRows) . "\n\n";

if (count($allRows) > 0) {
    $today = date('d.m.Y');
    echo "Looking for entries from today ($today)...\n";

    $todayEntries = 0;
    $ngoEntries = 0;
    $todayNgoEntries = 0;

    foreach ($allRows as $row) {
        $dateStr = $row[4] ?? '';
        $title = strtolower($row[6] ?? '');

        $hasNGO = (
            strpos($title, 'ngo') !== false ||
            strpos($title, 'nicht-regierungsorganisation') !== false ||
            strpos($title, 'nonprofit') !== false
        );

        if ($hasNGO) {
            $ngoEntries++;
        }

        if ($dateStr === $today) {
            $todayEntries++;
            if ($hasNGO) {
                $todayNgoEntries++;
                echo "  Found: $dateStr - " . substr($row[6], 0, 80) . "\n";
            }
        }
    }

    echo "\nTotal entries today: $todayEntries\n";
    echo "Total NGO entries (all time): $ngoEntries\n";
    echo "NGO entries from today: $todayNgoEntries\n";
}
