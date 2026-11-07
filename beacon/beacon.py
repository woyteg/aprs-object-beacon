#!/usr/bin/env python3
"""APRS Object Beacon - open source project by W9GIL (Group 312 Chicago).

Uruchamiany co 10 min przez systemd timer (aprs-beacon.timer). Wysyla obiekt(y) przemiennika
(np. "441.675+") do APRS-IS z komentarzem dobranym automatycznie:
  1. wydarzenie w toku
  2. zapowiedz wydarzenia (X dni przed)
  3. swieto
  4. rotacja komentarzy domyslnych

  beacon.py                    wysyla do APRS-IS
  beacon.py --dry-run          tylko pokazuje ramki
  beacon.py --dry-run --json   podglad dla editor.php
"""
import argparse
import datetime as dt
import json
import re
import socket
import sys
import time
import unicodedata
import urllib.parse
import urllib.request
from pathlib import Path

BASE = Path(__file__).resolve().parent
MAX_COMMENT = 43          # limit komentarza obiektu wg specyfikacji APRS
TOCALL = "APZ312"         # APZxxx = oprogramowanie eksperymentalne
PL = str.maketrans("ąćęłńóśźżĄĆĘŁŃÓŚŹŻ", "acelnoszzACELNOSZZ")
ALIASES = {
    "thanksgiving": "nth:11:4:4",   # 4. czwartek listopada
    "memorial": "nth:5:1:-1",       # ostatni poniedzialek maja
    "labor": "nth:9:1:1",           # 1. poniedzialek wrzesnia
    "pulaski": "nth:3:1:1",         # Casimir Pulaski Day (IL) - zostawione dla zgodnosci
}


def log(*a):
    print(*a, file=sys.stderr)


def load(path, fallback):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as e:
        log(f"Blad odczytu {path.name}: {e}")
        return fallback


def clean(text):
    """ASCII bez polskich znakow i bez znakow zarezerwowanych w APRS."""
    text = unicodedata.normalize("NFKD", text.translate(PL))
    text = text.encode("ascii", "ignore").decode()
    text = text.replace("|", "/").replace("~", "-")
    return " ".join(text.split())


# ---------- pogoda (OpenWeather) ----------

WX_CACHE = BASE / "weather_cache.json"
WX_REFRESH_MIN = 60      # domyslnie pogoda pobierana raz na godzine (config: weather.refresh_minutes)
WX_TEMPLATE = "WX {temp} {press} {wind} chm{clouds} {precip}"
COMPASS = ["N", "NNE", "NE", "ENE", "E", "ESE", "SE", "SSE",
           "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"]


def fetch_weather(wcfg, lat, lon):
    """Zwraca surowy JSON z OpenWeather; uzywa cache, zeby nie zuzywac limitu."""
    key = (round(lat, 3), round(lon, 3), wcfg.get("units", "imperial"))
    ttl = max(10, int(wcfg.get("refresh_minutes", WX_REFRESH_MIN))) * 60 - 30
    cache = None
    try:
        cache = json.loads(WX_CACHE.read_text())
        if cache.get("key") == list(key) and time.time() - cache["ts"] < ttl:
            return cache["data"]
    except Exception:
        cache = None
    q = urllib.parse.urlencode({"lat": lat, "lon": lon, "appid": wcfg["api_key"],
                                "units": key[2]})
    try:
        with urllib.request.urlopen(f"https://api.openweathermap.org/data/2.5/weather?{q}",
                                    timeout=10) as r:
            data = json.loads(r.read().decode())
    except Exception:
        # brak internetu / blad API: uzyj ostatnich danych, jesli maja mniej niz 3 h
        if cache and cache.get("key") == list(key) and time.time() - cache["ts"] < 3 * 3600:
            log("Pogoda: blad pobierania, uzywam ostatnich danych")
            return cache["data"]
        raise
    try:
        WX_CACHE.write_text(json.dumps({"key": list(key), "ts": time.time(), "data": data}))
    except Exception as e:
        log(f"Nie moge zapisac cache pogody: {e}")
    return data


