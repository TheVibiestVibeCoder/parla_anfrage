<?php
// ==========================================
// CONTACT FORM
// ==========================================

// Initialize variables
$success = false;
$error = false;
$errorMessage = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validate required fields
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $error = true;
        $errorMessage = 'Bitte füllen Sie alle Felder aus.';
    }
    // Validate email format
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = true;
        $errorMessage = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }
    // Send email
    else {
        $to = 'markus@disinfoconsulting.eu';
        $emailSubject = '[NGO Tracker Kontakt] ' . $subject;

        // Build email body
        $emailBody = "Neue Nachricht vom NGO Business Tracker Kontaktformular\n\n";
        $emailBody .= "Name: " . $name . "\n";
        $emailBody .= "E-Mail: " . $email . "\n";
        $emailBody .= "Betreff: " . $subject . "\n\n";
        $emailBody .= "Nachricht:\n" . $message . "\n";

        // Set headers
        $headers = array(
            'From: noreply@' . $_SERVER['HTTP_HOST'],
            'Reply-To: ' . $email,
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: text/plain; charset=UTF-8'
        );

        // Send email
        if (mail($to, $emailSubject, $emailBody, implode("\r\n", $headers))) {
            $success = true;
            // Clear form fields on success
            $name = $email = $subject = $message = '';
        } else {
            $error = true;
            $errorMessage = 'Beim Versenden der Nachricht ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontakt | "NGO Business" Tracker</title>

    <meta name="description" content="Kontaktieren Sie das Team hinter dem NGO Business Tracker. Feedback, Fragen oder Anmerkungen willkommen.">
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'bebas': ['"Bebas Neue"', 'cursive'],
                        'sans': ['"Inter"', 'sans-serif'],
                        'mono': ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        'brand-black': '#050505',
                        'brand-gray': '#1a1a1a',
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --bg-color: #000000;
            --text-color: #ffffff;
            --border-color: #333333;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            -webkit-font-smoothing: antialiased;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #000; 
        }
        ::-webkit-scrollbar-thumb {
            background: #333; 
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .container-custom {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        @media (min-width: 768px) {
            .container-custom {
                padding: 0 1.5rem;
            }
        }

        /* Input Animations */
        .form-input-container {
            position: relative;
        }
        
        .custom-input {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .custom-input:focus {
            border-color: #fff;
            padding-left: 1rem;
            background: rgba(255,255,255,0.03);
        }

        /* Button Hover Effect */
        .btn-glitch {
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .btn-glitch:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(255,255,255,0.1);
        }
    </style>
</head>
<body class="flex flex-col min-h-screen font-sans selection:bg-white selection:text-black">

<header class="w-full absolute top-0 z-50 bg-transparent">
    <div class="container mx-auto px-6 h-16 flex justify-between items-center">
        <a href="index.php" class="flex items-center gap-3 group">
            <div class="w-3 h-3 bg-white group-hover:bg-green-500 transition-colors duration-300"></div>
            
            <span class="font-bebas text-xl md:text-2xl tracking-widest text-white mt-1">
                <span class="md:hidden">NBT</span>
                <span class="hidden md:inline">NGO-Business Tracker</span>
            </span>
        </a>
</header>

    <main class="flex-grow pt-32 pb-20 px-6">
        <div class="container mx-auto max-w-2xl">

            <div class="mb-16 md:mb-24">
                <h1 class="font-bebas text-6xl md:text-8xl lg:text-9xl text-white leading-[0.85] mb-8">
                    KONTAKT
                </h1>
                <div class="h-px w-24 bg-white mb-8"></div>
                <p class="text-lg md:text-xl text-gray-400 font-light leading-relaxed max-w-xl">
                    Haben Sie Fragen, Feedback oder Anmerkungen zum NGO Business Tracker? 
                    Wir freuen uns über Input, um das Bild zu schärfen.
                </p>
            </div>

            <?php if ($success): ?>
                <div class="mb-12 p-6 border border-green-500/30 bg-green-500/5 backdrop-blur-sm">
                    <h3 class="font-bebas text-2xl text-green-500 mb-2">NACHRICHT GESENDET</h3>
                    <p class="text-green-400/80 font-mono text-sm">Vielen Dank. Wir melden uns in Kürze.</p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-12 p-6 border border-red-500/30 bg-red-500/5 backdrop-blur-sm">
                    <h3 class="font-bebas text-2xl text-red-500 mb-2">FEHLER</h3>
                    <p class="text-red-400/80 font-mono text-sm"><?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-12">

                <div class="form-input-container">
                    <label for="name" class="block font-bebas text-xl md:text-2xl text-gray-500 mb-2 tracking-wide">NAME *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="custom-input w-full py-4 text-lg md:text-xl font-sans text-white focus:outline-none placeholder-gray-800"
                        required
                        value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>"
                        placeholder="Ihr Name"
                    >
                </div>

                <div class="form-input-container">
                    <label for="email" class="block font-bebas text-xl md:text-2xl text-gray-500 mb-2 tracking-wide">E-MAIL *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="custom-input w-full py-4 text-lg md:text-xl font-sans text-white focus:outline-none placeholder-gray-800"
                        required
                        value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                        placeholder="ihre.email@adresse.at"
                    >
                </div>

                <div class="form-input-container">
                    <label for="subject" class="block font-bebas text-xl md:text-2xl text-gray-500 mb-2 tracking-wide">BETREFF *</label>
                    <input
                        type="text"
                        id="subject"
                        name="subject"
                        class="custom-input w-full py-4 text-lg md:text-xl font-sans text-white focus:outline-none placeholder-gray-800"
                        required
                        value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>"
                        placeholder="Kurz zusammengefasst"
                    >
                </div>

                <div class="form-input-container">
                    <label for="message" class="block font-bebas text-xl md:text-2xl text-gray-500 mb-2 tracking-wide">NACHRICHT *</label>
                    <textarea
                        id="message"
                        name="message"
                        rows="6"
                        class="custom-input w-full py-4 text-lg md:text-xl font-sans text-white focus:outline-none placeholder-gray-800 resize-y min-h-[150px]"
                        required
                        placeholder="Ihre Nachricht an uns..."
                    ><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                </div>

                <div class="pt-8">
                    <button type="submit" class="btn-glitch w-full md:w-auto bg-white text-black font-bebas text-2xl md:text-3xl px-12 py-4 hover:bg-transparent hover:text-white border-2 border-white transition-all uppercase tracking-widest">
                        Nachricht Senden
                    </button>
                    <p class="mt-4 text-xs font-mono text-gray-600">
                        * PFLICHTFELDER
                    </p>
                </div>

            </form>

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

</body>
</html>