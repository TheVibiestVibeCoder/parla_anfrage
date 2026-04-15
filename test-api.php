<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);

$BASE_URL  = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=true';
$TODAY     = date('d.m.Y');
$YESTERDAY = date('d.m.Y', strtotime('-1 day'));
$WEEK_AGO  = date('d.m.Y', strtotime('-7 days'));
$TWO_WEEKS = date('d.m.Y', strtotime('-14 days'));
$MONTH_AGO = date('d.m.Y', strtotime('-30 days'));

$NGO_KEYWORDS = ['ngo', 'ngos', 'ngo-business', 'nicht-regierungsorganisation',
                 'nichtregierungsorganisation', 'nonprofit', 'non-profit',
                 'non-governmental', 'ehrenamtlich'];

// ============================================================
// HELPERS
// ============================================================

function callApi($url, $payload, $timeoutSec = 30) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $t0       = microtime(true);
    $response = curl_exec($ch);
    $elapsed  = round((microtime(true) - $t0) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $error, $elapsed];
}

function analyzeRows($rows, $today) {
    global $NGO_KEYWORDS;
    $ngo = $todayRows = $dateMap = 0;
    $dates = [];
    foreach ($rows as $row) {
        $dateStr = $row[4] ?? '';
        $title   = mb_strtolower($row[6] ?? '');
        if ($dateStr === $today) $todayRows++;
        $hasNGO = false;
        foreach ($NGO_KEYWORDS as $kw) {
            if (strpos($title, $kw) !== false) { $hasNGO = true; break; }
        }
        if ($hasNGO) $ngo++;
        if ($dateStr) $dates[] = $dateStr;
    }
    $newest = $oldest = 'N/A';
    if ($dates) {
        // Convert to timestamps for comparison
        $ts = array_map(fn($d) => DateTime::createFromFormat('d.m.Y', $d), $dates);
        $ts = array_filter($ts);
        if ($ts) {
            $newest = max($ts)->format('d.m.Y');
            $oldest = min($ts)->format('d.m.Y');
        }
    }
    return ['today' => $todayRows, 'ngo' => $ngo, 'newest' => $newest, 'oldest' => $oldest];
}

$testNum = 0;
function printTest($label, $url, $payload, $response, $httpCode, $error, $elapsed, $extraNote = '') {
    global $TODAY, $testNum;
    $testNum++;
    $sep = str_repeat('=', 70);
    $sep2 = str_repeat('-', 70);

    echo "\n$sep\n";
    echo "TEST $testNum  |  $label\n";
    echo $sep . "\n";

    if ($extraNote) echo "NOTE: $extraNote\n";

    echo "URL:     $url\n";
    echo "PAYLOAD: " . json_encode($payload) . "\n";
    echo $sep2 . "\n";

    if ($error) {
        echo "CURL ERROR: $error\n";
        return;
    }

    echo "HTTP: $httpCode  |  {$elapsed}ms  |  " . strlen($response) . " bytes\n";

    $data = json_decode($response, true);
    if (!$data) {
        echo "JSON ERROR: " . json_last_error_msg() . "\n";
        echo "RAW (first 300 chars): " . substr($response, 0, 300) . "\n";
        return;
    }

    $pages      = $data['pages']  ?? '?';
    $count      = $data['count']  ?? '?';
    $lastSync   = $data['lastSync'] ?? '?';
    $rows       = $data['rows']   ?? [];
    $rowsBack   = count($rows);

    echo "pages (total pages per docs): $pages\n";
    echo "count (total results per docs): $count\n";
    echo "lastSync: $lastSync\n";
    echo "rows actually in response: $rowsBack\n";

    if ($rowsBack > 0) {
        $a = analyzeRows($rows, $TODAY);
        echo "Rows from TODAY ($TODAY): {$a['today']}\n";
        echo "NGO keyword matches (all): {$a['ngo']}\n";
        echo "Date range in data: {$a['oldest']}  →  {$a['newest']}\n";

        // Print first 3 rows as a sample
        echo "Sample rows (first 3):\n";
        foreach (array_slice($rows, 0, 3) as $i => $row) {
            $date   = $row[4]  ?? '??';
            $title  = substr($row[6] ?? '??', 0, 75);
            $gp     = $row[0]  ?? '??';
            $zit    = $row[7]  ?? '??';
            echo "  [$i] $date | GP=$gp | $zit | $title\n";
        }
    }
    echo $sep . "\n";
}

