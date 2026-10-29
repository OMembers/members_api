# `Race` — API endpoint pre preteky

Súbor: `endpoints/race.php`

Trieda definuje REST endpointy pre preteky — zoznam, detail, prihlasovanie/odhlasovanie účastníkov, notifikácie a platby súvisiace s pretekmi.

---

## Registrácia routes (`init()`)

| Metóda | Cesta                               | Handler              | Auth |
| ------ | ----------------------------------- | -------------------- | ---- |
| GET    | `/races`                            | `races`              | nie  |
| ANY    | `/race/`                            | `warn_race_required` | nie  |
| GET    | `/race/{race_id}`                   | `detail`             | áno  |
| GET    | `/race/{race_id}/redirect`          | `redirect`           | nie  |
| GET    | `/race/{race_id}/relations`         | `relations`          | áno  |
| POST   | `/race/{race_id}/signin/{user_id}`  | `signin`             | áno  |
| POST   | `/race/{race_id}/signout/{user_id}` | `signout`            | áno  |
| POST   | `/race/{race_id}/notify`            | `notify`             | áno  |
| GET    | `/race/{race_id}/payments`          | `payments`           | áno  |

`{race_id}` a `{user_id}` sú obmedzené na číselné hodnoty (`[0-9]+`).

---

## Metódy

### `warn_race_required()`

Zachytáva požiadavky na `/race/` bez `race_id`. Vždy vyhodí `ApiException` (404) s hláškou, že `race_id` je povinné.

### `redirect($race_id)`

Presmeruje (HTTP 302) na stránku: `Config::$g_baseadr . "race_info_show.php?id_zav=" . $race_id`.

### `races()`

Vráti zoznam pretekov od zadaného dátumu.

- **Input:** `from_date` (voliteľný, ISO dátum). Ak chýba, použije sa aktuálny dátum (`Utils::getCurrentDate()`).
- **Logika:** vyberie `id` pretekov, kde `datum >= from_date` **alebo** `datum2 >= from_date` (t.j. preteky, ktoré ešte prebiehajú alebo len začnú), zoradené podľa `datum`.
- Pre každé preteky sa detail dotiahne cez `RaceUtils::get_race_info($id)`.

### `detail($race_id)`

Vráti detail pretekov + zoznam prihlásených účastníkov.

- Základ: `RaceUtils::get_race_info($race_id)`.
- Rozšírené o kľúč `everyone` — pole všetkých prihlásených na dané preteky (tabuľka `TBL_ZAVXUS`), s kategóriou, poznámkami, dopravou, ubytovaním a SI-čipom (buď vlastný čip účastníka, alebo prepísaný pre konkrétne preteky).

### `relations($race_id)`

Vráti vzťah prihláseného používateľa (a jeho "oveciek", pozri `chief_pay`) k daným pretekom — teda kto z jeho skupiny je/nie je na preteky prihlásený.

- **Logika:** `LEFT JOIN` medzi všetkými relevantnými používateľmi (`user.id = Session::$user_id OR user.chief_pay = Session::$user_id`) a ich prípadným záznamom v `TBL_ZAVXUS` pre dané preteky. Príznak `is_signed_in` hovorí, či záznam existuje.

### `signin($race_id, $user_id)`

Prihlási (alebo aktualizuje existujúcu prihlášku) používateľa na preteky.

- **Input:** `category` (povinné, neprázdne), `note`, `note_internal`, `transport` (bool), `accommodation` (bool), a `transport_shared` (int) — ale iba ak majú preteky nastavenú "zdieľanú dopravu" (`transport == 3` v `TBL_RACE`).
- **Kontroly (v poradí):**
  1. `SessionUtils::require_managing_this_user($user_id)` — prihlásený musí mať právo spravovať cieľového `$user_id` (seba alebo svoju "ovečku").
  2. `SessionUtils::require_entry_is_not_locked($user_id)` — prihláška používateľa nesmie byť uzamknutá.
  3. `RaceUtils::get_time_to_registration($race_id)` musí byť rôzne od `0`, inak `404` (uzávierka prihlášok vypršala).
  4. `RaceUtils::require_race_is_not_cancelled($race_id)` — preteky nesmú byť zrušené.
- **Logika dopravy:** ak sú preteky so zdieľanou dopravou a používateľ ju nechce (`transport_shared` je prázdne/0), transport sa vynuluje (`transport = 0`).
- **Upsert:** ak už existuje záznam v `TBL_ZAVXUS` pre daného používateľa a preteky, urobí sa `UPDATE`, inak `INSERT`.

### `signout($race_id, $user_id)`

Odhlási používateľa z pretekov (zmaže záznam z `TBL_ZAVXUS`).

- Rovnaké kontroly ako pri `signin` (okrem uzávierky prihlášok): `require_managing_this_user`, `require_entry_is_not_locked`, `require_race_is_not_cancelled`.

### `notify($race_id)`

Odošle push notifikáciu súvisiacu s pretekmi.

- **Prístup:** vyžaduje `Session::$policy_mng_big` (veľký tréner).
- **Input:** `title`, `body`, `image` (voliteľná URL adresa).
- Zostaví `NotifyContent`, priradí `topic` = `Session::$clubname`, event typu `EVENT_RACE` s `$race_id`, a odošle cez `Notifications::send()`.

### `payments($race_id)`

Vráti zoznam platieb súvisiacich s danými pretekmi.

- **Prístup:** vyžaduje `MASK_MNG_BIG` **aj** `MASK_FIN` (rovnako ako `Finances::payment_delete`/`payments_import`).
- Vracia rovnaké stĺpce ako `Finances::detail`, okrem info o pretekoch (keďže `race_id` je už zadané), filtrované podľa `fin.id_zavod = $race_id`.

