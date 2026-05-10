@component('mail::message')
# Level 1 review completed (qualification task)

Qualification item **{{ $qualification->title_of_qualification }}** on application **{{ $application->application_number ?? '' }}** has been reviewed by Level 1.

**Qualification type:** {{ $qualification->qualificationTypeMaster?->name ?? $qualification->qualification_type ?? '—' }}  
**Awarding Institution:** {{ $qualification->awardingInstitution?->name ?? $qualification->awarding_institution_name_other ?? $qualification->awarding_institution_name ?? '—' }}  
**Country of award:** {{ $qualification->country?->name ?? $qualification->country_name_other ?? '—' }}

**Completed by:** {{ $level1Actor->name }}

**Findings:**

{{ $findings }}

@component('mail::button', ['url' => $adminUrl])
Open qualification task
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent
