@component('mail::message')
# Qualification amendment required

Your application **{{ $application->application_number }}** requires updates to **one qualification item** (reference {{ $qualification->verification_reference_number ?? ('#'.$qualification->id) }}).

**Qualification:** {{ $qualification->title_of_qualification }}

**Comment from ZAQA:**

{{ $comment }}

@component('mail::button', ['url' => $amendUrl])
Update this qualification
@endcomponent

If you can’t open the link, login first:

@component('mail::button', ['url' => $loginUrl])
Login
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent
