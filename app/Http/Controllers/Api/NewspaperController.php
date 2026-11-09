<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Newspaper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NewspaperController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // User app par sirf pichhle ek mahine ke active/published newspapers
        // dikhaye jaate hain. Admin listing par iska koi effect nahi hai.
        $query = Newspaper::query()
            ->where('is_published', true)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->whereDate('published_for_date', '>=', now()->subMonth()->toDateString())
            ->latest('published_for_date');
        if ($request->filled('date')) $query->whereDate('published_for_date', $request->input('date'));
        return response()->json(['newspapers' => $query->get()->map(fn (Newspaper $n) => $this->resource($n))]);
    }
    public function show(int $id): JsonResponse
    {
        $n = Newspaper::query()->where('is_published', true)->where('is_active', true)->where('is_deleted', false)->findOrFail($id);
        return response()->json(['newspaper' => $this->resource($n)]);
    }
    public function adminIndex(): JsonResponse { return response()->json(['newspapers' => Newspaper::latest()->get()->map(fn (Newspaper $n) => $this->resource($n))]); }
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request, true);
        $n = Newspaper::create($this->withoutFiles($data) + $this->uploads($request));
        return response()->json(['message' => 'Newspaper created successfully.', 'newspaper' => $this->resource($n)], 201);
    }
    public function update(Request $request, int $id): JsonResponse
    {
        $n = Newspaper::findOrFail($id); $data = $this->validateData($request, false); $n->update($this->withoutFiles($data) + $this->uploads($request));
        return response()->json(['message' => 'Newspaper updated successfully.', 'newspaper' => $this->resource($n->fresh())]);
    }
    public function publish(Request $request, int $id): JsonResponse
    {
        $n = Newspaper::findOrFail($id); $data = $request->validate(['is_published' => ['required', 'boolean'], 'is_active' => ['nullable', 'boolean']]);
        $n->update($data); return response()->json(['message' => $n->is_published ? 'Newspaper published.' : 'Newspaper unpublished.', 'newspaper' => $this->resource($n->fresh())]);
    }
    public function destroy(int $id): JsonResponse { $n = Newspaper::findOrFail($id); $n->update(['is_deleted' => true]); return response()->json(['message' => 'Newspaper deleted successfully.']); }
    protected function validateData(Request $request, bool $create): array
    {
        return $request->validate([
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:255'], 'published_for_date' => [$create ? 'required' : 'sometimes', 'date'],
            'description' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean'], 'is_published' => ['nullable', 'boolean'],
            'thumbnail' => ['nullable', 'image', 'max:2048'], 'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);
    }
    protected function uploads(Request $request): array
    {
        $out = []; foreach (['thumbnail' => 'images', 'pdf' => 'pdfs'] as $field => $folder) if ($request->hasFile($field)) {
            $dir = public_path("storage/newspapers/{$folder}"); if (! is_dir($dir)) mkdir($dir, 0755, true);
            $file = $request->file($field); $name = Str::uuid().'.'.($file->extension() ?: 'bin'); $file->move($dir, $name); $out[$field] = "newspapers/{$folder}/{$name}";
        } return $out;
    }
    protected function withoutFiles(array $data): array { unset($data['thumbnail'], $data['pdf']); return $data; }
    protected function resource(Newspaper $n): array { return ['id' => $n->id, 'title' => $n->title, 'published_for_date' => $n->published_for_date?->toDateString(), 'thumbnail' => $n->thumbnail ? asset('storage/'.$n->thumbnail) : null, 'pdf_url' => $n->pdf ? asset('storage/'.$n->pdf) : null, 'description' => $n->description, 'is_published' => $n->is_published, 'is_active' => $n->is_active, 'created_at' => $n->created_at]; }
}
