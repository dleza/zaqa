@php
  /** @var \App\Models\InstitutionApiClient $client */
  $institution = $client->awardingInstitution;
  $baseUrl = config('app.url');
  $docsUrl = $baseUrl.'/docs/institution-api';
  $apiBase = $baseUrl.'/api/institution/v1';
@endphp

<p>Hello {{ $client->contact_name ?: 'Team' }},</p>

<p>
  ZAQA has issued an Institution Integration API bearer token for:
  <strong>{{ $institution?->name ?? 'Awarding Institution' }}</strong>.
</p>

<p><strong>API Base URL:</strong> {{ $apiBase }}</p>
<p><strong>Swagger/OpenAPI Docs:</strong> {{ $docsUrl }}</p>

<p><strong>Bearer Token (copy and store securely):</strong></p>
<pre style="padding:12px;border:1px solid #ddd;background:#f7f7f7;white-space:pre-wrap;">{{ $plainTextToken }}</pre>

<p><strong>Granted scopes/abilities:</strong></p>
<ul>
  @foreach($abilities as $a)
    <li>{{ $a }}</li>
  @endforeach
</ul>

<p><strong>Security instructions:</strong></p>
<ul>
  <li>Store this token in a secure secret manager and do not share it broadly.</li>
  <li>Rotate/revoke the token immediately if it is leaked.</li>
  <li>Do not embed the token in client-side applications.</li>
</ul>

<p>If you need support with integration or token rotation, please contact ZAQA support.</p>

<p>Regards,<br>ZAQA</p>

