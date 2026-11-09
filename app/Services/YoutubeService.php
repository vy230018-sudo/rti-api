<?php

namespace App\Services;

use App\Models\YoutubeLiveStream;
use App\Models\YoutubeOauthToken;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\YouTube;
use Google\Service\YouTube\CdnSettings;
use Google\Service\YouTube\LiveBroadcast;
use Google\Service\YouTube\LiveBroadcastContentDetails;
use Google\Service\YouTube\LiveBroadcastSnippet;
use Google\Service\YouTube\LiveBroadcastStatus;
use Google\Service\YouTube\LiveStream;
use Google\Service\YouTube\LiveStreamContentDetails;
use Google\Service\YouTube\LiveStreamSnippet;
use Google\Service\YouTube\LiveStreamStatus;
use Google\Service\YouTube\MonitorStreamInfo;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class YoutubeService
{
    private const SCOPES = [YouTube::YOUTUBE_FORCE_SSL];

    public function authorizationUrl(): string
    {
        $client = $this->client();
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $state = Str::random(64);
        Cache::put('youtube_oauth_state_'.$state, true, now()->addMinutes(10));
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function validateState(string $state): bool
    {
        return (bool) Cache::pull('youtube_oauth_state_'.$state);
    }

    public function handleCallback(string $code): void
    {
        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            throw new RuntimeException($token['error_description'] ?? $token['error']);
        }
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Re-authorize with consent prompt.');
        }
        // Stored encrypted through the Eloquent encrypted cast; avoids plaintext .env storage.
        YoutubeOauthToken::query()->updateOrCreate(['id' => 1], ['refresh_token' => $token['refresh_token']]);
        session()->put('youtube_refresh_token', $token['refresh_token']);
    }

    public function createLive(array $data): YoutubeLiveStream
    {
        $youtube = $this->youtube();
        $scheduled = Carbon::parse($data['scheduledStartTime'])->utc();

        $broadcast = new LiveBroadcast;
        $snippet = new LiveBroadcastSnippet;
        $snippet->setTitle($data['title']);
        $snippet->setDescription($data['description'] ?? null);
        $snippet->setScheduledStartTime($scheduled->toRfc3339String());
        $broadcast->setSnippet($snippet);
        $status = new LiveBroadcastStatus;
        $status->setPrivacyStatus($data['privacyStatus']);
        $broadcast->setStatus($status);
        $contentDetails = new LiveBroadcastContentDetails;
        $contentDetails->setEnableAutoStart(false);
        $contentDetails->setEnableAutoStop(false);
        // A monitor stream requires ready -> testing -> live. This API exposes a
        // single "start" operation, so disable it to allow ready -> live directly.
        $monitorStream = new MonitorStreamInfo;
        $monitorStream->setEnableMonitorStream(false);
        $contentDetails->setMonitorStream($monitorStream);
        $broadcast->setContentDetails($contentDetails);
        $broadcast = $youtube->liveBroadcasts->insert('snippet,status,contentDetails', $broadcast);

        $stream = new LiveStream;
        $streamSnippet = new LiveStreamSnippet;
        $streamSnippet->setTitle($data['title'].' Stream');
        $stream->setSnippet($streamSnippet);
        $cdn = new CdnSettings;
        $cdn->setIngestionType('rtmp');
        $cdn->setResolution('variable');
        $cdn->setFrameRate('variable');
        $stream->setCdn($cdn);
        $streamStatus = new LiveStreamStatus;
        $streamStatus->setStreamStatus('created');
        $stream->setStatus($streamStatus);
        $stream->setContentDetails(new LiveStreamContentDetails);
        $stream = $youtube->liveStreams->insert('snippet,cdn,status,contentDetails', $stream);

        $youtube->liveBroadcasts->bind($broadcast->getId(), 'id,contentDetails,status', ['streamId' => $stream->getId()]);
        $ingestion = $stream->getCdn()->getIngestionInfo();

        return YoutubeLiveStream::query()->create([
            'title' => $data['title'], 'description' => $data['description'] ?? null,
            'broadcast_id' => $broadcast->getId(), 'stream_id' => $stream->getId(),
            'stream_key' => $ingestion->getStreamName(), 'ingestion_address' => $ingestion->getIngestionAddress(),
            'watch_url' => 'https://www.youtube.com/watch?v='.$broadcast->getId(),
            'embed_url' => 'https://www.youtube.com/embed/'.$broadcast->getId(),
            'privacy_status' => $data['privacyStatus'], 'status' => $broadcast->getStatus()->getLifeCycleStatus() ?? 'ready',
            'scheduled_start_time' => $scheduled,
        ]);
    }

    public function transition(YoutubeLiveStream $live, string $status): YoutubeLiveStream
    {
        try {
            $youtube = $this->youtube();
            $broadcast = $this->broadcast($youtube, $live->broadcast_id);
            $currentStatus = $broadcast->getStatus()?->getLifeCycleStatus();

            if ($currentStatus === $status) {
                return $this->syncStatus($live, $currentStatus);
            }

            if ($status === 'live') {
                $this->ensureStreamIsActive($youtube, $live);

                // Older broadcasts may have been created with a monitor stream
                // enabled. YouTube requires them to pass through testing first.
                if ($currentStatus === 'ready' && $broadcast->getContentDetails()?->getMonitorStream()?->getEnableMonitorStream()) {
                    $broadcast = $youtube->liveBroadcasts->transition('testing', $live->broadcast_id, 'id,status,contentDetails');
                    $currentStatus = $broadcast->getStatus()?->getLifeCycleStatus();
                }

                if ($currentStatus === 'testStarting') {
                    throw new RuntimeException('The YouTube broadcast is still entering testing mode. Wait a moment and start it again.', 409);
                }
            }

            $broadcast = $youtube->liveBroadcasts->transition(
                $status,
                $live->broadcast_id,
                'id,status'
            );

            return $this->syncStatus($live, $broadcast->getStatus()->getLifeCycleStatus() ?? $status);

        } catch (GoogleServiceException $e) {

            \Log::error($e->getMessage());

            $error = json_decode($e->getMessage(), true);

            if (! $error) {
                $error = [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ];
            }

            throw new RuntimeException(json_encode($error), $e->getCode());
        } catch (\Throwable $e) {

            \Log::error($e->getMessage());

            throw new RuntimeException($e->getMessage(), $e->getCode());
        }
    }

    private function broadcast(YouTube $youtube, string $broadcastId): LiveBroadcast
    {
        $broadcast = $youtube->liveBroadcasts
            ->listLiveBroadcasts('id,status,contentDetails', ['id' => $broadcastId])
            ->getItems()[0] ?? null;

        if (! $broadcast) {
            throw new RuntimeException('The YouTube broadcast could not be found.', 404);
        }

        return $broadcast;
    }

    private function ensureStreamIsActive(YouTube $youtube, YoutubeLiveStream $live): void
    {
        $stream = $youtube->liveStreams
            ->listLiveStreams('status', ['id' => $live->stream_id])
            ->getItems()[0] ?? null;

        if ($stream?->getStatus()?->getStreamStatus() !== 'active') {
            throw new RuntimeException('The YouTube stream is not active yet. Start sending RTMP video, then try again.', 409);
        }
    }

    private function syncStatus(YoutubeLiveStream $live, string $status): YoutubeLiveStream
    {
        $timestamps = match ($status) {
            'live' => ['started_at' => $live->started_at ?? now(), 'ended_at' => null],
            'complete' => ['ended_at' => $live->ended_at ?? now()],
            default => [],
        };

        $live->update(['status' => $status, ...$timestamps]);

        return $live->fresh();
    }

    private function youtube(): YouTube
    {
        $client = $this->client();
        $refreshToken = session('youtube_refresh_token') ?: YoutubeOauthToken::query()->value('refresh_token') ?: config('services.youtube.refresh_token');
        if (! $refreshToken) {
            throw new RuntimeException('YouTube is not authorized. Open /api/youtube/auth first.');
        }
        $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if ($client->isAccessTokenExpired()) {
            throw new RuntimeException('Unable to refresh the YouTube access token.');
        }

        return new YouTube($client);
    }

    private function client(): GoogleClient
    {
        if (! config('services.youtube.client_id') ||
            ! config('services.youtube.client_secret') ||
            ! config('services.youtube.redirect_uri')) {
            throw new RuntimeException('YouTube OAuth configuration is incomplete.');
        }

        $client = new GoogleClient;

        if (app()->environment('local')) {
            $client->setHttpClient(new GuzzleClient([
                'verify' => false,
            ]));
        }

        $client->setClientId(config('services.youtube.client_id'));
        $client->setClientSecret(config('services.youtube.client_secret'));
        $client->setRedirectUri(config('services.youtube.redirect_uri'));
        $client->setScopes(self::SCOPES);

        return $client;
    }
}
