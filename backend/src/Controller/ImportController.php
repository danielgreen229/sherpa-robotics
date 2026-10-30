<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ImportRepository;
use App\Repository\RateLimitRepository;
use App\Service\ImportService;
use App\Service\CatalogResetService;
use App\Support\ApiException;
use App\Support\Config;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class ImportController
{
    public function __construct(private readonly ImportRepository $jobs, private readonly ImportService $service, private readonly RateLimitRepository $limits, private readonly CatalogResetService $reset)
    {
    }

    public function create(Request $request, Response $response): Response
    {
        $user = (int) $request->getAttribute('user_id');
        if (!$this->limits->consume('import:' . $user, (int) Config::env('IMPORT_RATE_LIMIT', '5'), 60)) {
            return Json::response($response->withHeader('Retry-After', '60'), ['error' => 'Слишком много импортов. Повторите через минуту.'], 429);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ApiException('Передайте XLSX в multipart-поле file.', 422);
        }
        $id = $this->service->upload($file, $user);
        return Json::response($response, ['id' => $id, 'status' => 'queued'], 202);
    }

    /** @param array<string, string> $args */
    public function status(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, $this->jobs->status($args['id'], (int) $request->getAttribute('user_id')));
    }

    /** @param array<string, string> $args */
    public function errors(Request $request, Response $response, array $args): Response
    {
        return Json::response($response, $this->jobs->errors($args['id'], (int) $request->getAttribute('user_id'), $request->getQueryParams()));
    }

    /** @param array<string, string> $args */
    public function report(Request $request, Response $response, array $args): Response
    {
        $stream = (new \App\Service\ImportReportService($this->jobs))->generate($args['id'], (int) $request->getAttribute('user_id'));
        return $response->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="import-' . $args['id'] . '.json"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function recent(Request $request, Response $response): Response
    {
        return Json::response($response, $this->jobs->recent((int) $request->getAttribute('user_id')));
    }

    public function clear(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (($data['confirm'] ?? null) !== 'CLEAR_CATALOG') {
            throw new ApiException('Подтвердите удаление всего каталога в обновлённом интерфейсе.', 422);
        }
        return Json::response($response, $this->reset->clear((int) $request->getAttribute('user_id')));
    }
}
