<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>SolaStock — Inventory Command</title>

    <link rel="icon" href="{{ asset('imgs/favicon-solastock.svg') }}" type="image/svg+xml">
    <link rel="shortcut icon" href="{{ asset('imgs/favicon-solastock.svg') }}" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" />

    <link rel="stylesheet" href="{{ asset('solastock/styles.css') }}" />
    <link rel="stylesheet" href="{{ asset('solastock/floor.css') }}" />

    <script>
        (function () {
            var t = localStorage.getItem("solastock_theme");
            if (!t) t = (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
            document.documentElement.setAttribute("data-theme", t);
        })();
    </script>
</head>
<body>
    <div id="root"></div>

    <script src="https://unpkg.com/react@18.3.1/umd/react.development.js" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" crossorigin="anonymous"></script>

    <script type="text/babel" src="{{ asset('solastock/data.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/icons.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/charts.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/viz.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/components.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-widgets.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-dashboard.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-items.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-warehouse.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/floor.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-orders.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/screens-misc.jsx') }}" data-presets="react"></script>
    <script type="text/babel" src="{{ asset('solastock/app.jsx') }}" data-presets="react"></script>
</body>
</html>
