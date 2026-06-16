@component('mail::message')
# Applicant corrections submitted

The applicant **{{ $applicant->name }}** has submitted corrections for a qualification on application **{{ $application->application_number }}**.

**Qualification:** {{ $qualification->title_of_qualification }}

**Holder:** {{ $qualification->qualification_holder_name }}

@component('mail::button', ['url' => $adminUrl])
Review qualification
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent
