@extends('layouts.homepage-standalone')

@section('title', app()->getLocale() === 'nl' ? 'Algemene Voorwaarden' : 'Terms & Conditions')

@section('content')
<div class="min-h-screen bg-white">
    @include('components.header')

    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="prose prose-lg max-w-none">
            @if(app()->getLocale() === 'nl')
            {{-- Dutch version --}}
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Algemene Voorwaarden – {{ config('app.name') }}</h1>
            <p class="text-sm text-gray-500 mb-8">
                Versie 2025 – Laatst bijgewerkt: december 2025
            </p>

            <p class="mb-4">
                Deze Algemene Voorwaarden zijn van toepassing op alle diensten geleverd via
                <strong>{{ config('app.name') }}</strong> op <strong>{{ env('INVOICE_COMPANY_WEBSITE', 'example.com') }}</strong>, een handelsnaam van
                <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }}</strong>, gevestigd te {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}, Nederland (hierna: "{{ config('app.name') }}", "{{ config('app.name') }}",
                "wij", "ons" of "onze dienst").
            </p>
            <p class="mb-6">
                Door gebruik te maken van {{ env('INVOICE_COMPANY_WEBSITE', 'example.com') }}, een account aan te maken of een licentie af te nemen,
                verklaar je deze voorwaarden te hebben gelezen en te accepteren.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 1 – Definities</h2>
            <p class="mb-4">In deze Algemene Voorwaarden wordt verstaan onder:</p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>{{ config('app.name') }}</strong>: de online dienst waarmee gebruikers PDF's en gerelateerde bestanden kunnen converteren, optimaliseren, samenvoegen, analyseren, AI-functies uitvoeren en workflows gebruiken.</li>
                <li><strong>Interus</strong>: de onderneming die {{ config('app.name') }} exploiteert.</li>
                <li><strong>Klant / Gebruiker</strong>: iedere natuurlijke persoon of rechtspersoon die gebruik maakt van {{ config('app.name') }}, met of zonder account.</li>
                <li><strong>Bestand</strong>: ieder document, afbeelding of andere data die door de klant wordt geüpload voor verwerking via {{ config('app.name') }}.</li>
                <li><strong>Conversieresultaat</strong>: het uitvoerbestand dat wordt gegenereerd door gebruik van {{ config('app.name') }}.</li>
                <li><strong>Licentie / Abonnement</strong>: de door de klant gekozen gebruiksvorm (gratis, onetime credits, premium abonnement).</li>
                <li><strong>Credits</strong>: virtuele eenheden binnen {{ config('app.name') }} die nodig kunnen zijn om bepaalde functies of conversies uit te voeren.</li>
                <li><strong>Schriftelijk</strong>: communicatie per e-mail of via digitale bevestiging in de webinterface van {{ config('app.name') }}.</li>
                <li><strong>AI Edit PDF</strong>: de functionaliteit waarbij delen van een document naar een externe AI-engine worden gestuurd om inhoud te bewerken of te genereren.</li>
                <li><strong>API</strong>: de programmeerinterface waarmee geautomatiseerde toegang tot bepaalde functies van {{ config('app.name') }} wordt verkregen via een API-sleutel.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 2 – Toepasselijkheid</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Deze Algemene Voorwaarden zijn van toepassing op alle diensten, accounts, workflows, API-functionaliteiten, offertes en overeenkomsten met betrekking tot {{ config('app.name') }}.</li>
                <li>Afwijkingen van deze voorwaarden zijn alleen geldig indien deze schriftelijk door {{ config('app.name') }} zijn bevestigd.</li>
                <li>Eventuele door de klant gehanteerde algemene voorwaarden worden uitdrukkelijk van de hand gewezen.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 3 – Totstandkoming van de overeenkomst</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Een overeenkomst komt tot stand zodra de klant een account aanmaakt, een licentie of abonnement afneemt of feitelijk gebruik maakt van {{ config('app.name') }}.</li>
                <li>{{ config('app.name') }} behoudt zich het recht voor een aanvraag te weigeren zonder opgave van redenen.</li>
                <li>De klant garandeert dat de door hem verstrekte gegevens juist en volledig zijn.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 4 – Gebruiksrecht en Gebruiksbeperkingen</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>De klant ontvangt een beperkt, niet-exclusief, niet-overdraagbaar gebruiksrecht op {{ config('app.name') }} voor de duur van zijn licentie of abonnement.</li>
                <li>Alle intellectuele eigendomsrechten op {{ config('app.name') }}, waaronder software, algoritmes, ontwerpen, documentatie en AI-workflows, blijven volledig bij Interus/{{ config('app.name') }}.</li>
                <li>Het is de klant verboden om de dienst te gebruiken voor:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>het uploaden, verwerken of delen van pornografisch, seksueel expliciet materiaal of materiaal dat minderjarigen betreft;</li>
                        <li>het uploaden of verspreiden van virussen, malware, ransomware of andere schadelijke componenten;</li>
                        <li>spam, phishing, frauduleuze of misleidende activiteiten;</li>
                        <li>het uploaden van materiaal dat inbreuk maakt op intellectuele eigendomsrechten of andere rechten van derden;</li>
                        <li>het omzeilen van beveiligingsmaatregelen, scraping, brute-forcing of overbelasting van de infrastructuur;</li>
                        <li>het trainen of ondersteunen van concurrerende diensten of modellen die vergelijkbaar zijn met {{ config('app.name') }}.</li>
                    </ul>
                </li>
                <li>Wanneer {{ config('app.name') }} vermoedt of vaststelt dat een bestand of account in strijd handelt met de wet of deze voorwaarden, mag {{ config('app.name') }} zonder voorafgaande waarschuwing:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>de betreffende bestanden onmiddellijk verwijderen;</li>
                        <li>het account (tijdelijk) blokkeren;</li>
                        <li>credits bevriezen of intrekken;</li>
                        <li>misbruik onderzoeken en passende maatregelen treffen;</li>
                        <li>indien wettelijk vereist, melding doen bij de bevoegde (opsporings)instanties.</li>
                    </ul>
                </li>
                <li>De klant mag derden autoriseren om de dienst namens hem te gebruiken binnen zijn account. De klant blijft in alle gevallen volledig verantwoordelijk en aansprakelijk voor het gebruik van zijn account.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 5 – Bestanden, Verwerking en Bewaartermijnen</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Geüploade bestanden worden versleuteld (geëncrypt) opgeslagen.</li>
                <li>{{ config('app.name') }} bekijkt, analyseert of kopieert geüploade bestanden niet voor eigen doeleinden, tenzij dit strikt noodzakelijk is voor support en alleen met expliciete toestemming van de klant.</li>
                <li>Standaard bewaartermijnen:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>Geüploade bestanden worden automatisch verwijderd na <strong>1 uur</strong>;</li>
                        <li>Conversieresultaten worden automatisch verwijderd na <strong>14 dagen</strong>, tenzij de klant of organisatie een kortere termijn instelt of het resultaat handmatig verwijdert.</li>
                    </ul>
                </li>
                <li>{{ config('app.name') }} maakt geen back-ups van geüploade bestanden of conversieresultaten. De klant is zelf verantwoordelijk voor het veilig opslaan en bewaren van conversieresultaten.</li>
                <li>De klant is volledig verantwoordelijk voor de inhoud van geüploade bestanden en garandeert dat hij gerechtigd is deze te gebruiken en te verwerken.</li>
                <li>{{ config('app.name') }} behoudt zich het recht voor om bestanden onmiddellijk te verwijderen wanneer deze:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>pornografisch, schadelijk, illegaal of onrechtmatig zijn;</li>
                        <li>virussen, malware of andere schadelijke inhoud bevatten;</li>
                        <li>spam of misbruik vormen;</li>
                        <li>op andere wijze in strijd zijn met wet- of regelgeving of deze voorwaarden.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 6 – Conversies, AI-functionaliteit en Resultaten</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} streeft naar hoogwaardige conversies, maar kan niet garanderen dat het conversieresultaat exact overeenkomt met de oorspronkelijke opmaak, inhoud of structuur.</li>
                <li>Bij gebruik van de <strong>AI Edit PDF</strong>-functionaliteit kunnen delen van het document naar een externe AI-engine worden gestuurd. Door deze functie te gebruiken, geeft de klant hiervoor uitdrukkelijk toestemming.</li>
                <li>AI-uitvoer kan fouten, onnauwkeurigheden of onvolledige informatie bevatten. De klant blijft te allen tijde verantwoordelijk voor het controleren van de resultaten en het gebruik daarvan.</li>
                <li>{{ config('app.name') }} geeft geen juridisch, financieel, medisch of anderszins professioneel advies via AI-functionaliteit of conversieresultaten.</li>
                <li>{{ config('app.name') }} is niet aansprakelijk voor schade die direct of indirect voortvloeit uit het gebruik van conversieresultaten of AI-uitvoer.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 7 – Licenties, Abonnementen en Credits</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} biedt onder meer:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>Gratis gebruik (met beperkte functionaliteit);</li>
                        <li>Onetime credits;</li>
                        <li>Premium abonnementen.</li>
                    </ul>
                </li>
                <li>Bij een premium abonnement:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>wordt het abonnement automatisch verlengd, tenzij de klant tijdig opzegt;</li>
                        <li>kun je op elk moment opzeggen; het abonnement loopt dan door tot het einde van de lopende termijn;</li>
                        <li>worden premium credits maandelijks gereset naar <strong>0</strong>.</li>
                    </ul>
                </li>
                <li>Onetime credits:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>worden eerst verbruikt, vóór premium credits;</li>
                        <li>vervallen wanneer de klant het account of de overeenkomst beëindigt;</li>
                        <li>zijn niet restitueerbaar, tenzij dwingend recht anders voorschrijft.</li>
                    </ul>
                </li>
                <li>Bij beëindiging van de overeenkomst of het account worden alle resterende credits op <strong>0</strong> gezet.</li>
                <li><strong>Prijswijzigingen en inflatiecorrectie:</strong>
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>{{ config('app.name') }} mag jaarlijks een automatische prijsverhoging toepassen in verband met inflatie en/of kostenstijging;</li>
                        <li>nieuwe prijzen gelden bij de eerstvolgende verlenging van het premium abonnement;</li>
                        <li>klant wordt minimaal 30 dagen vooraf geïnformeerd.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 8 – API-gebruik</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Toegang tot de API van {{ config('app.name') }} gebeurt via persoonlijke API-sleutels (API-keys).</li>
                <li>API-gebruik valt onder deze Algemene Voorwaarden en eventuele aanvullende API-documentatie.</li>
                <li><strong>Rate limits:</strong> standaard geldt een limiet van maximaal <strong>3 requests per seconde</strong> per API-sleutel, tenzij in de licentie anders bepaald.</li>
                <li>{{ config('app.name') }} mag API-verkeer beperken, vertragen of blokkeren bij overschrijding van limieten of vermoeden van misbruik.</li>
                <li>Het is verboden de API te gebruiken voor:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>het bouwen van concurrerende diensten of producten;</li>
                        <li>massale scraping of ongeautoriseerde data-extractie;</li>
                        <li>het omzeilen van beveiligings- of toegangsbeheermechanismen.</li>
                    </ul>
                </li>
                <li>De klant is zelf verantwoordelijk voor de geheimhouding en beveiliging van API-sleutels. Misbruik van een sleutel wordt toegerekend aan de klant.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 9 – Fair Use Policy</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} hanteert een Fair Use Policy om misbruik en overbelasting van de dienst te voorkomen.</li>
                <li>{{ config('app.name') }} mag limieten stellen aan:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>het aantal conversies per tijdseenheid;</li>
                        <li>het aantal workflowuitvoeringen;</li>
                        <li>AI-verwerkingscapaciteit;</li>
                        <li>de maximale bestandsgrootte (in beginsel <strong>100 MB per PDF-bestand</strong>, afhankelijk van het gekozen licentietype).</li>
                    </ul>
                </li>
                <li>Bij overschrijding van deze limieten mag {{ config('app.name') }}:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>throttling toepassen (verkeer afremmen);</li>
                        <li>tijdelijk toegang beperken;</li>
                        <li>in overleg met de klant aanvullende tarieven in rekening brengen.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 10 – Consumentenherroepingsrecht</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} levert digitale diensten die direct na aankoop kunnen worden uitgevoerd (bijvoorbeeld door het uploaden van een bestand en het starten van een conversie).</li>
                <li>Wanneer een consument een dienst afneemt en instemt met onmiddellijke uitvoering, erkent de consument dat het wettelijke herroepingsrecht vervalt zodra {{ config('app.name') }} begint met de uitvoering (bijvoorbeeld bij de eerste conversie of workflow).</li>
                <li>Dit is in lijn met de Europese regels voor digitale inhoud en digitale diensten.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 11 – Aansprakelijkheid</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>De totale aansprakelijkheid van {{ config('app.name') }} wegens een toerekenbare tekortkoming of andere rechtsgrond is beperkt tot het bedrag dat de klant in de laatste <strong>12 maanden</strong> vóór het schadevoorval aan {{ config('app.name') }} heeft betaald, met een maximum van één jaarbedrag van het betreffende licentietype.</li>
                <li>{{ config('app.name') }} is in geen geval aansprakelijk voor:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>indirecte schade of gevolgschade;</li>
                        <li>winstderving;</li>
                        <li>verlies van bestanden of data, mede gelet op de korte bewaartermijnen;</li>
                        <li>schade als gevolg van onjuiste invoer of instellingen door de klant;</li>
                        <li>schade ontstaan door het gebruik van AI-uitvoer of conversieresultaten;</li>
                        <li>schade veroorzaakt door onjuist of ongeautoriseerd API-gebruik.</li>
                    </ul>
                </li>
                <li>De klant vrijwaart {{ config('app.name') }} voor aanspraken van derden die verband houden met het gebruik van {{ config('app.name') }}, geüploade bestanden of activiteiten via het account van de klant.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 12 – Export en Internationale Gebruikers</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>De klant is zelf verantwoordelijk voor naleving van lokale wetgeving in het land van gebruik.</li>
                <li>{{ config('app.name') }} mag niet worden gebruikt in strijd met Europese export- en sanctieregels.</li>
                <li>Betaling kan via Mollie in verschillende valuta (waaronder USD). Eventuele koersverschillen komen voor rekening van de klant.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 13 – Beëindiging</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} mag een account (tijdelijk) blokkeren of de overeenkomst beëindigen bij:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>misbruik of overtreding van deze voorwaarden;</li>
                        <li>wanbetaling;</li>
                        <li>beveiligingsrisico's of vermoeden van fraude.</li>
                    </ul>
                </li>
                <li>Bij beëindiging vervalt het gebruiksrecht op {{ config('app.name') }} direct.</li>
                <li>Bestanden en conversieresultaten worden verwijderd volgens de in deze voorwaarden genoemde bewaartermijnen.</li>
                <li>Alle resterende credits vervallen en worden op <strong>0</strong> gezet.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 14 – Beschikbaarheid en Onderhoud</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} streeft naar hoge beschikbaarheid van de dienst, maar geeft geen garantie op ononderbroken of foutloze werking.</li>
                <li>{{ config('app.name') }} mag de dienst tijdelijk onderbreken voor onderhoud, updates of verbeteringen.</li>
                <li>Onderbrekingen of storingen geven geen recht op schadevergoeding of restitutie.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 15 – Wijziging van de Algemene Voorwaarden</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} mag deze Algemene Voorwaarden eenzijdig wijzigen.</li>
                <li>Wijzigingen worden minimaal <strong>30 dagen</strong> vóór inwerkingtreding bekendgemaakt via de website en/of per e-mail.</li>
                <li>Indien de klant de dienst blijft gebruiken na de ingangsdatum van de gewijzigde voorwaarden, wordt dit beschouwd als acceptatie van de nieuwe voorwaarden.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 16 – Nietigheid en Vervanging</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Indien een bepaling in deze Algemene Voorwaarden nietig, vernietigbaar of anderszins ongeldig blijkt, blijven de overige bepalingen onverkort van kracht.</li>
                <li>{{ config('app.name') }} mag in dat geval de betreffende bepaling vervangen door een bepaling die juridisch geldig is en zoveel mogelijk aansluit bij de bedoeling van de oorspronkelijke bepaling.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Artikel 17 – Toepasselijk Recht en Bevoegde Rechter</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Op deze Algemene Voorwaarden en de overeenkomst tussen de klant en {{ config('app.name') }} is uitsluitend <strong>Nederlands recht</strong> van toepassing.</li>
                <li>Geschillen worden voorgelegd aan de bevoegde rechter te <strong>Arnhem</strong>, tenzij dwingend recht anders voorschrijft.</li>
            </ul>

            @else
            {{-- English version --}}
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Terms & Conditions – {{ config('app.name') }}</h1>
            <p class="text-sm text-gray-500 mb-2">
                Version 2025 – Last updated: December 2025
            </p>
            <p class="text-sm text-gray-600 mb-8">
                <em>This is an English translation. In case of discrepancies, the Dutch version prevails.</em>
            </p>

            <p class="mb-4">
                These Terms & Conditions apply to all services provided through <strong>{{ config('app.name') }}</strong> on
                <strong>{{ env('INVOICE_COMPANY_WEBSITE', 'example.com') }}</strong>, a trade name of <strong>{{ env('INVOICE_COMPANY_LEGAL_NAME', 'Interus') }}</strong>, based in {{ env('INVOICE_COMPANY_CITY', 'Arnhem') }}, {{ env('INVOICE_COMPANY_COUNTRY', 'the Netherlands') }}
                (hereinafter: "{{ config('app.name') }}", "{{ config('app.name') }}", "we", "us" or "our service").
            </p>
            <p class="mb-6">
                By using {{ env('INVOICE_COMPANY_WEBSITE', 'example.com') }}, creating an account or purchasing a licence, you declare that you have read and
                accepted these Terms & Conditions.
            </p>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 1 – Definitions</h2>
            <p class="mb-4">In these Terms & Conditions, the following terms have the meanings set out below:</p>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li><strong>{{ config('app.name') }}</strong>: the online service that enables users to convert, optimise, merge and analyse PDFs and related files, use AI features, and run workflows.</li>
                <li><strong>Interus</strong>: the company operating {{ config('app.name') }}.</li>
                <li><strong>Customer / User</strong>: any natural or legal person using {{ config('app.name') }}, with or without an account.</li>
                <li><strong>File</strong>: any document, image or other data uploaded by the customer for processing via {{ config('app.name') }}.</li>
                <li><strong>Conversion Result</strong>: the output file generated by using {{ config('app.name') }}.</li>
                <li><strong>Licence / Subscription</strong>: the chosen usage tier (free, one-time credits, premium subscription).</li>
                <li><strong>Credits</strong>: virtual units within {{ config('app.name') }} required to use specific features or perform conversions.</li>
                <li><strong>In writing</strong>: communication by e-mail or via digital confirmation in the {{ config('app.name') }} web interface.</li>
                <li><strong>AI Edit PDF</strong>: the feature where parts of a document are sent to an external AI engine to edit or generate content.</li>
                <li><strong>API</strong>: the application programming interface providing automated access to certain {{ config('app.name') }} features via an API key.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 2 – Scope of Application</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>These Terms & Conditions apply to all services, accounts, workflows, API functionality, quotations and agreements relating to {{ config('app.name') }}.</li>
                <li>Any deviations from these Terms & Conditions are only valid if expressly confirmed in writing by {{ config('app.name') }}.</li>
                <li>Any general terms and conditions of the customer are expressly rejected.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 3 – Formation of the Agreement</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>An agreement is concluded as soon as the customer creates an account, purchases a licence or subscription, or actually uses {{ config('app.name') }}.</li>
                <li>{{ config('app.name') }} reserves the right to refuse any request or order without stating reasons.</li>
                <li>The customer guarantees that all data provided to {{ config('app.name') }} are accurate and complete.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 4 – Right of Use and Usage Restrictions</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>The customer is granted a limited, non-exclusive and non-transferable right to use {{ config('app.name') }} for the term of the chosen licence or subscription.</li>
                <li>All intellectual property rights in {{ config('app.name') }}, including software, algorithms, designs, documentation and AI workflows, remain fully vested in Interus/{{ config('app.name') }}.</li>
                <li>The customer may not use the service to:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>upload, process or share pornographic, sexually explicit material or material involving minors;</li>
                        <li>upload or distribute files containing viruses, malware, ransomware or other harmful components;</li>
                        <li>send spam, phishing or fraudulent or misleading content;</li>
                        <li>upload content that infringes intellectual property or other rights of third parties;</li>
                        <li>circumvent security measures, perform scraping, brute-forcing, or overload the infrastructure;</li>
                        <li>train or support competing services or models that are similar to {{ config('app.name') }}.</li>
                    </ul>
                </li>
                <li>If {{ config('app.name') }} suspects or determines that a file or account is acting in violation of the law or these Terms & Conditions, {{ config('app.name') }} may, without prior notice:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>immediately delete the relevant files;</li>
                        <li>temporarily or permanently block the account;</li>
                        <li>freeze or revoke credits;</li>
                        <li>investigate misuse and take appropriate technical and legal measures;</li>
                        <li>notify competent (law enforcement) authorities where legally required.</li>
                    </ul>
                </li>
                <li>The customer may authorise third parties to use the service on its behalf within its account. The customer remains fully responsible and liable for all such use.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 5 – Files, Processing and Retention Periods</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Uploaded files are stored in encrypted form.</li>
                <li>{{ config('app.name') }} does not view, analyse or copy uploaded files for its own purposes, unless this is strictly necessary for support and only with the customer's explicit consent.</li>
                <li>Standard retention periods:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>Uploaded files are automatically deleted after <strong>1 hour</strong>;</li>
                        <li>Conversion results are automatically deleted after <strong>14 days</strong>, unless the customer or organisation configures a shorter period or manually deletes the result earlier.</li>
                    </ul>
                </li>
                <li>{{ config('app.name') }} does not create backups of uploaded files or conversion results. The customer is solely responsible for securely storing and backing up conversion results.</li>
                <li>The customer is fully responsible for the content of uploaded files and warrants that it is entitled to use and process the data contained therein.</li>
                <li>{{ config('app.name') }} reserves the right to immediately delete any file that:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>is pornographic, harmful, illegal or otherwise unlawful;</li>
                        <li>contains viruses, malware or other harmful content;</li>
                        <li>constitutes spam or abusive behaviour;</li>
                        <li>otherwise violates applicable law or these Terms & Conditions.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 6 – Conversions, AI Functionality and Results</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} strives to deliver high-quality conversions but does not guarantee that conversion results will be identical to the original layout, content or structure.</li>
                <li>When using the <strong>AI Edit PDF</strong> feature, parts of the document may be sent to an external AI engine. By using this feature, the customer explicitly consents to such processing.</li>
                <li>AI output may contain errors, inaccuracies or incomplete information. The customer is solely responsible for verifying the results and deciding how to use them.</li>
                <li>{{ config('app.name') }} does not provide legal, financial, medical or any other professional advice through its AI functionality or conversion results.</li>
                <li>{{ config('app.name') }} is not liable for any damage, loss or claims arising directly or indirectly from the use of conversion results or AI output.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 7 – Licences, Subscriptions and Credits</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} offers, among others:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>Free use (with limited functionality);</li>
                        <li>One-time credit packages;</li>
                        <li>Premium subscriptions.</li>
                    </ul>
                </li>
                <li>For premium subscriptions:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>the subscription renews automatically unless the customer cancels in time;</li>
                        <li>the customer may cancel at any time; the subscription then continues until the end of the current term;</li>
                        <li>premium credits are reset to <strong>0</strong> at the start of each new billing period.</li>
                    </ul>
                </li>
                <li>One-time credits:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>are always used first, before premium credits;</li>
                        <li>expire when the customer terminates the account or agreement;</li>
                        <li>are non-refundable unless mandatory law provides otherwise.</li>
                    </ul>
                </li>
                <li>Upon termination of the agreement or account, all remaining credits are set to <strong>0</strong>.</li>
                <li><strong>Price changes and inflation adjustment:</strong>
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>{{ config('app.name') }} may apply an annual automatic price increase in connection with inflation and/or cost increases;</li>
                        <li>new prices apply at the next renewal of the premium subscription;</li>
                        <li>the customer will be notified at least 30 days in advance.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 8 – API Use</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>Access to {{ config('app.name') }}'s API is provided via personal API keys.</li>
                <li>API usage is subject to these Terms & Conditions and any additional API documentation provided by {{ config('app.name') }}.</li>
                <li><strong>Rate limits:</strong> by default, a limit of <strong>3 requests per second</strong> per API key applies, unless specified otherwise in the customer's licence.</li>
                <li>{{ config('app.name') }} may limit, throttle or block API traffic if limits are exceeded or misuse is suspected.</li>
                <li>The customer may not use the API to:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>build competing services or products;</li>
                        <li>perform large-scale scraping or unauthorised data extraction;</li>
                        <li>bypass security or access control mechanisms.</li>
                    </ul>
                </li>
                <li>The customer is responsible for securing API keys. Any misuse of an API key is deemed to have been carried out by the customer.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 9 – Fair Use Policy</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} applies a Fair Use Policy to prevent abuse and overloading of the service.</li>
                <li>{{ config('app.name') }} may impose limits on:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>the number of conversions per time period;</li>
                        <li>the number of workflow executions;</li>
                        <li>AI processing capacity;</li>
                        <li>maximum file size (in principle <strong>100 MB per PDF</strong>, depending on the chosen licence type).</li>
                    </ul>
                </li>
                <li>If these limits are exceeded, {{ config('app.name') }} may:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>apply throttling (rate limiting);</li>
                        <li>temporarily restrict access;</li>
                        <li>charge additional fees in consultation with the customer.</li>
                    </ul>
                </li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 10 – Consumer Right of Withdrawal</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} provides digital services that may be performed immediately after purchase (for example by uploading a file and starting a conversion).</li>
                <li>When a consumer purchases such a service and expressly agrees to immediate performance, the consumer acknowledges that the statutory right of withdrawal lapses as soon as {{ config('app.name') }} starts executing the service (for example at the first conversion or workflow).</li>
                <li>This is in accordance with EU rules for digital content and digital services.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 11 – Liability</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>The total liability of {{ config('app.name') }} for any attributable failure in the performance of the agreement or on any other legal ground is limited to the amount paid by the customer to {{ config('app.name') }} in the last <strong>12 months</strong> prior to the event giving rise to liability, up to a maximum of one annual fee of the relevant licence type.</li>
                <li>{{ config('app.name') }} is in no event liable for:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>indirect or consequential damage;</li>
                        <li>loss of profit;</li>
                        <li>loss of files or data, given the short retention periods;</li>
                        <li>damage resulting from incorrect input or settings by the customer;</li>
                        <li>damage arising from the use of AI output or conversion results;</li>
                        <li>damage caused by incorrect or unauthorised use of the API.</li>
                    </ul>
                </li>
                <li>The customer indemnifies {{ config('app.name') }} against all claims from third parties related to the use of {{ config('app.name') }}, uploaded files or activities performed via the customer's account.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 12 – Export and International Users</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>The customer is responsible for complying with local laws in the country where the service is used.</li>
                <li>{{ config('app.name') }} may not be used in violation of European export or sanctions regulations.</li>
                <li>Payments may be processed through Mollie in various currencies (including USD). Exchange rate differences are borne by the customer.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 13 – Termination</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} may temporarily block an account or terminate the agreement in the event of:
                    <ul class="list-disc list-inside ml-5 mt-1 space-y-1">
                        <li>misuse or violation of these Terms & Conditions;</li>
                        <li>non-payment;</li>
                        <li>security risks or suspicion of fraud.</li>
                    </ul>
                </li>
                <li>Upon termination, the right to use {{ config('app.name') }} ends immediately.</li>
                <li>Files and conversion results are deleted in accordance with the retention periods set out in these Terms & Conditions.</li>
                <li>All remaining credits expire and are set to <strong>0</strong>.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 14 – Availability and Maintenance</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} strives to provide a highly available service but does not guarantee uninterrupted or error-free operation.</li>
                <li>{{ config('app.name') }} may temporarily interrupt the service for maintenance, updates or improvements.</li>
                <li>Interruptions or outages do not entitle the customer to compensation or refunds.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 15 – Changes to the Terms & Conditions</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>{{ config('app.name') }} may unilaterally amend these Terms & Conditions.</li>
                <li>Changes will be announced at least <strong>30 days</strong> before they take effect, via the website and/or by e-mail.</li>
                <li>If the customer continues to use the service after the effective date of the amended Terms & Conditions, this use will be deemed acceptance of the new terms.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 16 – Invalidity and Replacement</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>If any provision of these Terms & Conditions is found to be invalid, void or unenforceable, the remaining provisions shall remain in full force and effect.</li>
                <li>In such case, {{ config('app.name') }} may replace the invalid provision with a valid provision that best reflects the original intention.</li>
            </ul>

            <h2 class="text-2xl font-semibold mt-8 mb-3">Article 17 – Governing Law and Jurisdiction</h2>
            <ul class="list-disc list-inside mb-4 space-y-1">
                <li>These Terms & Conditions and the agreement between the customer and {{ config('app.name') }} are governed exclusively by <strong>Dutch law</strong>.</li>
                <li>Disputes shall be submitted to the competent court in <strong>Arnhem</strong>, the Netherlands, unless mandatory law provides otherwise.</li>
            </ul>
            @endif
        </div>
    </main>

    @include('components.footer')
</div>
@endsection
