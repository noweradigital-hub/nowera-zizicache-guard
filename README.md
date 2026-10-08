# Nowera ZiziCache Guard

Must-use plugin + odporúčané nastavenia pre ZiziCache 1.0.x. Zastaví self-crawl, ktorý sa nedá vypnúť v nastaveniach (preload po purge, LiteSpeed status checker) a ktorý na zdieľanom hostingu vyčerpá PHP workery.

Pre ZiziCache 1.0.x na zdieľanom LiteSpeed hostingu. Overené na 1.0.11 na dvoch WooCommerce e-shopoch, 14. 9. 2026.

Postup má tri časti:

1. Nastavenia, ktoré sa dajú vypnúť v administrácii ZiziCache.
2. Serverový cron na hostingu.
3. Guard `nowera-zizicache-guard.php` pre problémy, ktoré sa v nastaveniach vypnúť nedajú.

---

## 1. Nastavenia v ZiziCache (ručne)

| Nastavenie | Kľúč v konfigurácii | Hodnota | Prečo |
|---|---|---|---|
| Page Cache → Cache Preloading | `cache_preload` | **vypnuté** | Crawl celého webu zahltí PHP pool (reálny výpadok e-shopu). Stránky sa nacachujú pri prvej návšteve. Keď je zapnuté, guard nechá bežať aj preload po purge. |
| Page Cache → LiteSpeed preload | `litespeed_preload_enabled` | **vypnuté** | Ide cez curl_multi mimo WordPress HTTP API, takže ho guard nezastaví. |
| Page Cache → Separate mobile cache | `cache_separate_mobile` | **vypnuté** pri `cache_mode = litespeed` | V LiteSpeed móde mobilnú variantu nevytvorí, iba zdvojnásobí preload. Kód číta neexistujúci kľúč `cache_mobile`. |
| Optimalizácia CSS/JS → Self-host third-party CSS/JS | `self_host_third_party_css_js` | **vypnuté** | Validátor zablokuje bežný JS. Na testovanom e-shope sa nikdy nič neuložilo a každý necachovaný render súbor sťahuje znova. |
| Page Cache → Cache mode | `cache_mode` | nechať | Zmena prestaví celú cache vrstvu. Netestované. |

Kontrola: po nasadení guardu `nwr_zizi_guard_audit()` vypíše zapnuté rizikové nastavenia v `warnings`. Rovnaký zoznam sa zobrazí adminom aj na stránke Pluginy a v administrácii ZiziCache.

---

## 2. Hosting: serverový cron

Preload ZiziCache aj jeho watchdog bežia cez WP-Cron. Ten sa pri návštevách spúšťa ako ďalší PHP request.

1. V paneli hostingu otvor **Cron** a zvoľ **URL mód**:
   - URL: `https://DOMENA/wp-cron.php?doing_wp_cron`
   - Interval: `*/5 * * * *`
   - Pozor na hodnotu `5 * * * *`, to je raz za hodinu.
   - CLI mód môže zlyhať, ak panel doplní neexistujúcu cestu k PHP.
2. Až keď cron naozaj beží, pridaj do `wp-config.php` riadok `define( 'DISABLE_WP_CRON', true );`.

---

## 3. Guard: `nowera-zizicache-guard.php`

### Čo rieši (v nastaveniach sa to vypnúť nedá)

- **Preload po purge.** Týka sa uloženia produktu, zmeny skladu, zmeny stavu objednávky, uloženia šablóny Bricks/Elementor a komentára.
  - Blokuje requesty s hlavičkou `X-Zizi-Preload` a async self-ping na `admin-ajax.php`.
  - Vyprázdni frontu `preloader-list.txt`, takže sa ukončí aj reťaz `zizi_cache_preload_next`.
  - Zruší pauzu 0,5 s medzi desktopom a mobilom v requeste, ktorý ukladá.
