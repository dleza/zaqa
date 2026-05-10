@component('mail::message')
# New qualification verification assignment

Application **{{ $application?->application_number ?? '—' }}** has a qualification item assigned to you for review.

**Qualification:** {{ $qualification->title_of_qualification ?? '—' }}  
**Qualification type:** {{ $qualification->qualificationTypeMaster?->name ?? $qualification->qualification_type ?? '—' }}  
**Awarding Institution:** {{ $qualification->awardingInstitution?->name ?? $qualification->awarding_institution_name_other ?? $qualification->awarding_institution_name ?? '—' }}  
**Country of award:** {{ $qualification->country?->name ?? $qualification->country_name_other ?? '—' }}  
**Local/Foreign:** {{ ($qualification->is_foreign_qualification ?? false) ? 'Foreign' : 'Local' }}

@if(!empty($comment))
**Comment from {{ $assignedBy->name }}:**

{{ $comment }}
@endif

@component('mail::button', ['url' => $adminUrl])
Open qualification task
@endcomponent

Thanks,  
ZAQA Portal
@endcomponent
