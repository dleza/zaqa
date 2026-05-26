<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Institution Integration API Docs</title>
    @vite(['resources/js/docs/institution-api.ts'])
  </head>
  <body>
    <div id="swagger-ui"></div>
  </body>
</html>

