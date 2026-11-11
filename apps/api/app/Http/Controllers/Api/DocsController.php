<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocsController extends Controller
{
    /**
     * Muestra la interfaz interactiva de documentación OpenAPI (Swagger UI).
     * Solo disponible en entornos no productivos (local, testing, staging, etc.).
     */
    public function index(): Response
    {
        if (app()->environment('production')) {
            throw new NotFoundHttpException('Documentación no disponible en producción');
        }

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>GestiaHR · Referencia de API</title>
  <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" >
  <style>
    html { box-sizing: border-box; overflow-y: scroll; }
    *, *:before, *:after { box-sizing: inherit; }
    body { margin: 0; background: #fafafa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .topbar { display: none !important; }
    .swagger-ui .info { margin: 20px 0; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
  <script>
    window.onload = function() {
      window.ui = SwaggerUIBundle({
        url: "/docs/openapi.yaml",
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIStandalonePreset
        ],
        layout: "BaseLayout"
      });
    };
  </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Entrega el archivo de especificación OpenAPI YAML.
     */
    public function spec(): Response
    {
        if (app()->environment('production')) {
            throw new NotFoundHttpException('Especificación no disponible en producción');
        }

        $possiblePaths = [
            '/var/www/kit/api/openapi.yaml',
            base_path('../../kit/api/openapi.yaml'),
            base_path('../kit/api/openapi.yaml'),
            base_path('kit/api/openapi.yaml'),
            dirname(base_path()).'/kit/api/openapi.yaml',
            dirname(dirname(base_path())).'/kit/api/openapi.yaml',
        ];

        $specPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $specPath = $path;
                break;
            }
        }

        if ($specPath === null) {
            throw new NotFoundHttpException('Archivo de especificación openapi.yaml no encontrado');
        }

        $content = (string) file_get_contents($specPath);

        return response($content, 200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
        ]);
    }
}
