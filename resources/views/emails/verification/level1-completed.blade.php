@component('mail::message')
# Level 1 review completed

Application **{{ $application->application_number }}** has been completed by Level 1 and is ready for your Level 2 review.

**Completed by:** {{ $level1Actor->name }}

**Findings:**

{{ $findings }}

@component('mail::button', ['url' => $adminUrl])
Open application
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent

