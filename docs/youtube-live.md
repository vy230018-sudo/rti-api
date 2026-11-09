# YouTube Live API

Set the four `YOUTUBE_*` values from `.env.example`, register `YOUTUBE_REDIRECT_URI` in Google Cloud, then run `composer install` and `php artisan migrate`.

1. `GET /api/youtube/auth` returns `authorization_url`; open it in a browser and grant access.
2. Google calls `/api/youtube/callback`; its refresh token is saved encrypted in MySQL. `YOUTUBE_REFRESH_TOKEN` is only a fallback for an initial deployment.
3. Before calling `POST /api/youtube/live/start`, send video to the returned RTMP ingestion address and stream key. The endpoint verifies that YouTube reports the stream as active, preventing an invalid lifecycle transition.

```bash
curl -X POST "$BASE_URL/api/youtube/live/create" -H "Content-Type: application/json" -d '{"title":"RTI Live","description":"News Live","privacyStatus":"public","scheduledStartTime":"2026-07-28T12:00:00Z"}'
curl -X POST "$BASE_URL/api/youtube/live/start" -H "Content-Type: application/json" -d '{"broadcast_id":"BROADCAST_ID"}'
curl -X POST "$BASE_URL/api/youtube/live/end" -H "Content-Type: application/json" -d '{"broadcast_id":"BROADCAST_ID"}'
curl "$BASE_URL/api/youtube/live/current"
```

Import `postman/Youtube-Live.postman_collection.json` into Postman. The create response includes the broadcast/stream IDs, RTMP stream key and ingestion address, plus watch and embed URLs.