def format_weather(data, units, template):
    imp = units == "imperial"
    m, w = data.get("main", {}), data.get("wind", {})
    speed, gust = w.get("speed"), w.get("gust")
    if speed is None:
        wind = ""
    elif round(speed) == 0:
        wind = "calm"
    else:
        d = COMPASS[int((w.get("deg", 0) % 360) / 22.5 + 0.5) % 16]
        wind = f"{d}{round(speed)}{'mph' if imp else 'm/s'}"
        if gust and round(gust) > round(speed):
            wind += f" G{round(gust)}"
    mm = sum((data.get(k) or {}).get("1h", 0) for k in ("rain", "snow"))
    if mm <= 0:
        precip = ""
    elif imp:
        precip = f"{mm / 25.4:.2f}in"
    else:
        precip = f"{mm:.1f}mm"
    parts = {
        "temp": f"{round(m['temp'])}{'F' if imp else 'C'}" if "temp" in m else "",
        "press": f"{m['pressure']}hPa" if "pressure" in m else "",
        "hum": f"{m['humidity']}%" if "humidity" in m else "",
        "wind": wind,
        "clouds": f"{data.get('clouds', {}).get('all', 0)}%",
        "precip": precip,
        "desc": (data.get("weather") or [{}])[0].get("description", ""),
    }
    out = template
    for k, v in parts.items():
        out = out.replace("{" + k + "}", v)
    return " ".join(out.split())


def weather_text(cfg):
    wcfg = cfg.get("weather") or {}
    if not wcfg.get("api_key"):
        return ""          # pogoda nie skonfigurowana - {wx} po prostu znika
    objs = cfg.get("objects") or [{}]
    lat = wcfg.get("lat", objs[0].get("lat"))
    lon = wcfg.get("lon", objs[0].get("lon"))
    try:
        data = fetch_weather(wcfg, lat, lon)
        return format_weather(data, wcfg.get("units", "imperial"),
                              wcfg.get("template", WX_TEMPLATE))
    except Exception as e:
        log(f"Pogoda: blad pobierania: {e}")
        return ""


# ---------- daty swiat ----------

