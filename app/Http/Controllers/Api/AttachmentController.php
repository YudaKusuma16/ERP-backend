<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'detachable_type' => 'required|string',
            'detachable_id' => 'required|integer',
        ]);

        $attachments = Attachment::where('detachable_type', $request->detachable_type)
            ->where('detachable_id', $request->detachable_id)
            ->with('uploader')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['attachments' => $attachments]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'detachable_type' => 'required|string',
            'detachable_id' => 'required|integer',
        ]);

        $file = $request->file('file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('attachments', $fileName, 'public');

        $attachment = Attachment::create([
            'detachable_type' => $validated['detachable_type'],
            'detachable_id' => $validated['detachable_id'],
            'original_name' => $file->getClientOriginalName(),
            'file_name' => $fileName,
            'file_path' => $filePath,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'File uploaded successfully.',
            'attachment' => $attachment->load('uploader'),
        ], 201);
    }

    public function download(Attachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted.']);
    }
}