---

## Formát requestov a odpovedí (JSON)

### Poznámka k niektorým kľúčom

Nikto už netuší, čo znamenajú nasledovné kľúče. Ich prítomnosť je tu čisto z historických dôvodov.

| Kľúč     | Hodnota v 99% |
| -------- | ------------- |
| `rank21` | `"0"`         |
| `sport`  | `null`        |

### `GET /races`

**Input (query):**

| Kľúč        | Povinný | Poznámka                          |
| ----------- | ------- | --------------------------------- |
| `from_date` | nie     | ISO dátum, default = dnešný dátum |

**Output:** pole objektov

```json
[
  {
    "race_id": 7,
    "dates": ["2026-04-12"],
    "entries": ["2026-03-01", "2026-03-20"],
    "name": "Jarný beh 2026",
    "cancelled": false,
    "club": "TJ Sokol",
    "link": "http://example.com/preteky",
    "place": "Bratislava",
    "type": "Bežecký pretek",
    "sport": null,
    "rankings": ["Slovenský pohár"],
    "rank21": "0",
    "note": "Prezentácia od 8:00",
    "transport": 3,
    "accommodation": 1,
    "categories": ["M21", "W21"]
  },
  {
    "race_id": 9,
    "dates": ["2026-05-03", "2026-05-04"],
    "entries": ["2026-04-01"],
    "name": "Letný maratón",
    "cancelled": false,
    "club": "AK Rýchlik",
    "link": null,
    "place": "Košice",
    "type": "Cestný beh",
    "sport": null,
    "rankings": [],
    "rank21": "0",
    "note": null,
    "transport": 0,
    "accommodation": 0,
    "categories": []
  }
]
```

---

### `GET /race/{race_id}`

**Output:**

```json
{
  "race_id": 7,
  "dates": ["2026-04-12"],
  "entries": ["2026-03-01"],
  "name": "Jarný beh 2026",
  "cancelled": false,
  "club": "TJ Sokol",
  "link": "http://example.com/preteky",
  "place": "Bratislava",
  "type": "Bežecký pretek",
  "sport": null,
  "rankings": ["Slovenský pohár"],
  "rank21": "0",
  "note": "Prezentácia od 8:00",
  "transport": 3,
  "accommodation": 1,
  "categories": ["M21", "W21"],
  "everyone": [
    {
      "user_id": 12,
      "name": "Ján",
      "surname": "Novák",
      "reg": "SVK12345",
      "category": "M21",
      "note": "Alergia na orechy",
      "note_internal": "VIP",
      "transport": 1,
      "transport_shared": null,
      "accommodation": 2,
      "si_chip": 123456
    }
  ]
}
```

---

### `GET /race/{race_id}/redirect`

Bez JSON tela — HTTP `302` presmerovanie.

---

### `GET /race/{race_id}/relations`

**Output:**

```json
[
  {
    "user_id": 12,
    "name": "Ján",
    "surname": "Novák",
    "sort_name": "Novák Ján",
    "reg": "SVK12345",
    "race_id": 7,
    "category": "M21",
    "note": "Alergia na orechy",
    "note_internal": "VIP",
    "transport": 1,
    "transport_shared": null,
    "accommodation": 2,
    "is_signed_in": true,
    "si_chip": 123456
  }
]
```

---

### `POST /race/{race_id}/signin/{user_id}`

**Input:**

```json
{
  "category": "M21",
  "note": "Alergia na orechy",
  "note_internal": "",
  "transport": true,
  "accommodation": false,
  "transport_shared": 2
}
```

| Kľúč               | Povinný        | Poznámka                                                         |
| ------------------ | -------------- | ---------------------------------------------------------------- |
| `category`         | áno, neprázdne | kategória                                                        |
| `note`             | áno\*          | verejná poznámka                                                 |
| `note_internal`    | áno\*          | interná poznámka                                                 |
| `transport`        | áno\*          | bool                                                             |
| `accommodation`    | áno\*          | bool                                                             |
| `transport_shared` | podmienene     | len ak majú preteky `transport == 3`; inak sa neposiela/ignoruje |

\* `Input` je vytvorený s `required: true`, takže tieto kľúče sa očakávajú vždy (aj keď prázdne/`null`, podľa filtra).

**Output:** bez tela.

---

### `POST /race/{race_id}/signout/{user_id}`

**Input:** bez tela.
**Output:** bez tela.

---

### `POST /race/{race_id}/notify`

**Input:**

```json
{
  "title": "Zmena štartu",
  "body": "Štart pretekov sa posúva o 30 minút.",
  "image": "https://example.com/banner.jpg"
}
```

| Kľúč    | Povinný | Poznámka           |
| ------- | ------- | ------------------ |
| `title` | áno     | nadpis notifikácie |
| `body`  | áno     | text notifikácie   |
| `image` | nie     | URL adresa obrázka |

**Output:** výsledok `Notifications::send()` — vráti informácie z Google.

---

### `GET /race/{race_id}/payments`

**Output:**

```json
[
  {
    "fin_id": 101,
    "editor_user_id": 5,
    "editor_sort_name": "Kováč Peter",
    "user_id": 12,
    "user_sort_name": "Novák Ján",
    "chief_pay": null,
    "note": "Štartovné",
    "amount": -20,
    "date": "2026-04-01",
    "storno": null,
    "storno_user_id": null,
    "storno_sort_name": null,
    "storno_date": null,
    "storno_note": null,
    "claim": 0
  }
]
```
