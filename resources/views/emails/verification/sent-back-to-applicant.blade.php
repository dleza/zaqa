@component('mail::message')
# Application sent back for amendments

Your application **{{ $application->application_number }}** has been sent back for amendments.

**Comment from ZAQA:**

{{ $comment }}

@component('mail::button', ['url' => $trackUrl])
Track application
@endcomponent

If you can’t access the tracking page, login first:

@component('mail::button', ['url' => $loginUrl])
Login
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent

