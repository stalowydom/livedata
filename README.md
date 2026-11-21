# livedata – StalowyDOM Live Data

Wtyczka WordPress, która odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy (pompa ciepła Heiko, bufor NOEL, zużycie energii).

- Shortcode: `[sdl_heiko]`
- Endpoint dla HA: `POST /wp-json/sdl/v1/push` z nagłówkiem `X-SDL-Token`
- Aktualizacje: wtyczka sama sprawdza najnowsze wydanie (Release) w tym repozytorium. Każde wydanie musi mieć załącznik `stalowydom-live.zip`.

## Nowa wersja
1. Podnieś `Version:` i `SDL_VER` w `stalowydom-live/stalowydom-live.php`.
2. Wyślij na main – GitHub Actions sam zbuduje zip i opublikuje wydanie `vX.Y.Z`.
