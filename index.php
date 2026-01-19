<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impressum - Disinfo Awareness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --bg-color: #111111;
            --text-color: #e5e5e5;
            --text-muted: #a3a3a3;
            --border-color: #333333;
            
            --font-head: 'Bebas Neue', sans-serif;
            --font-body: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: var(--font-body);
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3 { 
            font-family: var(--font-head); 
            font-weight: 400;
            letter-spacing: 1px;
        }

        .font-mono { font-family: var(--font-mono); }
        .font-head { font-family: var(--font-head); }

        /* Investigative Box Style from Template */
        .investigative-box {
            border-top: 4px solid #ffffff;
            padding-top: 1.5rem;
            margin-bottom: 2rem;
        }

        .data-label {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            display: block;
        }

        .data-value {
            font-family: var(--font-body);
            color: #ffffff;
            font-size: 1.1rem;
            line-height: 1.5;
        }

        /* Hover effects for links */
        .back-link:hover .arrow {
            transform: translateX(-4px);
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <header class="container mx-auto max-w-[1200px] px-4 md:px-6 pt-12 md:pt-16 mb-16">
        <a href="index.php" class="back-link inline-flex items-center gap-2 text-xs font-mono text-gray-500 hover:text-white transition-colors mb-8 group">
            <span class="arrow transition-transform duration-200">&larr;</span> ZURÜCK ZUR HAUPTSEITE
        </a>

        <div class="border-b border-gray-800 pb-8">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-2 h-2 bg-red-600 rounded-full animate-pulse"></div>
                <span class="text-[10px] md:text-xs font-mono uppercase tracking-widest text-red-600">Disinfo Awareness</span>
            </div>
            
            <h1 class="text-6xl md:text-8xl lg:text-9xl text-white leading-[0.9] mb-6">
                Offenlegung<br><span class="text-gray-600">Impressum</span>
            </h1>

            <p class="font-mono text-xs md:text-sm text-gray-500 max-w-2xl">
                Informationen gemäß §5 (1) ECG, § 25 MedienG, § 63 GewO und § 14 UGB.
            </p>
        </div>
    </header>

    <main class="container mx-auto max-w-[1200px] px-4 md:px-6 mb-20 flex-grow">

        <section class="mb-20">
            <h2 class="text-4xl md:text-5xl text-white mb-8 border-l-4 border-red-600 pl-4">Vereinsdaten</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-12 gap-8 md:gap-12">
                <div class="md:col-span-12 investigative-box">
                    <span class="data-label">Vollständiger Name</span>
                    <div class="data-value text-xl md:text-2xl font-head tracking-wide">
                        Disinfo Awareness - Verein zur Aufklärung über Desinformation und FIMI (Foreign Information Manipulation Interference) zur Stärkung der Informationsresilienz
                    </div>
                </div>

                <div class="md:col-span-4 investigative-box border-t-[1px] border-gray-700 pt-6">
                    <span class="data-label">ZVR-Zahl</span>
                    <div class="font-mono text-xl text-white">1154237575</div>
                </div>

                <div class="md:col-span-4 investigative-box border-t-[1px] border-gray-700 pt-6">
                    <span class="data-label">Kontakt</span>
                    <a href="mailto:markus@disinfoawareness.eu" class="data-value hover:text-red-500 transition-colors border-b border-gray-700 pb-1">markus@disinfoawareness.eu</a>
                </div>

                <div class="md:col-span-4 investigative-box border-t-[1px] border-gray-700 pt-6">
                    <span class="data-label">Zustellanschrift</span>
                    <div class="data-value">
                        Staudingergasse 8/6<br>
                        1200 Wien<br>
                        Österreich
                    </div>
                </div>

                <div class="md:col-span-12 investigative-box border-t-[1px] border-gray-700 pt-6">
                    <span class="data-label">Zuständige Behörde</span>
                    <div class="data-value">Landespolizeidirektion Wien, Referat Vereins-, Versammlungs- und Medienrechtsangelegenheiten</div>
                </div>
            </div>
        </section>

        <section class="mb-20">
            <h2 class="text-4xl md:text-5xl text-white mb-8 border-l-4 border-white pl-4">Vertretung</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12">
                <div class="investigative-box">
                    <span class="data-label">Obmann</span>
                    <div class="text-3xl font-head text-white">Markus Schwinghammer</div>
                </div>

                <div class="investigative-box">
                    <span class="data-label">Obmann-Stellvertreter</span>
                    <div class="text-3xl font-head text-white">Mag. Robert Buchhaus</div>
                </div>

                <div class="md:col-span-2 mt-4 p-6 bg-[#1a1a1a] border-l-2 border-gray-600">
                    <span class="data-label mb-2 block">Vertretungsregelung</span>
                    <p class="text-gray-300 font-sans leading-relaxed">
                        Der/Die Obmann/Obfrau vertritt den Verein nach außen. Der/Die Stellvertreter/in vertritt ihn/sie im Falle der Verhinderung.
                    </p>
                </div>
            </div>
        </section>

        <section class="mb-12">
            <div class="flex items-end justify-between border-b-4 border-white pb-4 mb-10">
                <h2 class="text-4xl md:text-5xl text-white">Rechtliches</h2>
                <span class="font-mono text-xs text-gray-500">LEGAL_FRAMEWORK</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">
                
                <div>
                    <h3 class="text-2xl text-white mb-4">Urheberrecht</h3>
                    <div class="text-sm text-gray-400 leading-relaxed space-y-4">
                        <p>Die Inhalte dieser Webseite unterliegen, soweit dies rechtlich möglich ist, diversen Schutzrechten (z.B. dem Urheberrecht). Jegliche Verwendung oder Verbreitung von bereitgestelltem Material, welche urheberrechtlich untersagt ist, bedarf schriftlicher Zustimmung des Webseitenbetreibers.</p>
                        <p>Die Urheberrechte Dritter werden vom Betreiber dieser Webseite mit größter Sorgfalt beachtet. Sollten Sie trotzdem auf eine Urheberrechtsverletzung aufmerksam werden, bitten wir um einen entsprechenden Hinweis. Bei Bekanntwerden derartiger Rechtsverletzungen werden wir den betroffenen Inhalt umgehend entfernen.</p>
                    </div>
                </div>

                <div>
                    <h3 class="text-2xl text-white mb-4">Haftungsausschluss</h3>
                    <div class="text-sm text-gray-400 leading-relaxed">
                        <p>Trotz sorgfältiger inhaltlicher Kontrolle übernimmt der Webseitenbetreiber dieser Webseite keine Haftung für die Inhalte externer Links. Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich. Sollten Sie dennoch auf ausgehende Links aufmerksam werden, welche auf eine Webseite mit rechtswidriger Tätigkeit oder Information verweisen, ersuchen wir um dementsprechenden Hinweis, um diese nach § 17 Abs. 2 ECG umgehend zu entfernen.</p>
                    </div>
                </div>

                <div>
                    <h3 class="text-2xl text-white mb-4">Zweck</h3>
                    <div class="text-sm text-gray-400 leading-relaxed border-l-2 border-red-900 pl-4 bg-red-900/10 py-2 pr-2">
                        <p>Information über die Tätigkeit des Vereins sowie Förderung der Medienkompetenz und Resilienz gegen Desinformation.</p>
                    </div>
                </div>

            </div>
        </section>

    </main>

    <footer class="bg-black border-t border-white py-8 md:py-12 mt-auto">
        <div class="container mx-auto max-w-[1200px] px-4 md:px-6">
            <div class="flex flex-col md:flex-row justify-between items-end gap-6">
                <div>
                    <div class="text-xs font-mono text-gray-500 mb-2 uppercase tracking-widest">Disinfo Awareness</div>
                    <div class="text-xs font-mono text-gray-600">
                         © 2026 Disinfo Awareness. Wien, Österreich.
                    </div>
                </div>
                
                <div class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-2 h-2 bg-green-600 rounded-full"></div>
                        <span class="text-xs font-mono text-green-600">SYSTEM OPERATIONAL</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>