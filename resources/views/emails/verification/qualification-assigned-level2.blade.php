@component('mail::message')
# Level 2 review task assigned

You have been assigned a Level 2 qualification review task.

**Application:** {{ $application->application_number ?? '—' }}

**Qualification:** {{ $qualification->title_of_qualification ?? 'Qualification' }}

**Assignment category:** {{ $category->name ?? '—' }}

@component('mail::button', ['url' => $adminUrl])
Open qualification review
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
