<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        #documentation {
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <div id="documentation"></div>

    @if(($ui ?? 'redoc') === 'swagger-ui')
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.min.js"></script>
        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
        <script>
            window.addEventListener('load', function () {
                SwaggerUIBundle(Object.assign({
                    url: @json($jsonUrl),
                    dom_id: '#documentation',
                    presets: [
                        SwaggerUIBundle.presets.apis,
                        SwaggerUIStandalonePreset
                    ],
                    layout: 'BaseLayout'
                }, @json($uiOptions ?? [])));
            });
        </script>
    @else
        <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
        <script>
            window.addEventListener('load', function () {
                Redoc.init(
                    @json($jsonUrl),
                    Object.assign({}, @json($uiOptions ?? [])),
                    document.getElementById('documentation')
                );
            });
        </script>
    @endif
</body>
</html>


