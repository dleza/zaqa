@component('mail::message')
# Qualification returned for Level 1 correction

Hello {{ $assignedTo->name }},

**{{ $sentBy->name }}** has returned a qualification to you for internal Level 1 correction.

**Application:** {{ $application->application_number ?? '—' }}  
**Qualification:** {{ $qualification->title_of_qualification ?? 'Qualification' }}

**Level 2 comment:**  
{{ $commentExcerpt }}

@component('mail::button', ['url' => $adminUrl])
Open qualification review
@endcomponent

This is an internal review correction. The qualification has **not** been sent back to the applicant.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
