# FullImagesScrapper 1.8.2

Release date: 2026-09-07

- Resolves Pornolab TurboImageHost `/t/<id>_...` previews through their viewer
  page to the full `/sp/<hash>/...` original image.
- Uses the local Solverr endpoint `http://127.0.0.1:8191/v1` for the
  TurboImageHost viewer, which rejects direct cURL requests.
- Adds NNMClub and resolves ImageBan `/thumbs/YYYY.MM.DD/...` previews through
  `imageban.ru/show/...` to their `/out/YYYY/MM/DD/...` originals.
- Retains Tapochek support through the shared TorrentPier first-post parser.
- Bumps the gallery cache namespace so cached previews are refreshed.

Release artifact: `releases/FullImagesScrapper-1.8.2.zip`.
