# members_api

Global API for "members" system.

# Documentation

- [Version 3](/index/versions/3/README.md) (LATEST)
- [OpenAPI specification](/index/versions/3/openapi.yaml) for version 3

# URL Structure

The URL structure for accessing the API follows the pattern:

```
https://members.eob.cz/api/<version>/<club>/<endpoint>
```

**Example:** https://members.eob.cz/api/3/spe/

---

**`GET /api/3/clubs`**

**Output:**

```json
[
  {
    "clubname": "spe",
    "is_release": true,
    "fullname": "KOB Sokol Pezinok",
    "shortcut": "SPE",
    "baseadr": "https://prihlasky.sokolpezinok.sk/",
    "mainwww": "https://www.sokolpezinok.sk/",
    "emailadr": "admin@sokolpezinok.sk"
  }
]
```

# Crash Reporter

Any error is caught by [ApiCrashReporter](core/exceptions.php). By default, ApiCrashReporter renders HTML.

**Example:** https://members.eob.cz/api/3/spe/this-triggers-not-found-error

**JSON Output:**

```json
{
  "code": 404,
  "message": "Route not found: \"\/api\/3\/spe\/this-triggers-not-found-error\/\""
}
```

# Log Viewer

**`/api/logging`**

# Members dynamic clubs

Implemented, haven't been tested properly.

# License

This project is licensed under `Boost Software License 1.0`.
