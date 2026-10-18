<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores uploads on a private disk under a tenant-specific prefix with a
 * generated file name; the client's file name is kept only as metadata.
 * Files are served only through an authorizing controller, never by URL.
 */
class AttachmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(UploadedFile $file, Model $attachable, string $category, ?string $organizationId, User $uploader): Attachment
    {
        $disk = config('platform.attachments.disk');
        $prefix = $organizationId ? "tenants/{$organizationId}" : 'platform';
        $id = (string) Str::ulid();
        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs("{$prefix}/attachments/".now()->format('Y/m'), "{$id}.{$extension}", $disk);

        $attachment = Attachment::query()->create([
            'id' => $id,
            'organization_id' => $organizationId,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'category' => $category,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'not_scanned',
            'uploaded_by' => $uploader->id,
        ]);

        $this->audit->record('attachment.uploaded', $attachment, new: $attachment->only(['category', 'original_name', 'mime_type', 'size_bytes', 'sha256']), organizationId: $organizationId ?? false);

        return $attachment;
    }
}
