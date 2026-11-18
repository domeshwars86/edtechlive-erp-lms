<p>Hello,</p>
<p>{{ $inviterName }} invited you to join <strong>{{ $organizationName }}</strong> on edTechLive as {{ str_replace('_', ' ', $role) }}.</p>
<p><a href="{{ $invitationUrl }}">Review and accept invitation</a></p>
<p>This invitation expires in seven days. If you did not expect it, you can ignore this message.</p>
