<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\ExportService;
use App\Service\ProductData;
use App\Service\ProductService;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductController
{
    public function __construct(private readonly ProductRepository $repository, private readonly ProductService $service, private readonly ExportService $export)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return Json::response($response, $this->repository->paginate($request->getQueryParams()));
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, $this->repository->find((int) $args['id'])->toArray());
    }

    public function create(Request $request, Response $response): Response
    {
        return Json::response($response, $this->service->save(ProductData::fromArray((array) $request->getParsedBody()), createOnly: true), 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, $this->service->save(ProductData::fromArray((array) $request->getParsedBody()), (int) $args['id']));
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->repository->delete((int) $args['id']);
        return $response->withStatus(204);
    }

    public function export(Request $request, Response $response): Response
    {
        $path = $this->export->generate($request->getQueryParams());
        try {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot read export');
            }
            $response = $response->withBody(new \Slim\Psr7\Stream($stream));
            // Unix retains the open handle until the response has finished streaming.
            unlink($path);
            return $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->withHeader('Content-Disposition', 'attachment; filename="products.xlsx"')->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }
}
