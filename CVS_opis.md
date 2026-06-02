# CVS — Composite Valuation Score

### Aplikacja, która mówi czy spółka jest tania czy droga — i dlaczego model mówi co innego niż analitycy

---

Inwestor indywidualny patrzy na spółkę i widzi P/E 35. Drogo czy tanio? Nie wiadomo — zależy od sektora, wzrostu, jakości biznesu i dziesiątka innych czynników, które trzeba porównywać ręcznie, wiedzieć jak przeliczać i wiedzieć, w co patrzeć. Większość ludzi na tym etapie odpuszcza albo ślepo kieruje się rekomendacjami analityków z Wall Street.

CVS rozwiązuje dokładnie ten problem. Wprowadzasz symbol spółki, a system w kilka sekund wyciąga dane finansowe, oblicza trzy filary modelu i prezentuje jeden wynik: 0–100, gdzie 72+ to sygnał, żeby patrzeć bliżej, a poniżej 28 — żeby nie tracić czasu. Zamiast szukać, czy P/E jest za wysokie, dostajesz gotową odpowiedź: *tania czy droga względem sektora, własnej historii i jakości biznesu*. Jednocześnie.

---

## Dla kogo

CVS powstało dla inwestora indywidualnego, który chce sam decydować — ale nie chce spędzać godzin na zbieraniu danych, liczeniu wskaźników i sprawdzaniu, co mówi każdy analityk. To ktoś, kto obserwuje kilkanaście spółek, ma swoją watchlistę i chce dostawać sygnały, zanim przegapi okazję.

Aplikacja nie jest dla day traderów ani dla osób, które szukają gotowych porad inwestycyjnych. Jest dla kogoś, kto chce *własnego narzędzia analitycznego* — algorytmu, który mówi „ta spółka jest relatywnie tania, a jej momentum właśnie się poprawia" i wyjaśnia, dlaczego model myśli inaczej niż tłum analityków.

---

## Jak to działa

Sercem CVS jest model oparty na trzech filarach, liczony w dwóch trybach jednocześnie.

**Wycena** sprawdza, czy spółka jest tania względem mediany swojego sektora na bazie EV/FCF — uwzględniając wzrost przychodów i marże, a nie gołe liczby. Spółka technologiczna może kosztować drożej niż przemysłowa i nadal być relatywnie tania, jeśli rośnie 40% rocznie.

**Momentum** mierzy nadwyżkowy zwrot z ceny względem S&P 500 w horyzoncie 3 i 6 miesięcy. Nie interesuje nas, czy cena rośnie — interesuje nas, czy rośnie szybciej niż rynek.

**Jakość** ocenia marżę brutto na tle sektora, dźwignię finansową i dynamikę przyszłego wzrostu. To filtr odrzucający spółki, które wyglądają tanio, bo faktycznie mają słaby biznes.

Te trzy wyniki są ważone w dwóch profilach: **Swing** (1–4 miesiące, dominuje momentum) i **Fundamentalny** (6–12 miesięcy, dominuje wycena). Oba wyniki pojawiają się równolegle, bo horyzont zmienia interpretację.

Gdy obie oceny przekraczają próg 58 — pojawia się **złoty sygnał ⭐⭐**: wartość i momentum wskazują jednocześnie w tym samym kierunku. To najsilniejszy sygnał modelu.

Wszystkie parametry — wagi, progi, benchmarki sektorowe — żyją w jednym pliku konfiguracyjnym. Rdzeń jest deterministyczny i testowalny: te same dane finansowe zawsze dają ten sam wynik.

---

## Gdzie mieszka kontrariańskość

CVS jest świadomie zaprojektowane jako narzędzie kontrariańskie. Jeśli analitycy mówią „Kupuj" na spółkę z wysokim momentum i wysoką wyceną — model powie „Redukuj", bo patrzy na liczby, nie na narrację. Ta rozbieżność jest informacją.

Dlatego najważniejszą funkcją fazy 2 jest **analiza AI wyjaśniająca rozjazd**. Użytkownik PRO na stronie spółki klika „Generuj analizę AI" i dostaje 4-sekcyjną narrację w języku polskim, napisaną przez Claude Sonnet 4.6:

1. Co mówi model CVS (i dlaczego filary wskazują taki, a nie inny wynik)
2. Co mówią analitycy (konsensus, cele cenowe, rozkład rekomendacji)
3. Dlaczego się rozmijają — konkretna hipoteza oparta na liczbach
4. Komu wierzyć i w jakim horyzoncie

Analiza jest *uziemiona* — Claude dostaje twarde dane finansowe, wyniki pilarów i cel cenowy analityków, nie ma dostępu do internetu ani nie zgaduje. Jeśli czegoś nie ma w danych — przyznaje to zamiast konfabulować.

Wygenerowana analiza trafia do wspólnego cache — przez 7 dni jest widoczna dla wszystkich zalogowanych użytkowników bez ponownego wywołania API. Data analizy jest zawsze widoczna.

---

## Pełny system sygnalizacyjny

Faza 2 przekształciła CVS z kalkulatora na żądanie w **system, który pilnuje twoich spółek za ciebie**.

**Screener** pokazuje wszystkie spółki z watchlisty posortowane wg CVS, z filtrami po rekomendacji, złotym sygnale, sektorze i minimalnym wyniku. Dane są odświeżane codziennie przez crona.

**Alerty watchlisty** wysyłają mail, gdy spółka z obserwowanych zmieni rekomendację lub pojawi się złoty sygnał. Deduplikacja zapobiega spamowi — powiadomienie tylko przy zmianie stanu, nie każdego dnia.

**Track record modelu** pokazuje historyczną trafność rekomendacji CVS — czy KUPUJ faktycznie oznaczało wzrost po 30 dniach. Widok jest dostępny od razu, a dane uzupełniają się automatycznie z każdym dniem.

**CVS Fair Value** to dodatkowy punkt danych na wykresie prognoz: cena implikowana przez model przy parytecie sektorowym EV/FCF. Pojawia się obok celów cenowych analityków jako żółta linia, umożliwiając bezpośrednie porównanie „co mówi model" vs „co mówi rynek".

---

## Na czym działa

CVS to monolit napisany w **PHP 8.2** bez frameworka — świadomy wybór dla projektu solo hostowanego na shared hostingu. Router, kontrolery, repozytoria — wszystko własne, PSR-4, strict types, PHPStan level 6. Baza MySQL przez PDO, szablony plain-PHP, CSS vanilla z design tokenami.

Dane rynkowe pobierane z Yahoo Finance przez cURL (darmowe, publiczne endpointy). Cache w sesji PHP — każdy ticker odświeżany maksymalnie raz na godzinę.

Analiza AI przez **Claude API (Anthropic)**. Klient napisany od zera z prompt cachingiem, typowanym wynikiem i guardrailem: awaria API nigdy nie psuje strony — analiza CVS działa niezależnie.

**PHPMailer** na SMTP CF do alertów i formularza prośby o kod PRO. Cron na Cyber_Folks (2× dziennie) zasila snapshoty.

189 testów offline w PHPUnit, PHPStan level 6 bez błędów, ręczny deploy przez SSH + git pull.

---

## Co przyniesie przyszłość

MVP fazy 1 odpowiadało na pytanie: *czy warto patrzeć na tę spółkę?* Faza 2 dodała: *co dokładnie się dzieje i dlaczego model widzi to inaczej niż rynek*.

Następny horyzont to głębsza personalizacja i więcej kontekstu. Porównanie historycznego track recordu CVS z wynikami analityków per sektor — żeby użytkownik wiedział, w których branżach model miał rację częściej. Możliwość ustawienia własnych progów alertów — nie tylko zmiana rekomendacji, ale konkretny próg CVS, który interesuje daną osobę.

Dalej: screener po całym słowniku ~600 spółek zamiast tylko watchlisty, gdy API tego udźwignie. Integracja z innymi źródłami danych poza Yahoo Finance, żeby zmniejszyć zależność od jednego dostawcy.

A na dalekim horyzoncie — back-testowanie całej strategii: jak wyglądałyby historyczne wyniki, gdyby kupować przy CVS ≥ 72 i sprzedawać przy CVS ≤ 28. Odpowiedź na pytanie, które inwestorzy zadają od zawsze: czy ten model naprawdę działa.

---

*CVS — Composite Valuation Score | https://cvs.timeflow.fun | Disclaimer: hipoteza modelu analitycznego, nie rekomendacja inwestycyjna.*
