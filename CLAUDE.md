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
- Obie stopki (46 i 313) mają nad „©” małą szarą linię „Strona powstaje przy współpracy z Claude Opus 5.5” (12 px, #6b7680).
- Stopka zawiera link „Polityka prywatności” (/polityka-prywatnosci/, page_id 397 – oficjalna polityka WordPressa i dokument w banerze Complianz).
- Po każdej zmianie sprawdź całą stronę – także nagłówek, logo i menu mobilne (widok telefonu, rozwinięte menu), nie tylko treść podstron.
- Po zapisaniu treści strony przez REST sprawdź wygląd. Gdy zniknie ciemne tło (style Divi z błędną numeracją sekcji), wyczyść CSS Divi: admin-ajax `et_core_page_resource_clear` (nonce `clear_page_resources_nonce` z Divi → Theme Options) i odwiedź strony anonimowo.
- Znalezione literówki na stronach poprawiamy od razu, bez pytania (z podbiciem wersji podstrony urządzenia). Treść merytoryczną zmieniamy tylko po uzgodnieniu.
- Adres e-mail na stronach podajemy tylko przez `[sdl_email u="…" d="…"]` (zakodowany przed robotami), nigdy zwykłym tekstem.

## Wersjonowanie podstron (obowiązkowe)
Każda podstrona oprócz /kontakt/ (i strony głównej) ma blok wersji. Każda zmiana treści lub wyglądu podstrony (np. /heiko/, /projekt/, /sprzet/) wymaga aktualizacji shortcode'u na tej stronie:

    [sdl_wersja v="1.1" data="DD.MM.RRRR"]1.1 – co się zmieniło
    1.0 – pierwsza publikacja …[/sdl_wersja]

- Podbij `v`, ustaw `data`, dopisz nową linię NA GÓRZE listy (najnowsza pierwsza).
- Mała zmiana (literówka, zdjęcie) → x.Y+1; duża (nowa sekcja, nowy wykres) → X+1.0.
- Zmiana wtyczki, która widocznie zmienia podstronę (np. nowy wykres), też liczy się jako zmiana tej podstrony.

Historia:
- Heiko (/heiko/, page_id 369) – 1.0 z 21.11.2026, 1.1 i 1.2 tego samego dnia; 2.0 z 22.11.2026 (sekcja odszraniania [sdl_odszranianie]), 2.1 i 2.2 tego samego dnia; 2.3–2.9 z 23–24.11.2026; 3.0 z 24.11.2026 (sekcja „Warunki szronienia” w [sdl_odszranianie]); 3.1–3.6 z 26.11.2026; 4.0–4.1 z 26.11.2026 (filmy YouTube na dole); 5.0 z 26.11.2026 (wykres kWh na stopień: zużycie / (20 °C − śr. temp. zewn.), doby ≤ 12 °C, średnia 7 dni, odniesienie do poprzedniej zimy z /sdl/v1/seasons); 6.0 z 26.11.2026 (sekcja „Grzałki kontra pompa ciepła” – shortcode [sdl_grzalki] przed filmami); 7.0 z 26.11.2026 (sekcja „COP: laboratorium kontra prawdziwa instalacja” – [sdl_cop], po grzałkach); 8.0 z 26.11.2026 (sekcja „COP zmierzony: bufor jako ciepłomierz” – [sdl_cop_pomiar]); 8.1 z 26.11.2026 (wiersz „Praca sprężarki” w 5 ostatnich miesiącach, między zużyciem miesięcznym a sumami od początku roku); 8.2 z 26.11.2026 (kolory: zużycie miesięczne #3b82f6, czas pracy #84cc16); 9.0 z 27.11.2026 (pasek „Tryb pracy i harmonogram” między kafelkami a buforem, oddzielony liniami `.sdl-hr`); 10.0 z 27.11.2026 (w [sdl_cop_pomiar] punkty 7 „A jaki jest SCOP?” i 8 „Ciepłomierz”; nowa sekcja „Ciepła woda, ogrzewanie i plan rozwojowy” – [sdl_plan], po COP zmierzonym; w pasku trybu częstotliwość sprężarki); 10.1 z 27.11.2026 (poprawiony podtytuł sekcji planu; kotwice #plan-rozwojowy i #czego-nauczyl-nas-dom); 10.2 z 27.11.2026 (punkt 5 „Plan optymalizacyjny”; usunięta zdublowana część o rekuperatorze w [sdl_cop_pomiar]); 10.3 z 27.11.2026 (krok 1 wdrożony, punkt +10 °C → 30 °C); 10.4 z 28.11.2026 (opis kroku 1: przesunięcie krzywej +3 K wyzerowane, uczciwie o zysku przy 0 °C); 10.5 z 28.11.2026 (czujniki bufora w tulejach, nie na powierzchni; w planie optymalizacyjnym „Krok 0: kalibracja czujników” #kalibracja – lód 0 °C, myjka 30 °C; wyniki do opublikowania); 11.0 z 28.11.2026 (wykres „Zawór mieszający a krzywa pompy” #zawor-a-krzywa – 48 h z /sdl/v1/series range=48h, assets/sdl-zawor.js; wniosek: krzywą pompy układamy z zależności temp. za zaworem od zadanej pompy, po kalibracji). Imion domowników nie publikujemy („jeden z pokoi dzieci”).
- Starlink (/starlink/, page_id 480, shortcode [sdl_starlink]) – 1.0 z 26.11.2026 (zmiany z dnia publikacji zebrane w 1.0 na życzenie właściciela). Sekcja „A ile danych pobieramy”: miesięczne zużycie wpisane ręcznie w `$use` z aplikacji Starlink – aktualizować co miesiąc. Statystyki przesyłu liczone z bazy (`sdl_stream_stats()`).
- reQnet (/reqnet/, page_id 534, shortcode [sdl_reqnet]) – 2.0 z 27.11.2026 (kafelki COP rekuperatora, SCOP sezonu z dób < 12 °C, SPI; kolumna COP w tabeli; sekcja 6 „COP i SCOP rekuperatora”; tabele w punktach 2 i 3 zwijane, tabela dób do 30 dni); 1.1 z 27.11.2026 (kubatura domu ok. 250 m³ – rzeczywista 247 m³, piszemy 250); 1.0 z 27.11.2026 (centrala reQ V 400 ERV: KPI na żywo, sprawność odzysku, odzysk i ciepło do dogrzania liczone wzorem 0,34 Wh/m³K, tabela ostatnich dób, analiza 110/160/207 m³/h, uczenie się wilgotności przez automatykę, test 160 m³/h od 27.11.2026). Link z kafelka reQnet na /live/.
- Projekt (/projekt/, page_id 67) – 4.0 z 26.11.2026 (sekcja „Sprawdzian z czasów grzałek: 3,4 kW” w [sdl_bilans]); 4.1 z 27.11.2026 („Drugi sprawdzian: doba z pompą ciepła” – 27.11, −1 °C, ok. 49 kWh z pompy wobec 48 kWh z OZC); 4.2 z 28.11.2026 („Ile dał murek?” – tabela 120/100, podłoga 47→27, −17–20% całego ogrzewania); Sprzęt (/sprzet/, page_id 90) – 2.2 z 26.11.2026, 2.3 z 27.11.2026 (przycisk na kafelku reQnet); 2.4 z 27.11.2026 (nowy kafelek „Maty kapilarne” obok Heiko; pozostałe kafelki przesunięte, ostatni wiersz: tylko „Twoje logo”); 2.5 z 27.11.2026 (na kafelku mat logo matykapilarne.com – grafika maty + napis, media id 550; Hydrokapillare świadomie nie pokazujemy); Aquilo (/aquilo/, page_id 199) i Polityka prywatności (page_id 397) – 1.0 z 26.11.2026 (blok wersji nad H1; wcześniejsza historia odtworzona z ustaleń z właścicielem).
- Maty kapilarne (/maty-kapilarne/, page_id 543, shortcode [sdl_maty]) – 1.0 z 27.11.2026 (kafelki zaworu mieszającego na żywo, wykład: Wien, Stefan–Boltzmann, tabela mocy sufitu 25–32 °C, 70 W/m² producenta, temperatura odczuwalna, termowizja pokoi, łazienek i sypialni; łazienki: mikrocement na podłogach i ścianach, sufity g-k; maty typu B mają rurki 4,3 mm). Zdjęcia w `assets/img/termo-*.webp`. Kafelek mat na /live/ i /sprzet/ prowadzi tutaj.
- Dom na żywo (/live/, page_id 383, shortcode [sdl_dom]) – 1.0–1.8 z 22.11.2026, 1.9–1.15 z 23–24.11.2026; 1.16 z 27.11.2026 (kafelek Heiko: tryb teraz, częstotliwość sprężarki, harmonogram); 1.17 z 27.11.2026 (kafelek reQnet prowadzi do /reqnet/); 1.18 z 27.11.2026 (kafelek mat prowadzi do /maty-kapilarne/). Link z kafelka „Dashboard Live” na stronie głównej.

## Agenci AI i SEO
- Strona ma być przyjazna agentom AI bez zmian wyglądu: `lang="pl-PL"`, dane live wpisane w HTML po stronie serwera, ukryty wizualnie blok „Dane w formie tekstu” (`.sdl-sr`), Dataset JSON-LD, `/llms.txt` generowany przez wtyczkę.
- Każdy obrazek ma ALT (w module Divi i w bibliotece mediów); każda strona ma jeden H1 i opis Rank Math (`POST /sdl/v1/meta/<id>`).
- Nowe urządzenie lub strona = dopisać je do `/llms.txt` i do podsumowania tekstowego.
- **Nowa podstrona urządzenia = OBOWIĄZKOWO przycisk „zobacz więcej” na jego kafelku na /sprzet/ (page_id 90)** – skopiuj przycisk z innego kafelka (moduł `divi/button`, link to zmienna Divi `post_link_url_page` z `post_id` nowej strony), przycisk ma `margin.top` −86px, a karta (moduł `divi/blurb`) musi mieć `padding.bottom` 80px – tak jak pozostałe kafelki; wtedy przycisk siedzi w karcie na każdej szerokości. Sprawdź na 1300, 768 i 375 px. Podbij wersję /sprzet/. Do tego link z kafelka na /live/ (`sdl_dom_row`, ostatni argument).

## Dane z Home Assistant
- HA co 5 min wysyła odczyty urządzeń (heiko, dom, reqnet, maty, pompa_cwu, serwer) na `POST /wp-json/sdl/v1/push` z tokenem w nagłówku `X-SDL-Token`. Token jest tylko w bazie WordPressa i w sekretach HA – nigdy w tym repozytorium.
- Maty (`maty`) wysyłają też odczyty zaworu mieszającego z Sinum (`zawor_zadana`, `zawor_temp`, `zawor_powrot`, `zawor_przed`, `zawor_co`, `zawor_otwarcie`); strata wężownicy = `heiko.noel_bot` − `zawor_przed`. `zawor_co` nie jest pokazywany (czujnik do poprawy).
- Pompa ciepła wysyła też zużycie z podziałem CO/CWU, liczbę i czas faktycznych odszronień oraz `rh_out` (wilgotność na zewnątrz), `tp` (parownik), `fan`. Szronienie liczy `sdl_frost()` (PHP) i `frost()` w sdl-defrost.js – te same wzory Magnusa.
- Sumy od początku roku i średnie startów: `sdl_heiko_totals()`. Dni do 25.11.2026 zaimportowane z historii HA (statystyki długoterminowe) jako `kwh_day`, `starts_day`, `run_day` (mają pierwszeństwo); późniejsze liczone z bieżących odczytów (`energy_total`, `starts`, `run_h`).
- Strona /projekt: shortcode `[sdl_bilans]` (liczby z OZC w `sdl_ozc()`, struktura strat, zdjęcie murku `assets/img/murek-fundament.webp`, „czy 3,6 kW to możliwe” z wczorajszych danych, dwa wykresy sezonów z `/sdl/v1/seasons`). Historia zaimportowana: `tout_day` (śr. dobowa temp. zewn. z HA od 20.01.2026), `mat_day` (śr. dobowa temp. wody do mat z historii Sinum, 12.2025–04.2026 i od 20.09.2026). Adresu, działki, projektanta i grubości izolacji z OZC nie publikujemy.
- Czujniki bufora NOEL (góra/środek/dół) to czujniki Zigbee włożone do tulei w zbiorniku (pomiar wody, nie powierzchni). Czujniki pompy (Heiko) też są w zbiorniku: TW w połowie (tuż pod NOEL środek), TC na samym dole (nad NOEL dół) – potwierdzone przez właściciela 28.11.2026. Ogrzewanie pompa reguluje na TC, CWU na TW. TC pokazuje ok. 2 K więcej niż NOEL dół – planowana kalibracja wszystkich czujników w kąpieli wodnej (lód 0 °C + 30/45 °C z termometrem wzorcowym).
- COP z cykli bufora: urządzenie `heiko_cop` (metryki cop, tout, kind 0=CO/1=CWU, el, dur, tb, te; ts = start cyklu). 57 cykli 16–26.11.2026 zaimportowane z HA (dokładność 1 min); nowe wykrywa `sdl_cop_detect()` z odczytów co 5 min (bilans bufora 0,738 kWh/K + stygnięcie przed/po cyklu). REST `/sdl/v1/cop`, katalog Heiko Thermal 15 (49 Hz) w `sdl_cop_catalog()`. Instrukcja Heiko (2023) – tabele wydajności Thermal 15 na str. 26–27, karta katalogowa str. 18.
- Tryb pracy i harmonogram (urządzenie `heiko`, osobna wysyłka w tej samej automatyzacji): `mode` (0 postój, 1 CWU, 2 ogrzewanie), `sel_mode` (0 Ogrzewanie, 1 Auto, 2 CWU, 3 Chłodzenie), `sched_on` (0/1), `sched_status` (0 wyłączony, 1 wg harmonogramu, 2 czeka na CWU, 3 grzeje CWU, potem ogrzewanie), `auto_from`, `co_from` (minuty od północy), `hz` (częstotliwość sprężarki).
- Rekuperator (`reqnet`) wysyła też `co2` [ppm] i `rh` [%] z czujników centrali (od 27.11.2026).
- Plan optymalizacyjny nastaw pompy (opis: [sdl_plan] pkt 5, #plan-optymalizacyjny): krok 1 wdrożony 27.11.2026 ok. 23:00 – krzywa grzewcza włączona (−20/−10/−5/+5/+10 °C → 44/41/39/35/30 °C; dla +10 °C właściciel zostawił 30 zamiast proponowanych 33; wcześniej stała 37 °C), histerezy sprężarki 5 → 3 K; 28.11.2026 ok. 00:17 „Curve 1 Parallel Move” 3 → 0 (przesunięcie +3 K dawało 39 °C zamiast 36 °C). Sterownik interpoluje liniowo, ale bierze uśrednioną temperaturę zewnętrzną (inną niż chwilowa). Zawór mieszający – punkt odniesienia 25–27.11: >0,5 K poniżej zadanej 43–52% doby, >1 K 34–39%, zawór 100% otwarty 35–42%, śr. niedobór ok. 2 K. Kroki 2 (CWU raz dziennie ok. 13–16) i 3 (CWU 48 → 45 °C + dezynfekcja raz w tygodniu) po 4–5 dniach obserwacji każdego. Porównanie: COP z cykli bufora, starty, temperatury pokoi przy podobnej pogodzie.
- Zdarzenia odszraniania (urządzenie `heiko_defrost`) strona czyta z `/sdl/v1/events`.
- Szczegóły konfiguracji HA (nazwy sensorów i automatyzacji) trzymamy poza repozytorium.

## Grzałki (rok 2025) i filmy
- Do 25.12.2025 dom grzały grzałki w buforze (sterownik mybero – na razie bez zasilania; po podłączeniu pobrać dane dobowe i dodać porównanie pod przyciskiem „porównaj z grzałkami”, nie mieszać z panelem na żywo).
- Liczby w [sdl_grzalki] i na /projekt pochodzą z filmów YouTube właściciela; przy każdej link do minuty: `sdl_yt($id, $sekunda, $opis)`. Filmy: rKTyAxtBmX8 („Pompa ciepła zimą…”), CftcsQWNF08 („I mnie dopadło…”).

## Kod QR
- Kody QR wskazują na `https://stalowydom.com/qr` (lub `/qr/<nazwa>` dla osobnych kodów, np. ulotka, baner) – nasza domena, więc kod nie wygasa. Wtyczka liczy skany (łącznie, osoby, dziś), przekierowuje na stronę główną z `utm_source=qr&utm_medium=<nazwa>`.
- Statystyki: `GET /sdl/v1/qr` z nagłówkiem `X-SDL-Token` (HA, pakiet REST) lub jako administrator. Adresów IP nie zapisujemy – tylko dobowy skrót do liczenia osób.

## Prywatność
- Nie publikujemy nazw hostów, systemów ani szczegółów domowej sieci – ani na stronie, ani w tym repozytorium.
- Nie zapisujemy w repozytorium tokenów, haseł, adresów IP ani danych kontaktowych.
- Pompa CWU: tylko zużycie energii i liczba włączeń dziś (bez mocy chwilowej; wtyczka odrzuca moc przy zapisie).
