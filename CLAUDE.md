# livedata – zasady pracy

Wtyczka WordPress `stalowydom-live` dla stalowydom.com (dane z Home Assistant na podstronach urządzeń).

## Wydawanie wtyczki
- Każda zmiana w `stalowydom-live/` = podbicie `Version:` i `SDL_VER` w `stalowydom-live/stalowydom-live.php`.
- Push na `main` → GitHub Actions sam buduje `stalowydom-live.zip` i publikuje wydanie `vX.Y.Z`.
- WordPress wykrywa nową wersję sam (Wtyczki → Aktualizacje; auto-aktualizacje włączone).
- Przed wydaniem: `php -l`, `node --check` i `php tools/test-wp-head.php` (sprawdza, czy blok `#sdl-site-css` faktycznie się wypisuje – `php -l` nie wyłapie np. cudzysłowu w CSS). Zmiany wyglądu sprawdzaj na podglądzie (render), zanim wydasz.

- Po ręcznej aktualizacji przez `update.php` sprawdź, czy wtyczka jest nadal aktywna (Wtyczki) – aktualizacja wywołana w tle zostawia ją wyłączoną. Link „Włącz” z listy wtyczek jest względny – wywołuj go jako `/wp-admin/plugins.php?…`, inaczej (spoza wp-admin) trafi w 404 i wtyczka zostanie wyłączona.

## Poprawki wyglądu witryny
- Drobne poprawki motywu Divi dla całej strony (np. menu mobilne) są we wtyczce, w akcji `wp_head` (`#sdl-site-css`).
- Szablony Divi (stopka, nagłówek) i formularze WPForms można czytać i zapisywać przez `GET/POST /wp-json/sdl/v1/layout/<id>` (tylko administrator). Stopki: 46 (podstrony), 313 (strona główna).
- Stopka zawiera link „Polityka prywatności” (/polityka-prywatnosci/, page_id 397 – oficjalna polityka WordPressa i dokument w banerze Complianz).
- Po każdej zmianie sprawdź całą stronę – także nagłówek, logo i menu mobilne (widok telefonu, rozwinięte menu), nie tylko treść podstron.
- Po zapisaniu treści strony przez REST sprawdź wygląd. Gdy zniknie ciemne tło (style Divi z błędną numeracją sekcji), wyczyść CSS Divi: admin-ajax `et_core_page_resource_clear` (nonce `clear_page_resources_nonce` z Divi → Theme Options) i odwiedź strony anonimowo.
- Znalezione literówki na stronach poprawiamy od razu, bez pytania (z podbiciem wersji podstrony urządzenia). Treść merytoryczną zmieniamy tylko po uzgodnieniu.
- Adres e-mail na stronach podajemy tylko przez `[sdl_email u="…" d="…"]` (zakodowany przed robotami), nigdy zwykłym tekstem.

## Wersjonowanie podstron (obowiązkowe)
Każda zmiana treści lub wyglądu podstrony urządzenia (np. /heiko/) wymaga aktualizacji shortcode'u na tej stronie:

    [sdl_wersja v="1.1" data="DD.MM.RRRR"]1.1 – co się zmieniło
    1.0 – pierwsza publikacja …[/sdl_wersja]

- Podbij `v`, ustaw `data`, dopisz nową linię NA GÓRZE listy (najnowsza pierwsza).
- Mała zmiana (literówka, zdjęcie) → x.Y+1; duża (nowa sekcja, nowy wykres) → X+1.0.
- Zmiana wtyczki, która widocznie zmienia podstronę (np. nowy wykres), też liczy się jako zmiana tej podstrony.

Historia:
- Heiko (/heiko/, page_id 369) – 1.0 z 21.11.2026, 1.1 i 1.2 tego samego dnia; 2.0 z 22.11.2026 (sekcja odszraniania [sdl_odszranianie]), 2.1 i 2.2 tego samego dnia; 2.3–2.9 z 23–24.11.2026; 3.0 z 24.11.2026 (sekcja „Warunki szronienia” w [sdl_odszranianie]); 3.1–3.2 z 26.11.2026.
- Starlink (/starlink/, page_id 480, shortcode [sdl_starlink]) – 1.0 z 26.11.2026. Statystyki przesyłu liczone z bazy (`sdl_stream_stats()`).
- Dom na żywo (/live/, page_id 383, shortcode [sdl_dom]) – 1.0–1.8 z 22.11.2026, 1.9–1.15 z 23–24.11.2026. Link z kafelka „Dashboard Live” na stronie głównej.

