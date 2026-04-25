@component('mail::message')
# New Level 1 assignment

Application **{{ $application->application_number }}** has been assigned to you for Level 1 review.

@if(!empty($comment))
**Comment from {{ $assignedBy->name }}:**

{{ $comment }}
@endif

@component('mail::button', ['url' => $adminUrl])
Open application
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent

