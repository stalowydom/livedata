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
- Heiko (/heiko/, page_id 369) – 1.0 z 21.11.2026, 1.1 i 1.2 tego samego dnia; 2.0 z 22.11.2026 (sekcja odszraniania [sdl_odszranianie]), 2.1 i 2.2 tego samego dnia.
- Dom na żywo (/live/, page_id 383, shortcode [sdl_dom]) – 1.0–1.8 z 22.11.2026. Link z kafelka „Dashboard Live” na stronie głównej.

## Dane z Home Assistant
- HA co 5 min wysyła odczyty urządzeń (heiko, dom, reqnet, maty, pompa_cwu, serwer) na `POST /wp-json/sdl/v1/push` z tokenem w nagłówku `X-SDL-Token`. Token jest tylko w bazie WordPressa i w sekretach HA – nigdy w tym repozytorium.
- Pompa ciepła wysyła też zużycie z podziałem CO/CWU oraz liczbę i czas faktycznych odszronień.
- Zdarzenia odszraniania (urządzenie `heiko_defrost`) strona czyta z `/sdl/v1/events`.
- Szczegóły konfiguracji HA (nazwy sensorów i automatyzacji) trzymamy poza repozytorium.

## Prywatność
- Nie publikujemy nazw hostów, systemów ani szczegółów domowej sieci – ani na stronie, ani w tym repozytorium.
- Nie zapisujemy w repozytorium tokenów, haseł, adresów IP ani danych kontaktowych.
- Pompa CWU: tylko zużycie energii i liczba włączeń dziś (bez mocy chwilowej; wtyczka odrzuca moc przy zapisie).
