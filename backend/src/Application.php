<?php

declare(strict_types=1);

namespace App;

use App\Controller\ImportController;
use App\Controller\ProductController;
use App\Middleware\AuthMiddleware;
use App\Service\ExportService;
use App\Support\ApiException;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

final class Application
{
    /** @return App<\Psr\Container\ContainerInterface|null> */
    public static function create(?Runtime $runtime = null): App
    {
        $runtime ??= new Runtime();
        $app = AppFactory::create();
        $product = new ProductController($runtime->products, $runtime->productService, new ExportService($runtime->products));
        $import = new ImportController($runtime->jobs, $runtime->importService, $runtime->limits, $runtime->catalogReset);
        $app->get('/api/health', function (Request $request, Response $response) use ($runtime): Response {
            try {
                $runtime->database->connection()->executeQuery('SELECT 1 FROM users LIMIT 1');
                return Json::response($response, ['status' => 'ok', 'database' => 'ok']);
            } catch (\Throwable) {
                return Json::response($response, ['status' => 'unhealthy', 'database' => 'unavailable'], 503);
            }
        });
        $app->post('/api/auth/login', function (Request $request, Response $response) use ($runtime): Response {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            if (!$runtime->limits->consume('login:' . $ip, 10, 60)) {
                return Json::response($response->withHeader('Retry-After', '60'), ['error' => 'Слишком много попыток входа.'], 429);
            }
            $data = (array) $request->getParsedBody();
            if (!is_string($data['email'] ?? null) || !is_string($data['password'] ?? null) || strlen($data['email']) > 255 || strlen($data['password']) > 1024) {
                throw new ApiException('Передайте email и пароль.', 422);
            }
            return Json::response($response, $runtime->auth->login(trim($data['email']), $data['password']));
        });
        $app->get('/api/products/export', [$product, 'export']);
        $app->get('/api/products', [$product, 'index']);
        $app->post('/api/products', [$product, 'create']);
        $app->get('/api/products/{id:[0-9]+}', [$product, 'show']);
        $app->put('/api/products/{id:[0-9]+}', [$product, 'update']);
        $app->delete('/api/products/{id:[0-9]+}', [$product, 'delete']);
        $app->get('/api/imports', [$import, 'recent']);
        $app->post('/api/imports', [$import, 'create']);
        $app->delete('/api/imports', [$import, 'clear']);
        $app->get('/api/imports/{id:[a-f0-9]{32}}', [$import, 'status']);
        $app->get('/api/imports/{id:[a-f0-9]{32}}/errors', [$import, 'errors']);
        $app->get('/api/imports/{id:[a-f0-9]{32}}/report', [$import, 'report']);
        $app->get('/openapi.json', function (Request $request, Response $response): Response {
            $response->getBody()->write((string) file_get_contents(dirname(__DIR__) . '/openapi.json'));
            return $response->withHeader('Content-Type', 'application/json');
        });
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new AuthMiddleware($runtime->auth));
        $errors = $app->addErrorMiddleware(false, true, true);
        $errors->setDefaultErrorHandler(function (Request $request, \Throwable $e) use ($app): Response {
            $status = $e instanceof ApiException ? $e->status : ($e instanceof HttpException ? $e->getCode() : 500);
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            if ($status === 500) {
                error_log((string) $e);
            }
            $message = $e instanceof ApiException ? $e->getMessage() : ($status === 404 ? 'Ресурс не найден.' : ($status === 500 ? 'Внутренняя ошибка сервера.' : 'Некорректный запрос.'));
            return Json::response($app->getResponseFactory()->createResponse(), ['error' => $message], $status);
        });
        return $app;
    }
}
