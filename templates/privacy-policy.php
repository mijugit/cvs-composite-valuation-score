<?php declare(strict_types=1); ?>

<div class="prose" style="max-width:760px;margin:0 auto;">

<h1>Polityka Prywatności aplikacji CVS</h1>
<p style="color:var(--c-text-muted);font-size:.875rem;">Ostatnia aktualizacja: 2026-06-16</p>

<h2>1. Administrator danych osobowych</h2>
<p>Administratorem danych osobowych Użytkowników jest <strong>Autor aplikacji CVS</strong> — osoba fizyczna.<br>
Kontakt w sprawach danych osobowych: <a href="mailto:blog@timeflow.fun">blog@timeflow.fun</a></p>
<p>Aplikacja jest udostępniana niekomercyjnie, wyłącznie w celach edukacyjnych i analitycznych.</p>

<h2>2. Jakie dane przetwarzamy i w jakim celu</h2>

<h3>2.1. Dane rejestracyjne</h3>
<p><strong>Co:</strong> adres e-mail, hasło (przechowywane wyłącznie jako skrót bcrypt — Operator nigdy nie zna hasła w postaci jawnej).</p>
<p><strong>Cel:</strong> umożliwienie logowania i korzystania z Aplikacji.</p>
<p><strong>Podstawa prawna:</strong> art. 6 ust. 1 lit. b RODO — wykonanie umowy (Regulaminu Aplikacji).</p>
<p><strong>Okres przechowywania:</strong> do czasu usunięcia konta na wniosek Użytkownika lub do zaprzestania działalności Aplikacji.</p>

<h3>2.2. Watchlist i historia analiz</h3>
<p><strong>Co:</strong> lista obserwowanych spółek (tickery), historia wygenerowanych analiz CVS (ticker, wyniki modelu, daty).</p>
<p><strong>Cel:</strong> dostarczanie funkcjonalności watchlist, track record i screener.</p>
<p><strong>Podstawa prawna:</strong> art. 6 ust. 1 lit. b RODO — wykonanie umowy.</p>
<p><strong>Okres przechowywania:</strong> do czasu usunięcia konta lub danych na wniosek Użytkownika.</p>

<h3>2.3. Alerty e-mail o zmianach sygnałów CVS</h3>
<p><strong>Co:</strong> adres e-mail, preferencje alertów (globalne włączenie/wyłączenie, lista wyłączonych tickerów), historia wysłanych powiadomień.</p>
<p><strong>Cel:</strong> wysyłanie powiadomień e-mail o zmianach rekomendacji lub sygnałów CVS dla obserwowanych spółek.</p>
<p><strong>Podstawa prawna:</strong> art. 6 ust. 1 lit. a RODO — <strong>zgoda</strong> Użytkownika wyrażona przez włączenie alertów w Aplikacji.</p>
<p><strong>Wycofanie zgody:</strong> Użytkownik może wyłączyć alerty w dowolnym momencie:</p>
<ul>
    <li>z poziomu Aplikacji (panel watchlist),</li>
    <li>klikając link <strong>„Wypisz się z alertów"</strong> zawarty w każdej wiadomości e-mail z alertem.</li>
</ul>
<p>Wycofanie zgody nie wpływa na zgodność z prawem przetwarzania, które miało miejsce przed wycofaniem.</p>

<h3>2.4. Dane techniczne i bezpieczeństwo</h3>
<p><strong>Co:</strong> sesja PHP (identyfikator sesji przechowywany w przeglądarce), token CSRF. Aplikacja nie zbiera adresów IP ani logów dostępu na własnym poziomie; logi serwera mogą być generowane przez dostawcę hostingu.</p>
<p><strong>Cel:</strong> utrzymanie sesji użytkownika i ochrona formularzy przed atakami CSRF.</p>
<p><strong>Podstawa prawna:</strong> art. 6 ust. 1 lit. f RODO — prawnie uzasadniony interes Administratora (bezpieczeństwo Aplikacji).</p>

<h2>3. Podmioty przetwarzające dane</h2>

<h3>3.1. Dostawca hostingu — CyberFolks</h3>
<p>Aplikacja jest hostowana na serwerach firmy <strong>CyberFolks Sp. z o.o.</strong> (Polska, obszar EOG). Dane przechowywane na serwerze nie opuszczają Europejskiego Obszaru Gospodarczego w związku z usługą hostingu.</p>

