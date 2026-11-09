<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * News media (image / video / pdf / url) ko ads, profile aur ecom ki tarah
 * seedha public/storage/ ke andar save karta hai (symlink par depend nahi).
 *
 *   news/images/  -> public/storage/news/images/
 *   news/videos/  -> public/storage/news/videos/
 *   news/pdfs/    -> public/storage/news/pdfs/
 *
 * DB mein sirf relative path jaata hai (e.g. "news/images/news_xxx.jpg").
 * Fetch: asset('storage/' . $path)  (media_type 4 = external URL, as-is).
 */
trait HandlesNewsMedia
{
    /**
     * Naya media save karo.
     * - File aayi to save karke relative path return karta hai
     * - media_type 4 par URL return karta hai
     * - Kuch naya nahi aaya to null (matlab purana media rakho)
     */
    protected function saveNewsMedia(Request $request): ?string
    {
        $type = (int) $request->input('media_type');

        if (in_array($type, [1, 2, 3], true)) {

            if (! $request->hasFile('media')) {
                return null;
            }

            [$rule, $folder] = match ($type) {
                1 => ['mimes:jpg,jpeg,png|max:2048', 'news/images'],
                2 => ['mimes:mp4,mov,avi|max:10240', 'news/videos'],
                3 => ['mimes:pdf|max:5120', 'news/pdfs'],
            };

            $request->validate(['media' => $rule]);

            $directory = public_path('storage/' . $folder);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $file = $request->file('media');

            // extension() file ke asli content se nikalta hai (client ke diye naam se nahi),
            // taaki koi "shell.php" naam se upload karke public folder mein script na chala sake.
            $filename = uniqid('news_', true)
                . '_' . time()
                . '.' . ($file->extension() ?: 'bin');

            $file->move($directory, $filename);

            return $folder . '/' . $filename;
        }

        if ($type === 4) {
            $request->validate(['media' => 'required|url']);

            return (string) $request->input('media');
        }

        return null;
    }

    /**
     * Media type 1 (images): images[] ki SAARI files save karo.
     * Purana single `media` file field bhi chalta hai (backward compatible).
     *
     * Return: relative paths ka array (e.g. ["news/images/a.jpg", "news/images/b.jpg"]).
     * Koi file nahi aayi to [] (matlab purani images rakho).
     */
    protected function saveNewsImages(Request $request): array
    {
        $files = [];

        foreach (['images', 'media'] as $field) {
            $uploaded = $request->file($field);

            if ($uploaded === null) {
                continue;
            }

            foreach (is_array($uploaded) ? $uploaded : [$uploaded] as $file) {
                if ($file && $file->isValid()) {
                    $files[] = $file;
                }
            }
        }

        if ($files === []) {
            return [];
        }

        $request->validate([
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'media' => ['nullable'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $directory = public_path('storage/news/images');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $paths = [];

        foreach ($files as $file) {
            $filename = uniqid('news_', true) . '_' . time() . '.' . ($file->extension() ?: 'bin');
            $file->move($directory, $filename);
            $paths[] = 'news/images/' . $filename;
        }

        return $paths;
    }

    /** Images ke relative paths ko full URL mein badlo (http(s) URL ko as-is chhodta hai). */
    protected function newsImageUrls(array $paths): array
    {
        return array_values(array_map(
            fn ($path) => filter_var($path, FILTER_VALIDATE_URL) ? $path : asset('storage/' . ltrim((string) $path, '/')),
            array_filter($paths)
        ));
    }

    /** News ki saari images ke relative paths: nayi `images` column, nahi to purani single `media`. */
    protected function newsImagePaths($news): array
    {
        if ((int) $news->media_type !== 1) {
            return [];
        }

        $images = is_array($news->images) ? array_values(array_filter($news->images)) : [];

        if ($images === [] && $news->media) {
            $images = [$news->media]; // purani posts: sirf ek image
        }

        return $images;
    }

    /**
     * Purani media file delete karo (URL ko haath nahi lagata).
     * Naye (public/storage) aur purane (storage/app/public) dono locations check karta hai.
     */
    protected function deleteNewsMediaFile(?string $media): void
    {
        if (! $media || filter_var($media, FILTER_VALIDATE_URL)) {
            return;
        }

        $media = ltrim($media, '/');

        if (str_contains($media, '..')) {
            return;
        }

        $newPath = public_path('storage/' . $media);

        if (is_file($newPath)) {
            @unlink($newPath);
        }

        $oldPath = storage_path('app/public/' . $media);

        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
}
