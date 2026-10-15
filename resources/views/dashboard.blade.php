<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ $csrfToken }}">
<meta name="csp-nonce" content="{{ $nonce }}">
<title>Dynamic Throttle</title>
<link rel="icon" href="data:,">
@foreach ($entry['styles'] as $style)
<link rel="stylesheet" href="{{ $assets.$style }}">
@endforeach
@foreach ($entry['preloads'] as $preload)
<link rel="modulepreload" href="{{ $assets.$preload }}">
@endforeach
<script type="module" src="{{ $assets.$entry['script'] }}"></script>
</head>
<body>
<div id="dynamic-throttle" data-base="{{ $base }}"></div>
<noscript>The dashboard needs JavaScript.</noscript>
</body>
</html>
