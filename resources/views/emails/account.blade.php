<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$homeUrl">TaskBridge</x-mail::header>
</x-slot:header>

# {{ $heading }}

{{ $introduction }}

<x-mail::button :url="$actionUrl">{{ $actionText }}</x-mail::button>

{{ $expiry }}

{{ $guidance }}

Thanks,<br>
TaskBridge

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:
[{{ $actionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
<x-slot:footer>
<x-mail::footer>TaskBridge · Better work. Together.</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