- **LiteSpeed status checker** (UA `ZiziCache/1.0 LiteSpeedCacheChecker`).
  - Spúšťa ho polling dashboardu, stránka Pages Not Cached a každé uloženie príspevku.
  - Guard mu odpovie lokálne, bez requestu na web.

### Čo nerieši

- LiteSpeed preload a self-hosting. Tie vypni v nastaveniach (bod 1).

### Inštalácia

Nahraj súbor do `wp-content/mu-plugins/`. Nič netreba zapínať, funguje hneď.

### Overenie

1. `nwr_zizi_guard_audit()`, napríklad cez Novamira execute-php, má vrátiť:
   - `guard.page_preload_blocked = true`
   - `guard.tracker_blocked = true`
   - prázdne `warnings`
2. Ulož produkt a skontroluj:
   - `zizi-log.log` obsahuje „Error loading … blocked by Nowera ZiziCache Guard“.
   - V access logu nie sú requesty z IP servera s UA Mac/iPhone ani `LiteSpeedCacheChecker`.

### Režimy (voliteľné, `wp-config.php`)

| Konštanta | Hodnota | Význam |
|---|---|---|
| `NWR_ZIZI_PRELOAD` | `'auto'` (predvolené) | Preload stránok blokuje, kým je Cache Preloading vypnutý. Obnovu expirovanej súborovej cache nechá bežať. |
| | `'block'` | Blokuje všetky preload requesty. |
| | `'allow'` | Preload nechá bežať. Hodí sa na jednorazový ručný preload. |
| `NWR_ZIZI_TRACKER` | `'block'` (predvolené) / `'allow'` | Status checker |
| `NWR_ZIZI_GUARD` | `false` | Guard úplne vypnutý |

Filtre: `nwr_zizi_guard_block_preload( $block, $stale_refresh )`, `nwr_zizi_guard_block_tracker( $block )`.

### Vedľajšie efekty

- **Ručný preload:** tlačidlo Preload a príkaz `wp zizi-cache preload-cache` nič nespravia, kým je Cache Preloading vypnutý.
- **Štatistiky v LiteSpeed móde:** dashboard a Pages Not Cached ukazujú stav „unknown“ a 0 nacachovaných stránok. Skutočný stav ukáže hlavička `x-litespeed-cache` na stránke.
- **Log:** ZiziCache zapisuje preload správy vždy, takže v logu ostanú riadky „… blocked by Nowera ZiziCache Guard“.

### Odstránenie

Zmaž súbor. Urob to až vtedy, keď ZiziCache opraví chyby 1 a 2 z bodu 4.

---

## 4. Chyby v ZiziCache pre developera

1. **Preload po purge ignoruje `cache_preload`.**
   - Volajú ho `WooCommerce.php:424`, `AutomaticallyClear.php:80` a `PageBuilders.php:717`.
   - Bez async beží priamo v requeste, ktorý ukladá: 5 URL × desktop + mobil, timeout 30 s.
2. **`CacheUrlsTracker` v LiteSpeed móde posiela blokujúci GET na každú URL.**
   - Spúšťa ho polling dashboardu: `quick_refresh(1000)`. Časovač 60 s sa zapíše až po dobehnutí, takže sa behy prekrývajú.
   - Spúšťa ho aj sync a `save_post`.
3. **Self-host validátor blokuje bežný JS** (`SysTool.php:990–1045`). Neúspech si nepamätá medzi requestami, takže každý render sťahuje súbor znova.
4. **`LiteSpeedSupport.php:990/1089` číta neexistujúci kľúč `cache_mobile`.**
5. **`CacheSys::shouldLog` zapisuje priveľa.**
   - Správy bez úrovne berie ako ERROR.
   - Správy `[Preload]` zapisuje vždy, aj pri `log_level = none`.
6. **Docs (0.8.5) nesedia s 1.0.x.**
   - `zizi_cache_disabled_integrations` čaká FQCN.
   - `zizi_cache_preload_urls` nefiltruje preload po purge.

## Licencia
GPL-2.0-or-later, plné znenie v [LICENSE](LICENSE).