// ============================================================
// HEADER
// ============================================================
echo str_repeat('#', 70) . "\n";
echo "# PARLIAMENT API MONSTER DIAGNOSTIC\n";
echo "# Run at: " . date('Y-m-d H:i:s') . "\n";
echo "# Today:  $TODAY  |  Yesterday: $YESTERDAY\n";
echo "# 7d ago: $WEEK_AGO  |  14d ago: $TWO_WEEKS\n";
echo str_repeat('#', 70) . "\n";

// ============================================================
// SECTION A — PROGRESSIVE FILTERS  (last 14 days, build up)
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION A: PROGRESSIVE FILTERS (last 2 weeks) ***\n";
echo "*** We start with almost no filters, then add one by one ***\n";
echo str_repeat('*', 70) . "\n";

// A1 — Absolutely bare minimum: no GP, no DOKTYP, date range only
$p = ["VHG" => ["J_JPR_M"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("A1 — VHG only + last 14 days (no GP, no DOKTYP)", $BASE_URL, $p, $r, $c, $e, $ms,
    "Baseline: see how many Anfragen exist in last 2 weeks with zero other filters");

// A2 — Add all GP codes
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV","XXIV","XXIII"],
      "VHG" => ["J_JPR_M"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("A2 — + GP codes (XXVIII→XXIII) + last 14 days", $BASE_URL, $p, $r, $c, $e, $ms,
    "Adding GP filter. If count drops, GP codes are filtering out data");

// A3 — Add DOKTYP=J
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV","XXIV","XXIII"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("A3 — + DOKTYP=J + last 14 days", $BASE_URL, $p, $r, $c, $e, $ms,
    "Adding DOKTYP filter. If count drops, DOKTYP=J is the culprit");

// A4 — Add DOKTYP=JPR as well (schriftliche Anfragen an Präsident)
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV","XXIV","XXIII"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J","JPR"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("A4 — + DOKTYP=J+JPR + last 14 days", $BASE_URL, $p, $r, $c, $e, $ms,
    "Both inquiry types. JPR = inquiries to parliament president");

// ============================================================
// SECTION B — GP CODE ISOLATION  (all-time, no date filter)
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION B: GP CODE ISOLATION (no date filter, all time) ***\n";
echo "*** Each legislative period tested alone to see where the data is ***\n";
echo str_repeat('*', 70) . "\n";

foreach ([
    "XXVIII" => "28th GP – current, started late 2024",
    "XXVII"  => "27th GP – Jan 2020–Oct 2024 (should be HUGE)",
    "XXVI"   => "26th GP – Jan 2018–Oct 2019",
    "XXV"    => "25th GP – Nov 2013–Oct 2017",
    "XXIV"   => "24th GP – Nov 2008–Sep 2013",
] as $gp => $desc) {
    $p = ["GP_CODE" => [$gp], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
    [$r, $c, $e, $ms] = callApi($BASE_URL, $p);
    $letter = 'B' . (array_search($gp, array_keys(["XXVIII"=>0,"XXVII"=>0,"XXVI"=>0,"XXV"=>0,"XXIV"=>0])) + 1);
    printTest("$letter — GP=$gp ALONE ($desc)", $BASE_URL, $p, $r, $c, $e, $ms);
}

// B6 — PRODUCTION combo
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("B6 — GP=XXVIII+XXVII+XXVI+XXV combined (current production payload)", $BASE_URL, $p, $r, $c, $e, $ms,
    "This is EXACTLY what send-daily-emails.php calls. Should be ~380+");

// B7 — ALL periods since XX (docs say data starts at XX)
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV","XXIV","XXIII","XXII","XXI","XX"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("B7 — ALL GP codes XX through XXVIII (max dataset)", $BASE_URL, $p, $r, $c, $e, $ms,
    "Docs say data available since XX. Maximum possible result set");

// ============================================================
// SECTION C — DOKTYP VARIANTS
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION C: DOKTYP VARIANTS (GP=XXVII as reference period) ***\n";
echo str_repeat('*', 70) . "\n";

// C1 — No DOKTYP
$p = ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("C1 — GP=XXVII, NO DOKTYP filter", $BASE_URL, $p, $r, $c, $e, $ms,
    "Baseline for XXVII without DOKTYP filter");

// C2 — DOKTYP=J only
$p = ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("C2 — GP=XXVII, DOKTYP=J only (Schriftliche Anfrage)", $BASE_URL, $p, $r, $c, $e, $ms,
    "J = Schriftliche Anfrage. Compare count with C1 - gap = other types");

// C3 — DOKTYP=JPR only
$p = ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["JPR"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("C3 — GP=XXVII, DOKTYP=JPR only (Anfrage an Präsident)", $BASE_URL, $p, $r, $c, $e, $ms);

// C4 — Both J and JPR
$p = ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J","JPR"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("C4 — GP=XXVII, DOKTYP=J+JPR (both types)", $BASE_URL, $p, $r, $c, $e, $ms,
    "C2 + C3 combined. Should equal C1 if J and JPR cover everything");

// ============================================================
// SECTION D — DATE FILTERING (DATUM_VON)
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION D: DATE FILTERING via DATUM_VON ***\n";
echo "*** Docs: DATUM_VON accepts [startDate, endDate] in dd.mm.yyyy ***\n";
echo str_repeat('*', 70) . "\n";

// D1 — Today only
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "DATUM_VON" => [$TODAY, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("D1 — DATUM_VON: TODAY only ($TODAY)", $BASE_URL, $p, $r, $c, $e, $ms,
    "This is what the email sender NEEDS. Entries published today");

// D2 — Yesterday
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "DATUM_VON" => [$YESTERDAY, $YESTERDAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("D2 — DATUM_VON: YESTERDAY only ($YESTERDAY)", $BASE_URL, $p, $r, $c, $e, $ms,
    "Yesterday - if data was there yesterday this should return results");

// D3 — Last 7 days
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "DATUM_VON" => [$WEEK_AGO, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("D3 — DATUM_VON: last 7 days ($WEEK_AGO → $TODAY)", $BASE_URL, $p, $r, $c, $e, $ms);

// D4 — Last 14 days
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("D4 — DATUM_VON: last 14 days ($TWO_WEEKS → $TODAY)", $BASE_URL, $p, $r, $c, $e, $ms);

// D5 — Last 30 days
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "DATUM_VON" => [$MONTH_AGO, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("D5 — DATUM_VON: last 30 days ($MONTH_AGO → $TODAY)", $BASE_URL, $p, $r, $c, $e, $ms);

// ============================================================
// SECTION E — NGO KEYWORD SEARCH via SW (Schlagwort)
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION E: DIRECT NGO KEYWORD SEARCH via SW dimension ***\n";
echo "*** Docs: SW = Schlagwort (keyword tag). Could replace client-side filtering ***\n";
echo str_repeat('*', 70) . "\n";

// E1 — SW=NGO all time
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"], "SW" => ["NGO"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("E1 — SW=NGO (keyword tag), all time", $BASE_URL, $p, $r, $c, $e, $ms,
    "If SW filter works, this returns ONLY tagged NGO inquiries server-side. Much cleaner!");

// E2 — SW=NGO today
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "SW" => ["NGO"], "DATUM_VON" => [$TODAY, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("E2 — SW=NGO + TODAY only", $BASE_URL, $p, $r, $c, $e, $ms,
    "The IDEAL email-sender query if SW filtering works");

// E3 — THEMEN=Soziales (broad social sector, NGOs often tagged here)
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"], "THEMEN" => ["Soziales"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("E3 — THEMEN=Soziales (NGOs often in this category)", $BASE_URL, $p, $r, $c, $e, $ms);

// ============================================================
// SECTION F — PAGINATION / BATCHING ATTEMPTS
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION F: PAGINATION & BATCHING ATTEMPTS ***\n";
echo "*** Testing whether the API can be paginated / gives more results ***\n";
echo str_repeat('*', 70) . "\n";

$PROD_PAYLOAD = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];

// F1 — Baseline (page 1 implicit)
[$r, $c, $e, $ms] = callApi($BASE_URL, $PROD_PAYLOAD);
printTest("F1 — Baseline: production payload, no page param", $BASE_URL, $PROD_PAYLOAD, $r, $c, $e, $ms,
    "Reference. count and pages values here are key.");

// F2 — Explicit page=1 in URL
$url2 = $BASE_URL . '&page=1';
[$r, $c, $e, $ms] = callApi($url2, $PROD_PAYLOAD);
printTest("F2 — &page=1 in URL", $url2, $PROD_PAYLOAD, $r, $c, $e, $ms,
    "Does adding page=1 change anything vs F1?");

// F3 — page=2
$url3 = $BASE_URL . '&page=2';
[$r, $c, $e, $ms] = callApi($url3, $PROD_PAYLOAD);
printTest("F3 — &page=2 in URL", $url3, $PROD_PAYLOAD, $r, $c, $e, $ms,
    "CRITICAL: If rows differ from F2, the API IS paginating and showAll=true is broken!");

// F4 — page=3
$url4 = $BASE_URL . '&page=3';
[$r, $c, $e, $ms] = callApi($url4, $PROD_PAYLOAD);
printTest("F4 — &page=3 in URL", $url4, $PROD_PAYLOAD, $r, $c, $e, $ms);

// F5 — Try showAll=false explicitly
$url5 = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval&showAll=false';
[$r, $c, $e, $ms] = callApi($url5, $PROD_PAYLOAD);
printTest("F5 — showAll=FALSE (compare row count with showAll=true)", $url5, $PROD_PAYLOAD, $r, $c, $e, $ms,
    "If showAll=false also gives 25 rows, then showAll is ignored entirely");

// F6 — Without showAll param at all
$url6 = 'https://www.parlament.gv.at/Filter/api/filter/data/101?js=eval';
[$r, $c, $e, $ms] = callApi($url6, $PROD_PAYLOAD);
printTest("F6 — No showAll param at all", $url6, $PROD_PAYLOAD, $r, $c, $e, $ms,
    "Baseline without any showAll. Same as false?");

// F7 — Try 'offset' in POST body
$p7 = array_merge($PROD_PAYLOAD, ["offset" => 0, "limit" => 200]);
[$r, $c, $e, $ms] = callApi($BASE_URL, $p7);
printTest("F7 — POST body: offset=0, limit=200", $BASE_URL, $p7, $r, $c, $e, $ms,
    "Does the API support offset/limit pagination in the POST body?");

// F8 — Try 'start' and 'count' in POST body (alternative pagination)
$p8 = array_merge($PROD_PAYLOAD, ["start" => 0, "count" => 500]);
[$r, $c, $e, $ms] = callApi($BASE_URL, $p8);
printTest("F8 — POST body: start=0, count=500", $BASE_URL, $p8, $r, $c, $e, $ms,
    "Alternative pagination pattern. Does count=500 give more rows?");

// F9 — Try 'start' = 25 (second batch)
$p9 = array_merge($PROD_PAYLOAD, ["start" => 25, "count" => 500]);
[$r, $c, $e, $ms] = callApi($BASE_URL, $p9);
printTest("F9 — POST body: start=25 (second batch of 500)", $BASE_URL, $p9, $r, $c, $e, $ms,
    "If F7/F8 work, does shifting start return the next batch?");

// F10 — Try 'page' and 'pageSize' in POST body
$p10 = array_merge($PROD_PAYLOAD, ["page" => 1, "pageSize" => 500]);
[$r, $c, $e, $ms] = callApi($BASE_URL, $p10);
printTest("F10 — POST body: page=1, pageSize=500", $BASE_URL, $p10, $r, $c, $e, $ms);

// F11 — Batch: XXVII alone with page=2 (XXVII should have LOTS of data)
$urlP2 = $BASE_URL . '&page=2';
$p11 = ["GP_CODE" => ["XXVII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($urlP2, $p11);
printTest("F11 — GP=XXVII alone + page=2 (does page param work on large set?)", $urlP2, $p11, $r, $c, $e, $ms,
    "XXVII has thousands of Anfragen. If page=2 returns different rows, pagination works");

// ============================================================
// SECTION G — PARTY / FRAK_CODE FILTER
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION G: FRAK_CODE (party) FILTER TEST ***\n";
echo str_repeat('*', 70) . "\n";

// G1 — SPÖ last 14 days
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "FRAK_CODE" => ["S"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("G1 — FRAK_CODE=S (SPÖ), last 14 days", $BASE_URL, $p, $r, $c, $e, $ms,
    "Sanity check: does party filter work? SPÖ is historically active");

// G2 — All parties combined, last 14 days (should match A3 exactly)
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"],
      "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"],
      "FRAK_CODE" => ["S","V","F","G","N"], "DATUM_VON" => [$TWO_WEEKS, $TODAY]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("G2 — All main parties S+V+F+G+N, last 14 days", $BASE_URL, $p, $r, $c, $e, $ms,
    "If count < A3, some Anfragen are from minor parties not in this list");

// ============================================================
// SECTION H — PRODUCTION SIMULATION
// ============================================================
echo "\n\n" . str_repeat('*', 70) . "\n";
echo "*** SECTION H: EXACT PRODUCTION SIMULATION ***\n";
echo "*** Mimicking exactly what index.php and send-daily-emails.php do ***\n";
echo str_repeat('*', 70) . "\n";

// H1 — EXACT index.php call (XXVIII only in some time ranges)
$p = ["GP_CODE" => ["XXVIII"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("H1 — EXACT old test-api.php call (GP=XXVIII only)", $BASE_URL, $p, $r, $c, $e, $ms,
    "This is what the original test-api.php was calling. Explains the '25 results' mystery!");

// H2 — EXACT send-daily-emails.php call
$p = ["GP_CODE" => ["XXVIII","XXVII","XXVI","XXV"], "VHG" => ["J_JPR_M"], "DOKTYP" => ["J"]];
[$r, $c, $e, $ms] = callApi($BASE_URL, $p);
printTest("H2 — EXACT send-daily-emails.php call (GP=XXVIII+XXVII+XXVI+XXV)", $BASE_URL, $p, $r, $c, $e, $ms,
    "This is the ACTUAL production call. Should give the ~380 rows you remember.");

// ============================================================
// SUMMARY
// ============================================================
echo "\n\n" . str_repeat('#', 70) . "\n";
echo "# DIAGNOSTIC COMPLETE — " . $testNum . " tests run\n";
echo "# Finished at: " . date('Y-m-d H:i:s') . "\n";
echo "#\n";
echo "# KEY THINGS TO LOOK FOR:\n";
echo "#\n";
echo "# 1. PAGINATION BROKEN?\n";
echo "#    Compare F1 vs F3: if rows are DIFFERENT on page=2,\n";
echo "#    showAll=true is broken and we need to loop pages.\n";
echo "#\n";
echo "# 2. GP CODE IS THE ISSUE?\n";
echo "#    Compare H1 (25 rows) vs H2 (~380 rows expected).\n";
echo "#    The old test-api.php only queried XXVIII (new, sparse period).\n";
echo "#\n";
echo "# 3. DOKTYP FILTERING TOO MUCH?\n";
echo "#    Compare C1 (no DOKTYP) vs C2 (DOKTYP=J).\n";
echo "#    If C1 >> C2, DOKTYP=J is hiding a lot of data.\n";
echo "#\n";
echo "# 4. DATE FILTER WORKS?\n";
echo "#    Check D1 (today) and D2 (yesterday) counts.\n";
echo "#    If they work, the email sender can use DATUM_VON instead\n";
echo "#    of fetching ALL data and filtering in PHP.\n";
echo "#\n";
echo "# 5. SW KEYWORD FILTER WORKS?\n";
echo "#    Check E1 (SW=NGO). If it returns results, we can ask\n";
echo "#    the API for NGO inquiries directly instead of scanning everything.\n";
echo str_repeat('#', 70) . "\n";
