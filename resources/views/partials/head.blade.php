<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ isset($title) ? $title.' | TaskBridge' : 'TaskBridge' }}</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon" sizes="16x16 32x32 48x48 64x64" />

@vite('resources/css/app.css')
@fluxAppearance
