# APRS Object Beacon

🇺🇸 [English version](README.md)

Wysyła do sieci APRS-IS **obiekty APRS** co 10 minut. Obiektem może być przemiennik (np. `441.675+`), centrum kryzysowe, punkt medyczny na imprezie, stanowisko dowodzenia albo stacja terenowa, czyli każde miejsce, które ludzie mają znaleźć na mapie. Komentarz zmienia się sam: święta, wydarzenia, rotacja tekstów i aktualna pogoda z OpenWeather. Wszystko ustawiasz w przeglądarce, pod adresem `http://ADRES_PI/aprs/`.

## Po co obiekty?

Zwykła stacja APRS pokazuje swoją własną pozycję. **Obiekt** to punkt na mapie z własną nazwą, wysyłany w imieniu czegoś innego. Widać go na aprs.fi, w aplikacjach APRS i na ekranach radiotelefonów, razem z odległością.

| Zastosowanie | Przykład | Co widać |
|---|---|---|
| Przemiennik | `441.675+` | częstotliwość, ton, offset, zasięg, pogoda |
| Centrum kryzysowe | `EOC-COOK` | gdzie jest centrum i jak się zgłosić |
| Impreza, zabezpieczenie | `MEDIC-1` | punkty medyczne i punkty pomocy |
| Stanowisko dowodzenia | `NCS-312` | gdzie dziś jest stacja prowadząca |

Obiekt przemiennika nazwany częstotliwością ma jeszcze jedną zaletę: na wielu radiach Kenwood i Yaesu można się na niego dostroić jednym przyciskiem, razem z tonem.

Open source project by **W9GIL** · [Group 312 Chicago](https://group312chicago.org)

![Edytor APRS Object Beacon](docs/screenshot.png)

---

## Instalacja

Na Raspberry Pi (Raspberry Pi OS / Debian, także obok SvxLink i hotspota) wpisz jedno polecenie:

```bash
curl -fsSL https://raw.githubusercontent.com/woyteg/aprs-object-beacon/main/install.sh | sudo bash
```

`sudo -s` nie jest potrzebne. Instalator:

- wykrywa serwer WWW (Apache, lighttpd albo nginx) i dokłada PHP; jeśli żadnego nie ma, instaluje Apache,
- instaluje program w `/opt/aprs-beacon`, a edytor w `/var/www/html/aprs`,
- włącza automatyczne wysyłanie co 10 minut (systemd timer `aprs-beacon.timer`),
- na końcu pokazuje adres edytora.

Potem otwórz `http://ADRES_PI/aprs/`, wpisz swój **znak** i **pozycję** obiektu. Dopóki tego nie zrobisz, beacon nic nie wysyła. Kolejne obiekty dodasz przyciskiem **+ Dodaj obiekt**.

## Aktualizacja

To samo polecenie co przy instalacji. Twoje ustawienia, komentarze i klucz API zostają nietknięte.

## Co jest w edytorze

| Sekcja | Co ustawiasz |
|---|---|
| Teraz w eterze | podgląd ramki pokolorowany po polach, ostatnie i następne wysłanie, przycisk „Wyślij teraz” |
| Stacja nadawcza | znak z SSID; passcode APRS-IS liczy się sam |
| Obiekty | nazwa, pozycja (wklejona z Google Maps), ikona, stały opis; dla przemienników ton, offset, zasięg; dodawanie i usuwanie obiektów |
| Pogoda | klucz OpenWeather, jednostki, szablon; `{wx}` wstawia pogodę w dowolny tekst |
| Komentarze domyślne | teksty zmieniane co 10 minut |
| Wydarzenia cykliczne | święta, zawody, regularne spotkania: stałe daty i liczone automatycznie (Wielkanoc, Memorial Day, Thanksgiving, ARRL Field Day, Winter Field Day, „N-ty dzień tygodnia”, także co miesiąc) |
| Wydarzenia jednorazowe | zapowiedź „za X dni” i tekst w dniu wydarzenia |

Kolejność komentarza: wydarzenie jednorazowe w toku, potem jego zapowiedź, potem wydarzenie cykliczne, a na końcu rotacja.

Edytor jest po polsku i po angielsku; język przełączasz flagą w nagłówku.

### Daty wydarzeń cyklicznych

| Data | Znaczenie |
|---|---|
| `12-24` | stała data (MM-DD) |
| `easter`, `memorial`, `labor`, `thanksgiving` | liczone co roku |
| `fieldday` | ARRL Field Day: sobota 4. pełnego weekendu czerwca |
| `winterfieldday` | Winter Field Day: sobota ostatniego pełnego weekendu stycznia |
| `nth:M:D:N` | N-ty dzień tygodnia D (1 = pon … 7 = niedz) miesiąca M; N `-1` = ostatni; M `*` = każdy miesiąc |
| `…+1`, `…-2` | przesunięcie o dni: `fieldday+1` = niedziela Field Day, `easter+1` = Poniedziałek Wielkanocny |

Przykład, każdy pierwszy poniedziałek miesiąca:

```
nth:*:1:1 | Zapraszamy na Gracjane!
```

Edytor działa **bez hasła**, ale tylko z sieci lokalnej. Spoza LAN pokazuje komunikat o braku dostępu.

## Pogoda

1. Załóż darmowe konto na [openweathermap.org](https://openweathermap.org) i skopiuj klucz z **My API keys**. Nowy klucz aktywuje się do 2 godzin.
2. Wklej go w edytorze w sekcji **Pogoda**.
3. Wpisz `{wx}` w stały opis (pogoda zawsze) albo jako linię w komentarzach domyślnych (co któryś beacon).

Przykład: `WX 45F 1016hPa W12mph G22 chm75% 0.05in`. Pogoda jest pobierana raz na godzinę dla pozycji pierwszego obiektu.

## Pliki

```
/opt/aprs-beacon/beacon.py       program
/opt/aprs-beacon/config.json     stacja, obiekty, pogoda
/opt/aprs-beacon/comments.json   komentarze, święta, wydarzenia
/var/www/html/aprs/index.php     edytor
```

Przydatne polecenia:

```bash
sudo -u www-data python3 /opt/aprs-beacon/beacon.py --dry-run   # podgląd bez wysyłania
journalctl -u aprs-beacon -n 20                                 # log wysyłania
systemctl list-timers aprs-beacon.timer                         # kiedy następne wysłanie
```

## Odinstalowanie

```bash
sudo bash /opt/aprs-beacon/src/uninstall.sh
```

Kopia ustawień zostaje w `/root/aprs-beacon-backup-*.tar.gz`.

## Uwagi

- Oficjalna specyfikacja APRS przewiduje 43 znaki komentarza obiektu. APRS-IS i aprs.fi przyjmują dłuższe, ale radia na wyświetlaczu zwykle pokazują tylko początek. Limit ustawisz w `config.json` (`max_comment`, domyślnie 160).
- Program wysyła przez internet (APRS-IS), nie przez radio.

## Licencja

MIT, patrz [LICENSE](LICENSE). 73!
