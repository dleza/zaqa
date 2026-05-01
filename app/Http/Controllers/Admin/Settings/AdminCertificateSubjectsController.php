<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreCertificateSubjectRequest;
use App\Http\Requests\Admin\Settings\UpdateCertificateSubjectRequest;
use App\Models\CertificateSubject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminCertificateSubjectsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');

        $subjects = CertificateSubject::query()
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CertificateSubject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => (bool) $s->is_active,
                'sort_order' => (int) ($s->sort_order ?? 0),
            ]);

        return Inertia::render('Admin/Settings/CertificateSubjects/Index', [
            'subjects' => $subjects,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.certificate_subjects.create'),
                'edit' => (bool) $request->user()?->can('settings.certificate_subjects.edit'),
                'delete' => (bool) $request->user()?->can('settings.certificate_subjects.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/CertificateSubjects/Create');
    }

    public function store(StoreCertificateSubjectRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $subject = CertificateSubject::query()->create([
            'name' => trim((string) $data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        $audit->record(
            eventType: 'settings.certificate_subject_created',
            module: 'Settings',
            actionName: 'certificate_subject_created',
            message: 'Certificate subject created.',
            entityType: CertificateSubject::class,
            entityId: $subject->id,
            afterState: $subject->toArray(),
            actor: $request->user(),
        );

        return redirect()->route('admin.settings.certificate_subjects.index')
            ->with('success', 'Certificate subject created.');
    }

    public function edit(Request $request, CertificateSubject $certificate_subject): Response
    {
        return Inertia::render('Admin/Settings/CertificateSubjects/Edit', [
            'subject' => [
                'id' => $certificate_subject->id,
                'name' => $certificate_subject->name,
                'is_active' => (bool) $certificate_subject->is_active,
                'sort_order' => (int) ($certificate_subject->sort_order ?? 0),
            ],
        ]);
    }

    public function update(UpdateCertificateSubjectRequest $request, CertificateSubject $certificate_subject, AuditLogService $audit): RedirectResponse
    {
        $before = $certificate_subject->toArray();
        $data = $request->validated();

        $certificate_subject->forceFill([
            'name' => trim((string) $data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ])->save();

        $audit->record(
            eventType: 'settings.certificate_subject_updated',
            module: 'Settings',
            actionName: 'certificate_subject_updated',
            message: 'Certificate subject updated.',
            entityType: CertificateSubject::class,
            entityId: $certificate_subject->id,
            beforeState: $before,
            afterState: $certificate_subject->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Certificate subject updated.');
    }

    public function destroy(Request $request, CertificateSubject $certificate_subject, AuditLogService $audit): RedirectResponse
    {
        $before = $certificate_subject->toArray();

        $certificate_subject->forceFill(['is_active' => false])->save();

        $audit->record(
            eventType: 'settings.certificate_subject_deactivated',
            module: 'Settings',
            actionName: 'certificate_subject_deactivated',
            message: 'Certificate subject deactivated.',
            entityType: CertificateSubject::class,
            entityId: $certificate_subject->id,
            beforeState: $before,
            afterState: $certificateSubject->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Certificate subject deactivated.');
    }
}
