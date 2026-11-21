# livedata – StalowyDOM Live Data

Wtyczka WordPress, która odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy (pompa ciepła Heiko, bufor NOEL, zużycie energii).

- Shortcode: `[sdl_heiko]`
- Endpoint dla HA: `POST /wp-json/sdl/v1/push` z nagłówkiem `X-SDL-Token`
- Aktualizacje: wtyczka sama sprawdza najnowsze wydanie (Release) w tym repozytorium. Każde wydanie musi mieć załącznik `stalowydom-live.zip`.

## Nowa wersja
1. Podnieś `Version:` i `SDL_VER` w `stalowydom-live/stalowydom-live.php`.
2. Spakuj folder `stalowydom-live` do `stalowydom-live.zip`.
3. Utwórz Release z tagiem `vX.Y.Z` i dołącz zip.