def easter(y):
    a, b, c = y % 19, y // 100, y % 100
    d, e = b // 4, b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i, k = c // 4, c % 4
    l = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * l) // 451
    n = h + l - 7 * m + 114
    return dt.date(y, n // 31, n % 31 + 1)


def nth_weekday(y, month, weekday, n):
    """weekday: 1=pon..7=niedz; n: 1..5 albo -1 = ostatni."""
    if n > 0:
        d = dt.date(y, month, 1)
        d += dt.timedelta(days=(weekday - 1 - d.weekday()) % 7)
        return d + dt.timedelta(weeks=n - 1)
    nxt = dt.date(y + (month == 12), month % 12 + 1, 1)
    d = nxt - dt.timedelta(days=1)
    return d - dt.timedelta(days=(d.weekday() - (weekday - 1)) % 7)


def full_weekend_saturdays(y, month):
    """Soboty, po ktorych niedziela jest w tym samym miesiacu (pelne weekendy)."""
    d, out = dt.date(y, month, 1), []
    while d.month == month:
        if d.weekday() == 5 and (d + dt.timedelta(days=1)).month == month:
            out.append(d)
        d += dt.timedelta(days=1)
    return out


SPECIAL = {
    "fieldday": lambda y: full_weekend_saturdays(y, 6)[3],         # ARRL Field Day: 4. pelny weekend czerwca (sobota)
    "winterfieldday": lambda y: full_weekend_saturdays(y, 1)[-1],  # Winter Field Day: ostatni pelny weekend stycznia
    "easter": easter,
}
RULE_RE = re.compile(r"^(nth:(?:\d{1,2}|\*):[1-7]:(?:-1|[1-5])|\d{2}-\d{2}|[a-z]+)([+-]\d{1,2})?$")


def holiday_date(rule, year, month=None):
    """Data wydarzenia cyklicznego w danym roku.
    rule: MM-DD | easter | thanksgiving | memorial | labor | fieldday | winterfieldday
          | nth:MIESIAC:DZIEN_TYG:KTORY (MIESIAC '*' = kazdy miesiac -> month)
          + opcjonalne przesuniecie w dniach, np. easter+1, fieldday+1"""
    m = RULE_RE.match(rule.strip().lower())
    if not m:
        raise ValueError(rule)
    base, shift = m.group(1), int(m.group(2) or 0)
    base = ALIASES.get(base, base)
    if base in SPECIAL:
        d = SPECIAL[base](year)
    elif base.startswith("nth:"):
        _, mo, w, n = base.split(":")
        d = nth_weekday(year, (month or 1) if mo == "*" else int(mo), int(w), int(n))
    else:
        mo, day = base.split("-")
        d = dt.date(year, int(mo), int(day))
    return d + dt.timedelta(days=shift)


# ---------- wybor komentarza ----------

def pick_comment(c, today, slot):
    events = c.get("events", [])

    for ev in events:
        try:
            start = dt.date.fromisoformat(ev["start"])
            end = dt.date.fromisoformat(ev.get("end") or ev["start"])
        except Exception:
            continue
        if start <= today <= end:
            return ev.get("text") or ev.get("name", ""), "event"

    best = None
    for ev in events:
        try:
            days = (dt.date.fromisoformat(ev["start"]) - today).days
        except Exception:
            continue
        if 0 < days <= int(ev.get("announce_days", 0)) and (best is None or days < best[0]):
            best = (days, ev)
    if best:
        days, ev = best
        tpl = c.get("announce") or "{name} za {days} dni"
        return tpl.replace("{name}", ev.get("name", "")).replace("{days}", str(days)), "announce"

    for hol in c.get("holidays", []):
        try:
            if holiday_date(hol["date"], today.year, today.month) == today:
                return hol["text"], "holiday"
        except Exception as e:
            log(f"Zla data swieta '{hol.get('date')}': {e}")

    defaults = c.get("default") or []
    if defaults:
        return defaults[slot % len(defaults)], "default"
    return "", "none"


# ---------- ramka APRS ----------

def aprs_coord(value, is_lat):
    hemi = ("N" if value >= 0 else "S") if is_lat else ("E" if value >= 0 else "W")
    value = abs(value)
    deg = int(value)
    minutes = round((value - deg) * 60, 2)
    if minutes >= 60:
        deg, minutes = deg + 1, 0.0
    return f"{deg:02d}{minutes:05.2f}{hemi}" if is_lat else f"{deg:03d}{minutes:05.2f}{hemi}"


def freq_info(obj):
    """Standard repeater-object: Tnnn (ton), opcjonalnie offset, Rnnm (zasieg)."""
    parts = []
    tone = obj.get("tone")
    parts.append(f"T{int(float(tone)):03d}" if tone else "Toff")
    if obj.get("offset"):
        parts.append(str(obj["offset"]))
    if obj.get("range_miles"):
        parts.append(f"R{int(obj['range_miles']):02d}m")
    return " ".join(parts)


def build_packet(cfg, obj, comment, kill=False):
    name = obj["name"][:9].ljust(9)
    ts = dt.datetime.now(dt.timezone.utc).strftime("%d%H%Mz")
    sym = obj.get("symbol", "/r")
    return (f"{cfg['callsign']}>{TOCALL},TCPIP*:;{name}{'_' if kill else '*'}{ts}"
            f"{aprs_coord(obj['lat'], True)}{sym[0]}{aprs_coord(obj['lon'], False)}{sym[1]}{comment}")


def aprs_passcode(callsign):
    """Standardowy passcode APRS-IS liczony ze znaku (bez SSID)."""
    call = callsign.split("-")[0].upper()
    h = 0x73E2
    for i in range(0, len(call), 2):
        h ^= ord(call[i]) << 8
        if i + 1 < len(call):
            h ^= ord(call[i + 1])
    return h & 0x7FFF


def send(cfg, packets):
    host, port = cfg.get("server", "rotate.aprs2.net"), int(cfg.get("port", 14580))
    with socket.create_connection((host, port), timeout=20) as s:
        f = s.makefile("rw", encoding="ascii", newline="")
        f.readline()  # banner serwera
        passcode = str(cfg.get("passcode") or "").strip() or aprs_passcode(cfg["callsign"])
        f.write(f"user {cfg['callsign']} pass {passcode} vers APRS-Object-Beacon 1.0\r\n")
        f.flush()
        resp = f.readline().strip()
        if "unverified" in resp or "verified" not in resp:
            raise RuntimeError(f"APRS-IS odrzucil logowanie: {resp}")
        for p in packets:
            f.write(p + "\r\n")
        f.flush()
        time.sleep(1)


def is_unconfigured(cfg):
    call = str(cfg.get("callsign", "")).upper()
    if not call or call.startswith("N0CALL"):
        return True
    return any(o.get("enabled", True) and not o.get("lat") and not o.get("lon")
               for o in cfg.get("objects", []))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()

    cfg = load(BASE / "config.json", None)
    if not cfg:
        sys.exit(1)
    configured = not is_unconfigured(cfg)
    comments = load(BASE / "comments.json", {})

    text, source = pick_comment(comments, dt.date.today(), int(time.time() // 600))

    # {wx} = aktualna pogoda; pobierana tylko gdy gdzies jest uzyta
    objs = cfg.get("objects", [])
    if "{wx}" in text or any("{wx}" in (o.get("text") or "") for o in objs):
        wx = weather_text(cfg)
        text = text.replace("{wx}", wx)
        for o in objs:
            if o.get("text"):
                o["text"] = o["text"].replace("{wx}", wx)
        if source == "default" and not text.strip():
            # pogoda niedostepna - nie wysylaj pustego komentarza, wez nastepny z rotacji
            text, source = pick_comment(comments, dt.date.today(), int(time.time() // 600) + 1)
            text = text.replace("{wx}", "")
    text = clean(text)

    limit = int(cfg.get("max_comment", MAX_COMMENT))
    out, max_text = [], limit
    for obj in cfg.get("objects", []):
        if not obj.get("enabled", True):
            continue
        # czesc stala: (ton/offset/zasieg - tylko dla przemiennikow) + opis obiektu
        freq = freq_info(obj) if obj.get("freq", True) else ""
        head = " ".join(x for x in (freq, clean(obj.get("text", ""))) if x)
        if not obj.get("rotate", True):
            # obiekt bez zmiennego komentarza (np. punkt medyczny) - tylko staly opis
            out.append({"name": obj["name"], "source": "fixed", "text": "",
                        "truncated": len(head) > limit,
                        "packet": build_packet(cfg, obj, head[:limit])})
            continue
        sep = " / " if head else ""
        room = max(0, limit - len(head) - len(sep))
        max_text = min(max_text, room)
        comment = f"{head}{sep}{text[:room]}" if text[:room] else head
        out.append({"name": obj["name"], "source": source, "text": text[:room],
                    "truncated": len(text) > room, "packet": build_packet(cfg, obj, comment)})

    if args.json:
        print(json.dumps({"max_text": max_text, "packets": out, "configured": configured}, ensure_ascii=False))
        return
    # obiekty po zmianie nazwy: wyslij "kill", zeby stara nazwa zniknela z mapy
    active = {o["name"] for o in out}
    kills = [k for k in cfg.get("pending_kill", []) if k.get("name") not in active]
    kill_packets = [build_packet(cfg, k, "", kill=True) for k in kills]

    for o in out:
        print(f"[{o['source']}] {o['packet']}" + ("  (UCIETE)" if o["truncated"] else ""))
    for p in kill_packets:
        print(f"[usun] {p}")
    if not args.dry_run and not configured:
        log("Beacon nie jest skonfigurowany (znak N0CALL albo pozycja 0,0) - nic nie wysylam. Uzupelnij w edytorze.")
        return
    if not args.dry_run and (out or kill_packets):
        try:
            send(cfg, [o["packet"] for o in out] + kill_packets)
            print(f"Wyslano {len(out)} obiekt(y)." + (f" Usunieto z mapy: {len(kills)}." if kills else ""))
            try:
                (BASE / "last_sent.json").write_text(json.dumps(
                    {"ts": int(time.time()), "packets": [o["packet"] for o in out]}))
            except Exception:
                pass
        except Exception as e:
            log(f"Blad wysylania: {e}")
            try:
                (BASE / "last_error.json").write_text(json.dumps({"ts": int(time.time()), "error": str(e)}))
            except Exception:
                pass
            sys.exit(2)
        if "pending_kill" in cfg:
            clear_pending_kill()


def clear_pending_kill():
    """Usuwa liste pending_kill z config.json (czytamy plik od nowa, zeby nie nadpisac zmian z edytora)."""
    p = BASE / "config.json"
    try:
        c = json.loads(p.read_text(encoding="utf-8"))
        if c.pop("pending_kill", None) is not None:
            p.write_text(json.dumps(c, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    except Exception as e:
        log(f"Nie moge wyczyscic pending_kill: {e}")


if __name__ == "__main__":
    main()
