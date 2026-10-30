<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuthService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/') || in_array($path, ['/api/health', '/api/auth/login'], true)) {
            return $handler->handle($request);
        }
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer (\S+)$/D', $header, $match)) {
            throw new ApiException('Требуется авторизация.', 401);
        }
        return $handler->handle($request->withAttribute('user_id', $this->auth->verify($match[1])));
    }
}
