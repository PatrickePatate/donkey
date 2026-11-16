<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="reverb-key" content="{{ config('reverb.apps.apps.0.key') }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<script>
    (() => {
        const stored = localStorage.getItem('theme');
        const dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
