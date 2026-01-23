<?php
// ==========================================
// MAILING LIST SIGNUP PAGE
// ==========================================

require_once __DIR__ . '/MailingListDB.php';

// Initialize variables
$success = false;
$reactivated = false;
$error = false;
$errorMessage = '';
$db = null;

// Get client IP (handle proxies)
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Check for proxied IP (but validate it)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIPs = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $firstIP = trim($forwardedIPs[0]);
        if (filter_var($firstIP, FILTER_VALIDATE_IP)) {
            $ip = $firstIP;
        }
    }

    return $ip;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = new MailingListDB();
        $clientIP = getClientIP();

        // Rate limiting check (DDoS protection)
        if ($db->checkRateLimit($clientIP, 'signup', 3, 60)) {
            $error = true;
            $errorMessage = 'Zu viele Anmeldeversuche. Bitte versuchen Sie es in einer Stunde erneut.';
        } else {
            // Log attempt for rate limiting
            $db->logRateLimitAttempt($clientIP, 'signup');

            // Get and validate form data
            $email = trim($_POST['email'] ?? '');
            $gdprConsent = isset($_POST['gdpr_consent']) && $_POST['gdpr_consent'] === '1';

            // Validate required fields
            if (empty($email)) {
                $error = true;
                $errorMessage = 'Bitte geben Sie Ihre E-Mail-Adresse ein.';
            }
            // Validate email format
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = true;
                $errorMessage = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
            // Check GDPR consent
            elseif (!$gdprConsent) {
                $error = true;
                $errorMessage = 'Bitte stimmen Sie der Datenschutzerklärung und Datenverarbeitung zu.';
            }
            // Add subscriber
            else {
                try {
                    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    $result = $db->addSubscriber($email, $clientIP, $userAgent);

                    if ($result['success']) {
                        $success = true;
                        $reactivated = $result['reactivated'] ?? false;
                        $email = ''; // Clear form
                    }
                } catch (Exception $e) {
                    $error = true;
                    $errorMessage = $e->getMessage();
                }
            }
        }

        // Clean old rate limit entries periodically
        if (rand(1, 100) === 1) {
            $db->cleanOldRateLimits(24);
        }

    } catch (Exception $e) {
        $error = true;
        $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        error_log('Mailing list signup error: ' . $e->getMessage());
    }
}

