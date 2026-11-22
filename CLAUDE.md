# livedata – zasady pracy

Wtyczka WordPress `stalowydom-live` dla stalowydom.com (dane z Home Assistant na podstronach urządzeń).

## Wydawanie wtyczki
- Każda zmiana w `stalowydom-live/` = podbicie `Version:` i `SDL_VER` w `stalowydom-live/stalowydom-live.php`.
- Push na `main` → GitHub Actions sam buduje `stalowydom-live.zip` i publikuje wydanie `vX.Y.Z`.
- WordPress wykrywa nową wersję sam (Wtyczki → Aktualizacje; auto-aktualizacje włączone).
- Przed wydaniem: `php -l` i `node --check`. Zmiany wyglądu sprawdzaj na podglądzie (render), zanim wydasz.

## Poprawki wyglądu witryny
- Drobne poprawki motywu Divi dla całej strony (np. menu mobilne) są we wtyczce, w akcji `wp_head` (`#sdl-site-css`).

## Wersjonowanie podstron (obowiązkowe)
Każda zmiana treści lub wyglądu podstrony urządzenia (np. /heiko/) wymaga aktualizacji shortcode'u na tej stronie:

    [sdl_wersja v="1.1" data="DD.MM.RRRR"]1.1 – co się zmieniło
    1.0 – pierwsza publikacja …[/sdl_wersja]

- Podbij `v`, ustaw `data`, dopisz nową linię NA GÓRZE listy (najnowsza pierwsza).
- Mała zmiana (literówka, zdjęcie) → x.Y+1; duża (nowa sekcja, nowy wykres) → X+1.0.
- Zmiana wtyczki, która widocznie zmienia podstronę (np. nowy wykres), też liczy się jako zmiana tej podstrony.

Historia:
- Heiko (/heiko/, page_id 369) – 1.0 z 21.11.2026, 1.1 i 1.2 tego samego dnia.
- Dom na żywo (/live/, page_id 383, shortcode [sdl_dom]) – 1.0 i 1.1 z 22.11.2026. Link z kafelka „Dashboard Live” na stronie głównej.

## Dane z Home Assistant
- Automatyzacja HA `sdl_push_heiko` („WWW: dane domu na stalowydom.com”) co 5 min wysyła urządzenia: heiko, dom, reqnet, maty, pompa_cwu, atlas (lista sensorów w zmiennej `devices` automatyzacji).
- Podział energii pompy CO/CWU: licznik z taryfami `select.pompa_ciepla_energia_co_cwu` przełączany automatyzacją `sdl_licznik_co_cwu` (temperatura zadana bieżącego trybu = temperatura CWU → cwu).
- Odszranianie: surowa flaga `sensor.heiko_odszranianie` wisi do następnego startu sprężarki, więc liczymy `binary_sensor.heiko_odszranianie_faktyczne` (flaga = 1, sprężarka > 0 Hz, wentylator 1 < 50 obr/min) i z niego liczba/czas dziś (history_stats, czas w godzinach → na stronie minuty).
