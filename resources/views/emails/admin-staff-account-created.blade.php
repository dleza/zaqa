<p>Hello {{ $recipientName }},</p>

<p>
  An administrator has created your ZAQA staff account. You can sign in using the details below.
</p>

<p><strong>Login URL:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
<p><strong>Username (email):</strong> {{ $email }}</p>
<p><strong>Role:</strong> {{ $roleName }}</p>

<p><strong>Temporary password (copy and store securely):</strong></p>
<pre style="padding:12px;border:1px solid #ddd;background:#f7f7f7;white-space:pre-wrap;">{{ $plainTextPassword }}</pre>

<p><strong>Security instructions:</strong></p>
<ul>
  <li>Sign in as soon as possible and change your password after your first login.</li>
  <li>Do not share this password with anyone.</li>
  <li>If you did not expect this account, contact your ZAQA administrator immediately.</li>
</ul>

<p>Regards,<br>ZAQA</p>
