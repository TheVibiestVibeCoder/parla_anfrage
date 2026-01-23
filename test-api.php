<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$url = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true';

$payload = [
    "GP_CODE" => ["XXVIII"],
    "VHG" => ["J_JPR_M"],
    "DOKTYP" => ["J"]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

echo "Calling API...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "CURL Error: $error\n";
}

echo "Response length: " . strlen($response) . " bytes\n\n";

$data = json_decode($response, true);
$jsonError = json_last_error();

if ($jsonError !== JSON_ERROR_NONE) {
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "First 500 chars of response:\n";
    echo substr($response, 0, 500) . "\n";
} else {
    echo "JSON decoded successfully\n";
    echo "Type: " . gettype($data) . "\n";

    if (is_array($data)) {
        echo "Keys: " . implode(', ', array_keys($data)) . "\n\n";

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                echo "$key: array with " . count($value) . " elements\n";
                if ($key === 'rows' && count($value) > 0) {
                    echo "  First row type: " . gettype($value[0]) . "\n";
                    if (is_array($value[0])) {
                        echo "  First row has " . count($value[0]) . " elements\n";
                        echo "  First row [4] (date): " . ($value[0][4] ?? 'N/A') . "\n";
                        echo "  First row [6] (title): " . substr($value[0][6] ?? 'N/A', 0, 100) . "\n";

                        // Find NGO-related entries from today
                        $today = date('d.m.Y');
                        echo "\n  Looking for entries from today ($today)...\n";

                        $todayEntries = 0;
                        $ngoEntries = 0;
                        $todayNgoEntries = 0;

                        foreach ($value as $row) {
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
                                    echo "    Found: $dateStr - " . substr($row[6], 0, 80) . "...\n";
                                }
                            }
                        }

                        echo "\n  Total entries today: $todayEntries\n";
                        echo "  Total NGO entries (all time): $ngoEntries\n";
                        echo "  NGO entries from today: $todayNgoEntries\n";
                    }
                }
            } else {
                echo "$key: " . substr(print_r($value, true), 0, 100) . "\n";
            }
        }
    }
}
