@php
  /** @var \App\Models\InstitutionApiClient $client */
  $institution = $client->awardingInstitution;
@endphp

<p>Hello {{ $client->contact_name ?: 'Team' }},</p>

<p>
  ZAQA has generated a pull lookup bearer token for:
  <strong>{{ $institution?->name ?? 'Awarding Institution' }}</strong>.
</p>

@if($lookupUrl)
  <p><strong>Lookup endpoint ZAQA will call:</strong> {{ $lookupUrl }}</p>
@endif

<p><strong>Pull lookup token (copy and store securely):</strong></p>
<pre style="padding:12px;border:1px solid #ddd;background:#f7f7f7;white-space:pre-wrap;">{{ $plainTextToken }}</pre>

<p><strong>Institution system configuration:</strong></p>
<p>Configure your institution-hosted lookup endpoint to accept this token. For UNZA SIS, set:</p>
<pre style="padding:12px;border:1px solid #ddd;background:#f7f7f7;white-space:pre-wrap;">ZAQA_LOOKUP_ENABLED=true
ZAQA_LOOKUP_TOKEN={{ $plainTextToken }}</pre>

<p><strong>Security instructions:</strong></p>
<ul>
  <li>This token is different from the ZAQA push API token used for institution → ZAQA submissions.</li>
  <li>Store this token in a secure secret manager and do not share it broadly.</li>
  <li>Rotate the token immediately if it is leaked.</li>
</ul>

<p>If you need support with integration or token rotation, please contact ZAQA support.</p>

<p>Regards,<br>ZAQA</p>