## Agenci AI i SEO
- Strona ma być przyjazna agentom AI bez zmian wyglądu: `lang="pl-PL"`, dane live wpisane w HTML po stronie serwera, ukryty wizualnie blok „Dane w formie tekstu” (`.sdl-sr`), Dataset JSON-LD, `/llms.txt` generowany przez wtyczkę.
- Każdy obrazek ma ALT (w module Divi i w bibliotece mediów); każda strona ma jeden H1 i opis Rank Math (`POST /sdl/v1/meta/<id>`).
- Nowe urządzenie lub strona = dopisać je do `/llms.txt` i do podsumowania tekstowego.

## Dane z Home Assistant
- HA co 5 min wysyła odczyty urządzeń (heiko, dom, reqnet, maty, pompa_cwu, serwer) na `POST /wp-json/sdl/v1/push` z tokenem w nagłówku `X-SDL-Token`. Token jest tylko w bazie WordPressa i w sekretach HA – nigdy w tym repozytorium.
- Maty (`maty`) wysyłają też odczyty zaworu mieszającego z Sinum (`zawor_zadana`, `zawor_temp`, `zawor_powrot`, `zawor_przed`, `zawor_co`, `zawor_otwarcie`); strata wężownicy = `heiko.noel_bot` − `zawor_przed`. `zawor_co` nie jest pokazywany (czujnik do poprawy).
- Pompa ciepła wysyła też zużycie z podziałem CO/CWU, liczbę i czas faktycznych odszronień oraz `rh_out` (wilgotność na zewnątrz), `tp` (parownik), `fan`. Szronienie liczy `sdl_frost()` (PHP) i `frost()` w sdl-defrost.js – te same wzory Magnusa.
- Sumy od początku roku i średnie startów: `sdl_heiko_totals()`. Dni do 25.11.2026 zaimportowane z historii HA (statystyki długoterminowe) jako `kwh_day`, `starts_day`, `run_day` (mają pierwszeństwo); późniejsze liczone z bieżących odczytów (`energy_total`, `starts`, `run_h`).
- Strona /projekt: shortcode `[sdl_bilans]` (liczby z OZC w `sdl_ozc()`, struktura strat, zdjęcie murku `assets/img/murek-fundament.webp`, „czy 3,6 kW to możliwe” z wczorajszych danych, dwa wykresy sezonów z `/sdl/v1/seasons`). Historia zaimportowana: `tout_day` (śr. dobowa temp. zewn. z HA od 20.01.2026), `mat_day` (śr. dobowa temp. wody do mat z historii Sinum, 12.2025–04.2026 i od 20.09.2026). Adresu, działki, projektanta i grubości izolacji z OZC nie publikujemy.
- Zdarzenia odszraniania (urządzenie `heiko_defrost`) strona czyta z `/sdl/v1/events`.
- Szczegóły konfiguracji HA (nazwy sensorów i automatyzacji) trzymamy poza repozytorium.

## Kod QR
- Kody QR wskazują na `https://stalowydom.com/qr` (lub `/qr/<nazwa>` dla osobnych kodów, np. ulotka, baner) – nasza domena, więc kod nie wygasa. Wtyczka liczy skany (łącznie, osoby, dziś), przekierowuje na stronę główną z `utm_source=qr&utm_medium=<nazwa>`.
- Statystyki: `GET /sdl/v1/qr` z nagłówkiem `X-SDL-Token` (HA, pakiet REST) lub jako administrator. Adresów IP nie zapisujemy – tylko dobowy skrót do liczenia osób.

## Prywatność
- Nie publikujemy nazw hostów, systemów ani szczegółów domowej sieci – ani na stronie, ani w tym repozytorium.
- Nie zapisujemy w repozytorium tokenów, haseł, adresów IP ani danych kontaktowych.
- Pompa CWU: tylko zużycie energii i liczba włączeń dziś (bez mocy chwilowej; wtyczka odrzuca moc przy zapisie).
