<?php

namespace App\Domain\Settings;

use App\Enums\DocumentSignatureType;
use App\Models\DocumentSignatureSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentSignatureService
{
    public function activeForType(DocumentSignatureType $type): ?DocumentSignatureSetting
    {
        return DocumentSignatureSetting::query()
            ->with('uploadedBy:id,name')
            ->where('type', $type)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    public function dataUriForType(DocumentSignatureType $type): ?string
    {
        $setting = $this->activeForType($type);
        if (! $setting) {
            return null;
        }

        return $this->dataUriFromSetting($setting);
    }

    public function dataUriFromSetting(DocumentSignatureSetting $setting): ?string
    {
        $disk = Storage::disk($setting->disk);
        if (! $disk->exists($setting->file_path)) {
            return null;
        }

        $contents = $disk->get($setting->file_path);
        if ($contents === null || $contents === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    public function storeUpload(DocumentSignatureType $type, UploadedFile $file, User $actor, ?string $displayName = null): DocumentSignatureSetting
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'png') {
            throw ValidationException::withMessages([
                'file' => 'Signature must be a PNG image.',
            ]);
        }

        if ($file->getMimeType() !== 'image/png') {
            throw ValidationException::withMessages([
                'file' => 'Signature must be a PNG image.',
            ]);
        }

        DocumentSignatureSetting::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $path = $file->store('document-signatures/'.$type->value, 'local');

        return DocumentSignatureSetting::query()->create([
            'type' => $type,
            'display_name' => $displayName ?: ucfirst($type->value).' signature',
            'file_path' => $path,
            'disk' => 'local',
            'is_active' => true,
            'uploaded_by_user_id' => $actor->id,
        ]);
    }

    public function deactivate(DocumentSignatureSetting $setting): void
    {
        $setting->forceFill(['is_active' => false])->save();
    }

    public function remove(DocumentSignatureSetting $setting): void
    {
        Storage::disk($setting->disk)->delete($setting->file_path);
        $setting->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activePayload(DocumentSignatureType $type): ?array
    {
        $setting = $this->activeForType($type);
        if (! $setting) {
            return null;
        }

        return [
            'id' => $setting->id,
            'type' => $setting->type->value,
            'display_name' => $setting->display_name,
            'is_active' => $setting->is_active,
            'uploaded_at' => optional($setting->created_at)?->toIso8601String(),
            'uploaded_by' => $setting->uploadedBy?->name,
            'preview_url' => route('admin.settings.document_signatures.preview', $setting),
        ];
    }
}
