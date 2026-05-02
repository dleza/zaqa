@component('mail::message')
# Level 1 review completed (qualification task)

Qualification item **{{ $qualification->title_of_qualification }}** on application **{{ $application->application_number ?? '' }}** has been reviewed by Level 1.

**Completed by:** {{ $level1Actor->name }}

**Findings:**

{{ $findings }}

@component('mail::button', ['url' => $adminUrl])
Open qualification task
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent
