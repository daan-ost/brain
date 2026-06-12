@extends('layouts.homepage-standalone')

@section('title', app()->getLocale() === 'nl' ? 'Privacybeleid' : 'Privacy Policy')

@section('content')
<div class="min-h-screen bg-white">
    @include('components.header')

    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="prose prose-lg max-w-none">
            @if(app()->getLocale() === 'nl')
            {{-- Dutch version --}}
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Privacybeleid — {{ config('app.name') }}</h1>
            <p class="text-sm text-gray-500 mb-8">
                Versie 2025 / Laatst bijgewerkt: december 2025
            </p>

            <p class="mb-4">
                {{ config('app.name') }} is een dienst van <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }} B.V.</strong>, gevestigd te:<br>
                {{ env('INVOICE_COMPANY_ADDRESS', 'Zijpendaalseweg 51A') }}, {{ env('INVOICE_COMPANY_POSTAL_CODE', '6814 CD') }} {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}, Nederland<br>
                KvK: <strong>{{ env('INVOICE_COMPANY_COC', '62291564') }}</strong>
            </p>

            <p class="mb-6">
                Interus B.V. ("{{ config('app.name') }}", "wij", "ons") hecht grote waarde aan de bescherming van uw privacy.
                Dit Privacybeleid legt uit welke persoonsgegevens wij verwerken, waarom wij deze verwerken, hoe lang wij deze bewaren,
                en welke rechten u hebt op grond van de AVG. De meest recente versie van dit Privacybeleid is altijd
                beschikbaar op onze website.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">1. Hoe wij met uw gegevens omgaan</h2>
            <p class="mb-4">
                Wij verwerken alleen persoonsgegevens wanneer dit noodzakelijk is om onze diensten te leveren, te beveiligen en te onderhouden,
                of wanneer dit wettelijk verplicht is. Wij nemen passende technische en organisatorische maatregelen om
                uw gegevens te beschermen tegen verlies, misbruik en ongeoorloofde toegang.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">2. Welke gegevens wij verwerken en waarvoor</h2>
            <p class="mb-4">
                Wij onderscheiden verschillende categorieën verwerkingsactiviteiten, afhankelijk van hoe u onze diensten gebruikt.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2a. Gegevens nodig voor onze PDF-diensten (SaaS-functionaliteit)</h3>
            <p class="mb-4">
                Wanneer u bestanden uploadt of een van onze PDF-tools gebruikt, verwerken wij:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Geüploade bestanden</li>
                <li>Conversieresultaten</li>
                <li>Bestandsmetadata (zoals bestandsnaam, bestandstype en tijdstempels)</li>
            </ul>
            <p class="mb-4">
                Deze bestanden kunnen persoonsgegevens bevatten. Voor deze gegevens geldt het volgende:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Wij bekijken, inspecteren, analyseren of kopiëren uw bestanden <strong>niet</strong> voor eigen doeleinden.</li>
                <li>Geüploade bestanden worden <strong>automatisch verwijderd na 1 uur</strong>.</li>
                <li>Conversieresultaten worden <strong>automatisch verwijderd na 14 dagen</strong>, tenzij:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>u deze eerder zelf verwijdert; of</li>
                        <li>uw organisatie een andere bewaartermijn heeft ingesteld.</li>
                    </ul>
                </li>
            </ul>
            <p class="mb-4">
                Wij handelen als <strong>verwerkingsverantwoordelijke</strong> voor deze verwerkingsactiviteiten, aangezien wij bepalen hoe de
                dienst technisch functioneert. Supportmedewerkers kunnen alleen toegang krijgen tot bestanden wanneer dit strikt noodzakelijk is
                om uw supportverzoek te behandelen en alleen met uw uitdrukkelijke toestemming.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2b. Gegevens nodig voor uw account en administratie</h3>
            <p class="mb-4">
                Wanneer u een account aanmaakt of een betaalde licentie of abonnement aanschaft, verwerken wij:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Naam</li>
                <li>E-mailadres</li>
                <li>Wachtwoord (opgeslagen in gehashte vorm)</li>
                <li>Bedrijfsgegevens (optioneel)</li>
                <li>Factuuradres</li>
                <li>BTW-nummer (optioneel)</li>
                <li>Licentie- en abonnementsinformatie</li>
                <li>IP-adres bij inloggen (voor beveiligingsdoeleinden)</li>
            </ul>
            <p class="mb-4">
                Wij gebruiken deze gegevens om:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>uw account aan te maken en te beheren;</li>
                <li>uw licenties, credits en abonnementen te beheren;</li>
                <li>facturen uit te reiken en betalingen te verwerken via externe betalingsdienstaanbieders;</li>
                <li>contact met u op te nemen over dienstgerelateerde informatie, zoals belangrijke wijzigingen of incidenten.</li>
            </ul>
            <p class="mb-4">
                Als u deze gegevens niet verstrekt, kunnen wij onze diensten mogelijk niet naar behoren uitvoeren.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2c. Nieuwsbrieven en optionele communicatie</h3>
            <p class="mb-4">
                Als u zich aanmeldt voor onze nieuwsbrief, verwerken wij:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>uw e-mailadres</li>
            </ul>
            <p class="mb-4">
                Wij gebruiken uw e-mailadres uitsluitend om te verzenden:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>nieuwsbrieven over {{ config('app.name') }}; en</li>
                <li>belangrijke updates over onze diensten.</li>
            </ul>
            <p class="mb-4">
                U kunt zich op elk moment afmelden via de link in elke e-mail. {{ config('app.name') }} verstuurt geen
                advertenties van derden of partnerpromoties op basis van uw persoonsgegevens.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2d. Beveiliging, foutenanalyse en systeemlogboeken</h3>
            <p class="mb-4">
                Om de stabiliteit en veiligheid van ons platform te waarborgen, verwerken wij technische gegevens zoals:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>IP-adres;</li>
                <li>browser- en apparaatinformatie;</li>
                <li>foutenlogboeken en diagnostische informatie;</li>
                <li>server- en applicatielogboeken.</li>
            </ul>
            <p class="mb-4">
                Deze verwerking is noodzakelijk om:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>misbruik en beveiligingsincidenten te voorkomen;</li>
                <li>technische problemen te diagnosticeren en op te lossen;</li>
                <li>prestaties te monitoren en betrouwbaarheid te verbeteren.</li>
            </ul>
            <p class="mb-4">
                Wij gebruiken <strong>geen</strong> tracking cookies, profilering of gepersonaliseerde advertenties.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">3. Cookies</h2>
            <p class="mb-4">
                {{ config('app.name') }} gebruikt:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>functionele cookies</strong>, die noodzakelijk zijn voor de goede werking van de website
                    (zoals inlog- en taalinstellingen); en</li>
                <li><strong>analytische cookies</strong> die geen persoonlijk identificeerbare informatie bevatten.</li>
            </ul>
            <p class="mb-4">
                {{ config('app.name') }} gebruikt <strong>geen</strong>:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>tracking cookies;</li>
                <li>marketing cookies; of</li>
                <li>cookies voor profilering of gepersonaliseerde advertenties.</li>
            </ul>
            <p class="mb-4">
                Een cookiemelding wordt alleen getoond wanneer dit wettelijk verplicht is.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">4. Rechtsgrondslag voor verwerking</h2>
            <p class="mb-4">
                Wij verwerken persoonsgegevens op basis van de volgende rechtsgronden onder de AVG:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>Uitvoering van een overeenkomst</strong>: voor het leveren van onze PDF-diensten, het beheren van uw account,
                    en het afhandelen van facturering en betalingen.</li>
                <li><strong>Wettelijke verplichting</strong>: voor het bewaren van administratieve en fiscale gegevens.</li>
                <li><strong>Gerechtvaardigd belang</strong>: voor beveiliging, monitoring en verbetering van onze diensten.</li>
                <li><strong>Toestemming</strong>: voor het verzenden van nieuwsbrieven, indien u zich uitdrukkelijk hebt aangemeld.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">5. Ontvangers van persoonsgegevens</h2>
            <p class="mb-4">
                Wij delen uw persoonsgegevens alleen met derden wanneer dit noodzakelijk is om:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>onze infrastructuur te hosten en te beveiligen;</li>
                <li>onze diensten betrouwbaar te leveren;</li>
                <li>betalingen te verwerken via externe betalingsdienstaanbieders; en</li>
                <li>transactionele e-mails en systeemmeldingen te verzenden.</li>
            </ul>
            <p class="mb-4">
                Deze derden handelen als verwerkers en:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>handelen alleen volgens onze schriftelijke instructies;</li>
                <li>mogen uw gegevens niet voor eigen doeleinden gebruiken; en</li>
                <li>zijn verplicht passende beveiligingsmaatregelen te implementeren.</li>
            </ul>
            <p class="mb-4">
                Wij vermelden niet alle individuele leveranciers bij naam in dit beleid. Dit is toegestaan onder de
                AVG zolang de categorieën ontvangers duidelijk worden beschreven.
            </p>
            <p class="mb-4">
                Wij verstrekken persoonsgegevens aan rechtshandhavingsinstanties of toezichthouders alleen wanneer wij
                hiertoe wettelijk verplicht zijn en alleen op een specifiek, rechtmatig verzoek.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">6. Bewaartermijnen</h2>
            <p class="mb-4">
                Wij bewaren persoonsgegevens niet langer dan noodzakelijk voor de doeleinden beschreven in dit Privacybeleid,
                tenzij een langere bewaartermijn wettelijk verplicht is.
            </p>
            <table class="w-full border border-gray-300 text-sm mb-4">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-gray-300 px-3 py-2 text-left">Type gegevens</th>
                        <th class="border border-gray-300 px-3 py-2 text-left">Bewaartermijn</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Geüploade bestanden</td>
                        <td class="border border-gray-300 px-3 py-2">1 uur na upload</td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Conversieresultaten</td>
                        <td class="border border-gray-300 px-3 py-2">
                            14 dagen na creatie (tenzij eerder verwijderd of anders ingesteld door uw organisatie)
                        </td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Accountgegevens</td>
                        <td class="border border-gray-300 px-3 py-2">Zolang uw account actief is</td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Administratieve en factureringsgegevens</td>
                        <td class="border border-gray-300 px-3 py-2">
                            7 jaar, conform wettelijke fiscale en boekhoudkundige verplichtingen
                        </td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Technische en loggegevens</td>
                        <td class="border border-gray-300 px-3 py-2">Maximaal 90 dagen</td>
                    </tr>
                </tbody>
            </table>

            <h2 class="text-2xl font-semibold mt-8 mb-3">7. Uw rechten onder de AVG</h2>
            <p class="mb-4">
                U hebt de volgende rechten met betrekking tot uw persoonsgegevens:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>Recht op inzage</strong> – om bevestiging te krijgen of wij uw gegevens verwerken en, zo ja, een kopie te ontvangen.</li>
                <li><strong>Recht op rectificatie</strong> – om onjuiste of onvolledige gegevens te laten corrigeren.</li>
                <li><strong>Recht op wissing</strong> – om verwijdering van uw gegevens te verzoeken wanneer deze niet langer noodzakelijk zijn of wanneer verwerking onrechtmatig is.</li>
                <li><strong>Recht op beperking</strong> – om een tijdelijke pauze in de verwerking te verzoeken onder bepaalde omstandigheden (bijvoorbeeld als u de juistheid van de gegevens betwist).</li>
                <li><strong>Recht van bezwaar</strong> – om bezwaar te maken tegen verwerking op basis van gerechtvaardigd belang.</li>
                <li><strong>Recht op gegevensoverdraagbaarheid</strong> – om uw gegevens te ontvangen in een gestructureerd, gangbaar en machineleesbaar formaat en deze gegevens over te dragen aan een andere verwerkingsverantwoordelijke, waar technisch haalbaar.</li>
            </ul>

            <h3 class="text-xl font-semibold mt-6 mb-2">Betaalde klanten</h3>
            <p class="mb-4">
                Als u een actieve betaalde licentie of abonnement hebt, kunt u uw verzoek rechtstreeks indienen via
                <a href="mailto:{{ config('mail.from.address') }}" class="text-blue-600 hover:text-blue-800">{{ config('mail.from.address') }}</a>.
                Verzoeken van betalende klanten worden met prioriteit behandeld en altijd binnen één maand.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">Gratis en gastgebruikers</h3>
            <p class="mb-4">
                Als u {{ config('app.name') }} gebruikt zonder betaald account, kunt u een AVG-verzoek indienen via ons online privacyverzoekformulier
                op de website. Wij reageren binnen de wettelijke termijn van één maand.
            </p>
            <p class="mb-4">
                Als u van mening bent dat wij uw gegevens verwerken in strijd met de toepasselijke privacywetgeving, hebt u het recht om
                een klacht in te dienen bij de Autoriteit Persoonsgegevens.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">8. Contactgegevens</h2>
            <p class="mb-4">
                Als u vragen hebt over dit Privacybeleid of over hoe wij met uw persoonsgegevens omgaan, kunt u contact met ons opnemen via:
            </p>
            <p class="mb-2">
                <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }} B.V.</strong><br>
                {{ env('INVOICE_COMPANY_ADDRESS', 'Zijpendaalseweg 51A') }}<br>
                {{ env('INVOICE_COMPANY_POSTAL_CODE', '6814 CD') }} {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}<br>
                Nederland
            </p>
            <p class="mb-4">
                E-mail: <a href="mailto:{{ env('INVOICE_COMPANY_EMAIL', '{{ config('mail.from.address') }}') }}" class="text-blue-600 hover:text-blue-800">{{ env('INVOICE_COMPANY_EMAIL', '{{ config('mail.from.address') }}') }}</a>
            </p>

            @else
            {{-- English version --}}
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Privacy Policy — {{ config('app.name') }}</h1>
            <p class="text-sm text-gray-500 mb-2">
                Version 2025 / Last updated: December 2025
            </p>
            <p class="text-sm text-gray-600 mb-8">
                <em>This is an English translation. In case of discrepancies, the Dutch version prevails.</em>
            </p>

            <p class="mb-4">
                {{ config('app.name') }} is a service provided by <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }} B.V.</strong>, located at:<br>
                {{ env('INVOICE_COMPANY_ADDRESS', 'Zijpendaalseweg 51A') }}, {{ env('INVOICE_COMPANY_POSTAL_CODE', '6814 CD') }} {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}, {{ env('INVOICE_COMPANY_COUNTRY', 'The Netherlands') }}<br>
                Chamber of Commerce (KvK): <strong>{{ env('INVOICE_COMPANY_COC', '62291564') }}</strong>
            </p>

            <p class="mb-6">
                Interus B.V. ("{{ config('app.name') }}", "we", "us") attaches great importance to the protection of your privacy.
                This Privacy Policy explains which personal data we process, why we process it, how long we retain it,
                and what rights you have under the GDPR. The most recent version of this Privacy Policy is always
                available on our website.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">1. How we handle your data</h2>
            <p class="mb-4">
                We only process personal data when necessary to provide, secure, and maintain our services,
                or when required by law. We take appropriate technical and organizational measures to protect
                your data from loss, misuse, and unauthorized access.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">2. Which data we process and for what purpose</h2>
            <p class="mb-4">
                We distinguish several categories of processing activities, depending on how you use our services.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2a. Data required to deliver our PDF services (SaaS functionality)</h3>
            <p class="mb-4">
                When you upload files or use any of our PDF tools, we process:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Uploaded files</li>
                <li>Conversion results</li>
                <li>File metadata (such as filename, file type, and timestamps)</li>
            </ul>
            <p class="mb-4">
                These files may contain personal data. For these data, the following applies:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>We do <strong>not</strong> view, inspect, analyze, or copy your files for our own purposes.</li>
                <li>Uploaded files are <strong>automatically deleted after 1 hour</strong>.</li>
                <li>Conversion results are <strong>automatically deleted after 14 days</strong>, unless:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>you remove them earlier yourself; or</li>
                        <li>your organization has configured a different retention period.</li>
                    </ul>
                </li>
            </ul>
            <p class="mb-4">
                We act as the <strong>data controller</strong> for these processing activities, as we determine how the
                service functions technically. Support staff can only access files when strictly necessary to handle your
                support request and only with your explicit consent.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2b. Data required for your account and administration</h3>
            <p class="mb-4">
                When you create an account or purchase a paid license or subscription, we process:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Name</li>
                <li>Email address</li>
                <li>Password (stored in hashed form)</li>
                <li>Company details (optional)</li>
                <li>Billing address</li>
                <li>VAT number (optional)</li>
                <li>License and subscription information</li>
                <li>IP address at login (for security purposes)</li>
            </ul>
            <p class="mb-4">
                We use these data to:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>create and manage your account;</li>
                <li>manage your licenses, credits, and subscriptions;</li>
                <li>issue invoices and process payments via external payment service providers;</li>
                <li>contact you about service-related information, such as important changes or incidents.</li>
            </ul>
            <p class="mb-4">
                If you do not provide these data, we may not be able to properly perform our services.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2c. Newsletters and optional communications</h3>
            <p class="mb-4">
                If you subscribe to our newsletter, we process:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>your email address</li>
            </ul>
            <p class="mb-4">
                We use your email address exclusively to send:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>newsletters about {{ config('app.name') }}; and</li>
                <li>important updates about our services.</li>
            </ul>
            <p class="mb-4">
                You can unsubscribe at any time by using the link included in each email. {{ config('app.name') }} does not send
                third-party advertising or partner promotions based on your personal data.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">2d. Security, error analysis, and system logs</h3>
            <p class="mb-4">
                To ensure stability and security of our platform, we process technical data such as:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>IP address;</li>
                <li>browser and device information;</li>
                <li>error logs and diagnostic information;</li>
                <li>server and application logs.</li>
            </ul>
            <p class="mb-4">
                This processing is necessary to:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>prevent abuse and security incidents;</li>
                <li>diagnose and resolve technical issues;</li>
                <li>monitor performance and improve reliability.</li>
            </ul>
            <p class="mb-4">
                We do <strong>not</strong> use tracking cookies, profiling, or personalized advertising.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">3. Cookies</h2>
            <p class="mb-4">
                {{ config('app.name') }} uses:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>functional cookies</strong>, which are necessary for the proper functioning of the website
                    (such as login and language settings); and</li>
                <li><strong>analytical cookies</strong> that do not contain personally identifiable information.</li>
            </ul>
            <p class="mb-4">
                {{ config('app.name') }} does <strong>not</strong> use:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>tracking cookies;</li>
                <li>marketing cookies; or</li>
                <li>cookies for profiling or personalized advertising.</li>
            </ul>
            <p class="mb-4">
                A cookie notice is displayed only when required by law.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">4. Legal basis for processing</h2>
            <p class="mb-4">
                We process personal data based on the following legal grounds under the GDPR:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>Performance of a contract</strong>: for providing our PDF services, managing your account,
                    and handling billing and payments.</li>
                <li><strong>Legal obligation</strong>: for retaining administrative and tax records.</li>
                <li><strong>Legitimate interest</strong>: for security, monitoring, and improvement of our services.</li>
                <li><strong>Consent</strong>: for sending newsletters, if you have explicitly subscribed.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">5. Recipients of personal data</h2>
            <p class="mb-4">
                We only share your personal data with third parties when this is necessary to:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>host and secure our infrastructure;</li>
                <li>deliver our services reliably;</li>
                <li>process payments via external payment service providers; and</li>
                <li>send transactional emails and system notifications.</li>
            </ul>
            <p class="mb-4">
                These third parties act as processors and:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>act only according to our written instructions;</li>
                <li>are not allowed to use your data for their own purposes; and</li>
                <li>are required to implement appropriate security measures.</li>
            </ul>
            <p class="mb-4">
                We do not publicly list all individual suppliers by name in this policy. This is permitted under the
                GDPR as long as the categories of recipients are clearly described.
            </p>
            <p class="mb-4">
                We may provide personal data to law enforcement agencies or supervisory authorities only when we
                are legally required to do so and only upon a specific, lawful request.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">6. Retention periods</h2>
            <p class="mb-4">
                We do not retain personal data longer than necessary for the purposes described in this Privacy Policy,
                unless a longer retention period is required by law.
            </p>
            <table class="w-full border border-gray-300 text-sm mb-4">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-gray-300 px-3 py-2 text-left">Type of data</th>
                        <th class="border border-gray-300 px-3 py-2 text-left">Retention period</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Uploaded files</td>
                        <td class="border border-gray-300 px-3 py-2">1 hour after upload</td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Conversion results</td>
                        <td class="border border-gray-300 px-3 py-2">
                            14 days after creation (unless removed earlier or configured differently by your organization)
                        </td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Account data</td>
                        <td class="border border-gray-300 px-3 py-2">For as long as your account is active</td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Administrative and billing data</td>
                        <td class="border border-gray-300 px-3 py-2">
                            7 years, in accordance with statutory tax and accounting obligations
                        </td>
                    </tr>
                    <tr>
                        <td class="border border-gray-300 px-3 py-2">Technical and log data</td>
                        <td class="border border-gray-300 px-3 py-2">Up to 90 days</td>
                    </tr>
                </tbody>
            </table>

            <h2 class="text-2xl font-semibold mt-8 mb-3">7. Your rights under the GDPR</h2>
            <p class="mb-4">
                You have the following rights regarding your personal data:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>Right of access</strong> – to obtain confirmation as to whether or not we process your data and, if so, to receive a copy.</li>
                <li><strong>Right to rectification</strong> – to have inaccurate or incomplete data corrected.</li>
                <li><strong>Right to erasure</strong> – to request deletion of your data when it is no longer necessary or when processing is unlawful.</li>
                <li><strong>Right to restriction</strong> – to request a temporary pause on processing under certain conditions (for example, if you contest the accuracy of the data).</li>
                <li><strong>Right to object</strong> – to object to processing based on legitimate interest.</li>
                <li><strong>Right to data portability</strong> – to receive your data in a structured, commonly used, and machine-readable format and to transmit those data to another controller, where technically feasible.</li>
            </ul>

            <h3 class="text-xl font-semibold mt-6 mb-2">Paid customers</h3>
            <p class="mb-4">
                If you have an active paid license or subscription, you may submit your request directly via
                <a href="mailto:{{ config('mail.from.address') }}" class="text-blue-600 hover:text-blue-800">{{ config('mail.from.address') }}</a>.
                Requests from paying customers are handled with priority and always within one month.
            </p>

            <h3 class="text-xl font-semibold mt-6 mb-2">Free users and guest users</h3>
            <p class="mb-4">
                If you use {{ config('app.name') }} without a paid account, you can submit a GDPR request through our online privacy request
                form on the website. We respond within the legal timeframe of one month.
            </p>
            <p class="mb-4">
                If you believe that we process your data in violation of applicable privacy laws, you have the right to
                lodge a complaint with the Dutch Data Protection Authority (Autoriteit Persoonsgegevens).
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">8. Contact information</h2>
            <p class="mb-4">
                If you have questions about this Privacy Policy or about how we handle your personal data, you can contact us at:
            </p>
            <p class="mb-2">
                <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }} B.V.</strong><br>
                {{ env('INVOICE_COMPANY_ADDRESS', 'Zijpendaalseweg 51A') }}<br>
                {{ env('INVOICE_COMPANY_POSTAL_CODE', '6814 CD') }} {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}<br>
                {{ env('INVOICE_COMPANY_COUNTRY', 'The Netherlands') }}
            </p>
            <p class="mb-4">
                Email: <a href="mailto:{{ env('INVOICE_COMPANY_EMAIL', '{{ config('mail.from.address') }}') }}" class="text-blue-600 hover:text-blue-800">{{ env('INVOICE_COMPANY_EMAIL', '{{ config('mail.from.address') }}') }}</a>
            </p>
            @endif
        </div>
    </main>

    @include('components.footer')
</div>
@endsection