<h3>3.2. Claude API (Anthropic) — transfer do USA</h3>
<p>Do generowania narracyjnych komentarzy analitycznych Aplikacja korzysta z interfejsu API Claude, udostępnianego przez <strong>Anthropic, PBC</strong> (USA).</p>
<p>W ramach tego procesu do Anthropic przekazywane są: ticker spółki, wyniki liczbowe modelu CVS (bez danych osobowych Użytkownika — adres e-mail i identyfikator konta nie są przekazywane).</p>
<p>Transfer danych do USA odbywa się na podstawie <strong>Standardowych Klauzul Umownych (SCC)</strong> przyjętych przez Komisję Europejską, zgodnie z art. 46 ust. 2 lit. c RODO. Polityka prywatności Anthropic: <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener">anthropic.com/legal/privacy</a></p>

<h3>3.3. Dostawca usług e-mail (SMTP)</h3>
<p>Alerty e-mail wysyłane są za pośrednictwem skonfigurowanego serwera SMTP. Adres e-mail odbiorcy jest niezbędny do dostarczenia wiadomości i może być przetwarzany przez dostawcę SMTP.</p>

<h2>4. Pliki cookie</h2>
<p>Aplikacja używa wyłącznie <strong>ściśle niezbędnych plików cookie</strong> (strictly necessary cookies):</p>

<table class="pillar-table" style="width:100%;margin:1rem 0;">
    <thead>
        <tr>
            <th>Nazwa</th>
            <th>Typ</th>
            <th>Cel</th>
            <th>Czas życia</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>PHPSESSID</code></td>
            <td>Sesja</td>
            <td>Identyfikator sesji PHP — utrzymanie stanu logowania</td>
            <td>Do zamknięcia przeglądarki</td>
        </tr>
        <tr>
            <td>Token CSRF (w sesji)</td>
            <td>Sesja</td>
            <td>Ochrona formularzy przed atakami CSRF</td>
            <td>Do zamknięcia przeglądarki</td>
        </tr>
    </tbody>
</table>

<p>Żadne inne pliki cookie nie są instalowane. Ściśle niezbędne pliki cookie są zwolnione z wymogu wyrażenia zgody na podstawie art. 5 ust. 3 Dyrektywy ePrivacy (2002/58/WE). <strong>Baner zgody na cookies nie jest wymagany.</strong></p>

<h2>5. Prawa Użytkownika</h2>
<p>Na podstawie RODO (Rozporządzenie UE 2016/679) przysługują Ci następujące prawa:</p>
<ul>
    <li><strong>Prawo dostępu</strong> (art. 15) — możesz żądać potwierdzenia, czy przetwarzamy Twoje dane, oraz ich kopii.</li>
    <li><strong>Prawo sprostowania</strong> (art. 16) — możesz żądać poprawienia nieprawidłowych danych.</li>
    <li><strong>Prawo do usunięcia</strong> (art. 17) — możesz żądać usunięcia danych („prawo do bycia zapomnianym").</li>
    <li><strong>Prawo do ograniczenia przetwarzania</strong> (art. 18).</li>
    <li><strong>Prawo do przenoszenia danych</strong> (art. 20) — możesz żądać kopii danych w formacie nadającym się do odczytu maszynowego.</li>
    <li><strong>Prawo sprzeciwu</strong> (art. 21) — wobec przetwarzania opartego na prawnie uzasadnionym interesie.</li>
    <li><strong>Prawo wycofania zgody</strong> (art. 7 ust. 3) — dla alertów e-mail: wyłącz je w Aplikacji lub kliknij link w mailu.</li>
</ul>
<p>Wnioski prosimy kierować na adres: <a href="mailto:blog@timeflow.fun">blog@timeflow.fun</a>. Odpowiedź udzielana jest w terminie do <strong>30 dni</strong>.</p>
<p>Przysługuje Ci także prawo wniesienia skargi do <strong>Prezesa Urzędu Ochrony Danych Osobowych</strong> (UODO), ul. Stawki 2, 00-193 Warszawa, <a href="https://www.uodo.gov.pl" target="_blank" rel="noopener">uodo.gov.pl</a>.</p>

<h2>6. Zmiany Polityki Prywatności</h2>
<p>O istotnych zmianach niniejszej Polityki Prywatności Użytkownicy zostaną poinformowani za pośrednictwem poczty e-mail lub komunikatu w Aplikacji.</p>

<p style="margin-top:3rem;padding-top:1.5rem;border-top:1px solid var(--c-border);color:var(--c-text-muted);font-size:.8125rem;">
    <a href="/terms-of-service">Regulamin</a>
</p>

</div>