// Get current subscriber count
$subscriberCount = 0;
try {
    if (!$db) {
        $db = new MailingListDB();
    }
    $subscriberCount = $db->getSubscriberCount();
} catch (Exception $e) {
    error_log('Error getting subscriber count: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de" prefix="og: https://ogp.me/ns#">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Newsletter | "NGO Business" Tracker</title>
    <meta name="description" content="Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business. Bleiben Sie informiert über die neuesten Entwicklungen.">
    <meta name="robots" content="index, follow">

    <meta property="og:type" content="website">
    <meta property="og:title" content="Newsletter | NGO Business Tracker">
    <meta property="og:description" content="Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business.">
    <meta property="og:locale" content="de_AT">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    
    <link rel="preload" href="https://fonts.gstatic.com/s/bebasneue/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuI6fMZhrib2Bg-4.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v24/tDbv2o-flEEny0FZhsfKu5WU5zr3E_BX0zS8.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">

    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="styles.css"> <style>
        /* Essential styles copied/adapted to match index.php look even without external css */
        :root {
            --bg-color: #050505;
            --text-color: #ffffff;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
        }

        h1, h2, h3, h4, .font-bebas {
            font-family: 'Bebas Neue', sans-serif;
        }

        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .container-custom {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        /* Input styling to match the raw/investigative look */
        .investigative-input {
            width: 100%;
            background-color: transparent;
            border: 1px solid #333;
            color: #fff;
            padding: 1rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .investigative-input:focus {
            outline: none;
            border-color: #fff;
        }

        .investigative-btn {
            background-color: #fff;
            color: #000;
            border: 1px solid #fff;
            padding: 1rem 2rem;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .investigative-btn:hover {
            background-color: transparent;
            color: #fff;
        }

        /* Checkbox custom styling */
        .investigative-checkbox {
            appearance: none;
            background-color: transparent;
            margin: 0;
            font: inherit;
            color: currentColor;
            width: 1.25rem;
            height: 1.25rem;
            border: 1px solid #fff;
            display: grid;
            place-content: center;
            cursor: pointer;
        }

        .investigative-checkbox::before {
            content: "";
            width: 0.65em;
            height: 0.65em;
            transform: scale(0);
            transition: 120ms transform ease-in-out;
            box-shadow: inset 1em 1em white;
        }

        .investigative-checkbox:checked::before {
            transform: scale(1);
        }

        .stat-value {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 3rem;
            line-height: 1;
            color: #fff;
        }

        .stat-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #9ca3af;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-black">

    <header class="w-full absolute top-0 z-50 bg-transparent">
        <div class="container mx-auto px-6 h-16 flex justify-between items-center">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-3 h-3 bg-white group-hover:bg-green-500 transition-colors duration-300"></div>
                
                <span class="font-bebas text-xl md:text-2xl tracking-widest text-white mt-1">
                    <span class="md:hidden">NBT</span>
                    <span class="hidden md:inline">NGO-Business Tracker</span>
                </span>
            </a>
            
            <nav class="hidden md:flex gap-8">
                <a href="index.php" class="text-xs font-mono text-gray-400 hover:text-white uppercase tracking-widest transition-colors">Dashboard</a>
            </nav>
        </div>
    </header>

    <section class="flex flex-col justify-center items-center text-center bg-black border-b border-white px-4 py-6 md:px-6 md:py-8 lg:py-12 pt-32 pb-16">
        <div class="max-w-4xl mx-auto w-full">
            <span class="inline-block border-b border-gray-600 pb-1 mb-4 md:mb-6 text-[10px] md:text-xs font-mono text-gray-400 uppercase tracking-[0.2em]">Service</span>
            <h1 class="text-5xl sm:text-6xl md:text-7xl lg:text-8xl text-white leading-[0.9] mb-4 md:mb-6 break-words tracking-tight font-bebas">
                Newsletter<br>Alert
            </h1>
            <p class="text-sm md:text-base lg:text-lg text-gray-300 font-sans leading-relaxed max-w-2xl mx-auto mt-6">
                Erhalten Sie täglich Updates über neue parlamentarische Anfragen zum Thema NGO-Business.
            </p>
        </div>
    </section>

    <main class="container-custom py-16 md:py-24">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-16">
            
            <div class="lg:col-span-4 flex flex-col gap-10">
                
                <div class="border-l-4 border-white pl-6 py-2">
                    <div class="stat-label">Aktive Abonnent:innen</div>
                    <div class="stat-value"><?php echo number_format($subscriberCount, 0, ',', '.'); ?></div>
                    <div class="text-sm font-sans text-gray-400 mt-2 italic">Personen werden bereits informiert.</div>
                </div>

                <div class="border border-gray-800 p-6 bg-gray-900/30">
                    <h3 class="font-bebas text-2xl mb-4 text-white">Das Protokoll</h3>
                    <ul class="space-y-4">
                        <li class="flex gap-3 items-start">
                            <span class="font-mono text-xs text-gray-500 mt-1">01</span>
                            <span class="text-sm text-gray-300 font-sans"><strong>Täglicher Scan:</strong> Das System prüft täglich um 20:00 Uhr auf neue Anfragen.</span>
                        </li>
                        <li class="flex gap-3 items-start">
                            <span class="font-mono text-xs text-gray-500 mt-1">02</span>
                            <span class="text-sm text-gray-300 font-sans"><strong>Report:</strong> Neue Treffer werden zusammengefasst zugesendet.</span>
                        </li>
                        <li class="flex gap-3 items-start">
                            <span class="font-mono text-xs text-gray-500 mt-1">03</span>
                            <span class="text-sm text-gray-300 font-sans"><strong>Zero Spam:</strong> Keine Anfragen = Keine E-Mail (oder eine kurze Statusmeldung).</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="lg:col-span-8">
                
                <div class="border-t-2 border-white pt-8">
                    <div class="flex items-start mb-8">
                        <h2 class="text-3xl md:text-4xl text-white font-bebas leading-none">Anmeldung<br><span class="text-gray-500 text-lg font-sans font-normal tracking-normal">Tragen Sie sich in den Verteiler ein</span></h2>
                    </div>

                    <?php if ($success): ?>
                        <div class="mb-8 border border-green-900 bg-green-900/10 p-4 flex gap-4 items-start">
                            <div class="text-green-500 font-mono text-xl">✓</div>
                            <div>
                                <h3 class="text-green-500 font-bold font-mono text-sm uppercase tracking-wider mb-1">
                                    <?php echo $reactivated ? 'REAKTIVIERT' : 'ERFOLGREICH'; ?>
                                </h3>
                                <p class="text-gray-300 text-sm font-sans">
                                    Sie erhalten ab heute täglich um 20:00 Uhr Updates.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="mb-8 border border-red-900 bg-red-900/10 p-4 flex gap-4 items-start">
                            <div class="text-red-500 font-mono text-xl">!</div>
                            <div>
                                <h3 class="text-red-500 font-bold font-mono text-sm uppercase tracking-wider mb-1">FEHLER</h3>
                                <p class="text-gray-300 text-sm font-sans"><?php echo htmlspecialchars($errorMessage); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-6 max-w-xl">
                        <div>
                            <label for="email" class="block text-[10px] font-mono text-gray-500 mb-2 uppercase tracking-widest">
                                E-Mail-Adresse
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="investigative-input"
                                placeholder="name@beispiel.at"
                                value="<?php echo htmlspecialchars($email ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="pt-4">
                            <label class="flex items-start gap-4 cursor-pointer group">
                                <input
                                    type="checkbox"
                                    name="gdpr_consent"
                                    value="1"
                                    class="investigative-checkbox mt-1 group-hover:border-gray-400 transition-colors"
                                    required
                                >
                                <span class="text-xs text-gray-400 font-sans leading-relaxed group-hover:text-gray-300 transition-colors">
                                    Ich stimme zu, dass meine E-Mail-Adresse zum Versand des täglichen Newsletters gespeichert und verarbeitet wird. Ich kann mich jederzeit über den Link im Newsletter wieder abmelden.
                                </span>
                            </label>
                        </div>

                        <div class="pt-6">
                            <button type="submit" class="investigative-btn">
                                Aufnahme in Verteiler
                            </button>
                        </div>

                        <div class="text-center pt-4">
                            <p class="text-[10px] text-gray-600 font-mono uppercase tracking-widest">
                                RATE LIMIT ACTIVE: MAX 3 REQUESTS/HOUR
                            </p>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </main>

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
                        Hinweis: Diese Plattform ist experimentell. Fehler können vorkommen.
                    </div>
                    <div class="text-xs font-mono text-gray-600">
                          © <?php echo date('Y'); ?> "NGO BUSINESS" TRACKER
                    </div>
                    <div class="mt-2 space-x-4">
                        <a href="impressum.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Impressum</a>
                        <a href="kontakt.php" class="text-xs font-mono text-gray-500 hover:text-white transition-colors underline">Kontakt</a>
                        <a href="index.php" class="text-xs font-mono text-blue-400 hover:text-blue-300 transition-colors underline">Dashboard</a>
                    </div>
                </div>

                <div class="text-left md:text-right w-full md:w-auto">
                    <div class="text-xs font-mono text-gray-500 mb-2">SERVICE: NEWSLETTER</div>
                    <div class="text-xs font-mono text-gray-500 mb-2">DELIVERY: 20:00 CET</div>
                    <div class="flex items-center justify-start md:justify-end gap-2 mt-4">
                        <div class="w-2 h-2 bg-green-600 rounded-full"></div>
                        <span class="text-xs font-mono text-green-600">SYSTEM OPERATIONAL</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>