<?php

namespace App\Http\Controllers;

use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicWorkFormAttachmentController extends Controller
{
    public function show(string $token, int $attachment): Response
    {
        $workForm = OrderItemWorkForm::query()
            ->where('public_tracking_token', $token)
            ->where('status', 'active')
            ->firstOrFail();

        $attachmentRecord = OrderItemWorkFormAttachment::query()
            ->whereKey($attachment)
            ->where('work_form_id', $workForm->id)
            ->where('visibility', 'customer_visible')
            ->firstOrFail();

        $filePath = (string) $attachmentRecord->file_path;
        $diskCandidates = collect([
            $attachmentRecord->disk,
            'public',
            config('filesystems.default'),
        ])->filter()->unique()->values();

        [$disk, $resolvedPath] = $this->resolveAttachmentStorageLocation(
            $workForm,
            $attachmentRecord,
            $diskCandidates->all(),
            $filePath
        );

        if (!$disk || $resolvedPath === null) {
            abort(404);
        }

        $mimeType = $attachmentRecord->mime_type ?: Storage::disk($disk)->mimeType($resolvedPath) ?: 'application/octet-stream';
        $fileName = $attachmentRecord->file_name ?: basename($resolvedPath);
        $disposition = sprintf('inline; filename="%s"', addslashes($fileName));

        return response(Storage::disk($disk)->get($resolvedPath), 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function resolveAttachmentStorageLocation(
        OrderItemWorkForm $workForm,
        OrderItemWorkFormAttachment $attachment,
        array $diskCandidates,
        string $filePath
    ): array {
        $normalizedFilePath = str_replace('\\', '/', $filePath);

        foreach ($diskCandidates as $disk) {
            foreach (array_values(array_unique(array_filter([$filePath, $normalizedFilePath]))) as $candidatePath) {
                if ($candidatePath !== '' && Storage::disk($disk)->exists($candidatePath)) {
                    return [$disk, $candidatePath];
                }
            }
        }

        $fileName = trim((string) $attachment->file_name);

        if ($fileName === '') {
            return [null, null];
        }

        $directory = str_replace('\\', '/', sprintf(
            'work-forms/%d/%d/%d',
            $workForm->tenant_account_id,
            $workForm->order_id,
            $workForm->id
        ));

        $originalBaseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9\-_ ]/', '')
            ->replace(' ', '-')
            ->trim('-_')
            ->value();

        foreach ($diskCandidates as $disk) {
            $storage = Storage::disk($disk);

            $matchedPath = $this->safeAllFiles($storage, $directory)
                ->first(function (string $candidatePath) use ($fileName, $originalBaseName): bool {
                    $candidateFileName = basename($candidatePath);

                    if ($candidateFileName === $fileName) {
                        return true;
                    }

                    return $originalBaseName !== ''
                        && str_starts_with(
                            Str::of(pathinfo($candidateFileName, PATHINFO_FILENAME))->value(),
                            $originalBaseName
                        );
                });

            if ($matchedPath) {
                return [$disk, $matchedPath];
            }

            $fallbackPath = $this->safeAllFiles($storage, 'work-forms')
                ->first(function (string $candidatePath) use ($fileName, $originalBaseName): bool {
                    $candidateFileName = basename($candidatePath);

                    if ($candidateFileName === $fileName) {
                        return true;
                    }

                    return $originalBaseName !== ''
                        && str_starts_with(
                            Str::of(pathinfo($candidateFileName, PATHINFO_FILENAME))->value(),
                            $originalBaseName
                        );
                });

            if ($fallbackPath) {
                return [$disk, $fallbackPath];
            }
        }

        return [null, null];
    }

    private function safeAllFiles($storage, string $directory)
    {
        try {
            return collect($storage->allFiles($directory));
        } catch (\Throwable) {
            return collect();
        }
    }
}
