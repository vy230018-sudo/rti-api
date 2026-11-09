<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class VideoAudioMixer
{
    /**
     * Returns the processed video and (when supplied) the stored voice path.
     * FFmpeg must be installed/configured as FFMPEG_BINARY.
     */
    public function mix(string $videoPath, ?string $musicUrl, ?UploadedFile $voice, float $volume, bool $muteOriginal): array
    {
        $source = public_path('storage/'.ltrim($videoPath, '/'));
        $dir = public_path('storage/news/videos'); if (! is_dir($dir)) mkdir($dir, 0755, true);
        $output = $dir.'/processed_'.uniqid().'.mp4';
        $inputs = [$source]; $audioInputs = []; $voiceRelativePath = null;
        if ($musicUrl) $audioInputs[] = ['path' => $musicUrl, 'kind' => 'music'];
        if ($voice) {
            $voicePath = $dir.'/voice_'.uniqid().'.'.($voice->extension() ?: 'm4a');
            $voice->move($dir, basename($voicePath));
            $audioInputs[] = ['path' => $voicePath, 'kind' => 'voice'];
            $voiceRelativePath = 'news/videos/'.basename($voicePath);
        }

        // No additional audio: only process when the caller explicitly wants
        // the original soundtrack removed.
        if ($audioInputs === [] && ! $muteOriginal) return ['media' => $videoPath, 'voice' => null];

        // Music loops when shorter than the video; -shortest trims it at the
        // video end. Voice is deliberately not looped.
        foreach ($audioInputs as $input) {
            if ($input['kind'] === 'music') $inputs[] = '-stream_loop';
            if ($input['kind'] === 'music') $inputs[] = '-1';
            $inputs[] = '-i'; $inputs[] = $input['path'];
        }
        $filters = []; $labels = [];
        if (! $muteOriginal && $this->hasAudio($source)) $labels[] = '[0:a]';
        foreach ($audioInputs as $index => $input) {
            $label = '['.($index + 1).':a]';
            if ($input['kind'] === 'music') {
                // Voice + music: background music must not overpower speech.
                $musicVolume = $voice ? min($volume, 0.35) : $volume;
                $filters[] = $label.'volume='.$musicVolume.'[music]';
                $label = '[music]';
            }
            $labels[] = $label;
        }
        $filters[] = count($labels) > 1
            ? implode('', $labels).'amix=inputs='.count($labels).':duration=first:dropout_transition=2[aout]'
            : (($labels[0] ?? '').'anull[aout]');
        $command = array_merge([config('services.ffmpeg.binary', 'ffmpeg'), '-y'], $inputs, ['-map', '0:v:0']);
        if ($audioInputs !== []) { $command[] = '-filter_complex'; $command[] = implode(';', $filters); $command[] = '-map'; $command[] = '[aout]'; }
        elseif ($muteOriginal) { $command[] = '-an'; }
        $command = array_merge($command, ['-c:v', 'libx264', '-c:a', 'aac', '-movflags', '+faststart', '-shortest', $output]);
        $result = Process::timeout(300)->run($command);
        if (! $result->successful() || ! is_file($output)) throw ValidationException::withMessages(['video' => ['Video audio processing failed. Ensure FFmpeg is installed and the supplied audio is valid.']]);
        return ['media' => 'news/videos/'.basename($output), 'voice' => $voiceRelativePath];
    }

    private function hasAudio(string $video): bool
    {
        $probe = Process::timeout(20)->run([str_replace('ffmpeg', 'ffprobe', config('services.ffmpeg.binary', 'ffmpeg')), '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $video]);
        return $probe->successful() && trim($probe->output()) !== '';
    }
}
