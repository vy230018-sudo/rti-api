<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Youtube\CreateLiveRequest;
use App\Http\Requests\Youtube\TransitionLiveRequest;
use App\Http\Resources\YoutubeLiveStreamResource;
use App\Models\YoutubeLiveStream;
use App\Services\YoutubeService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class YoutubeController extends Controller
{
    public function __construct(private readonly YoutubeService $youtube) {}

    public function auth(): JsonResponse { return response()->json(['authorization_url' => $this->youtube->authorizationUrl()]); }

    public function callback(Request $request): JsonResponse
    {
        if ($request->filled('error')) return response()->json(['message' => 'Google authorization was denied.', 'error' => $request->string('error')->toString()], 403);
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);
        if (! $this->youtube->validateState($request->string('state')->toString())) {
            return response()->json(['message' => 'Invalid or expired OAuth state.'], 422);
        }
        try { $this->youtube->handleCallback($request->string('code')->toString()); return response()->json(['message' => 'YouTube authorization completed successfully.']); }
        catch (RuntimeException|GoogleServiceException $e) { return $this->googleError($e); }
    }

    public function create(CreateLiveRequest $request): JsonResponse
    {
        try { return (new YoutubeLiveStreamResource($this->youtube->createLive($request->validated())))->response()->setStatusCode(201); }
        catch (RuntimeException|GoogleServiceException $e) { return $this->googleError($e); }
    }
public function start(TransitionLiveRequest $request): JsonResponse
{
    try {
        return $this->transition($request, 'live');

    } catch (GoogleServiceException $e) {

        return response()->json([
            'success' => false,
            'message' => 'YouTube API Error',
            'error' => json_decode($e->getMessage(), true),
        ], $e->getCode());

    } catch (RuntimeException $e) {

        return response()->json([
            'success' => false,
            'message' => 'YouTube Live request failed.',
            'error' => json_decode($e->getMessage(), true) ?: $e->getMessage(),
        ], $e->getCode() ?: 500);

    } catch (\Throwable $e) {

        \Log::error($e);

        return response()->json([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage(),
        ], 500);
    }
}    

public function end(TransitionLiveRequest $request): JsonResponse
{
    try {

        return $this->transition($request, 'complete');

    } catch (GoogleServiceException $e) {

        return response()->json([
            'success' => false,
            'message' => 'YouTube API Error',
            'error' => json_decode($e->getMessage(), true),
        ], $e->getCode());

    } catch (RuntimeException $e) {

        return response()->json([
            'success' => false,
            'message' => 'YouTube Live request failed.',
            'error' => json_decode($e->getMessage(), true) ?: $e->getMessage(),
        ], $e->getCode() ?: 500);

    } catch (\Throwable $e) {

        \Log::error($e);

        return response()->json([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage(),
        ], 500);
    }
}
    public function current(): JsonResponse
    {
        $live = YoutubeLiveStream::query()->where('status', 'live')->latest('started_at')->first();
        return response()->json(['data' => $live ? [
            'status' => $live->status,
            'watch_url' => $live->watch_url,
            'embed_url' => $live->embed_url,
        ] : null]);
    }

    public function liveList(): JsonResponse
    {
        $liveStreams = YoutubeLiveStream::query()
            ->where('status', 'liveStarting')
            ->latest('started_at')
            ->get(['title', 'watch_url']);

        return response()->json(['data' => $liveStreams]);
    }

    private function transition(TransitionLiveRequest $request, string $status): JsonResponse
    {
        try { $live = YoutubeLiveStream::query()->where('broadcast_id', $request->validated('broadcast_id'))->firstOrFail(); return (new YoutubeLiveStreamResource($this->youtube->transition($live, $status)))->response(); }
        catch (RuntimeException|GoogleServiceException $e) { return $this->googleError($e); }
    }
   private function googleError(\Throwable $e): JsonResponse
{
    report($e);

    $message = $e->getMessage();
    $decoded = json_decode($message, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return response()->json(
            $decoded,
            $e->getCode() ?: 422
        );
    }

    return response()->json([
        'message' => $message,
    ], $e->getCode() ?: 422);
}
}
