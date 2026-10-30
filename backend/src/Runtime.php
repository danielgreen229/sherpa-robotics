<?php

declare(strict_types=1);

namespace App;

use App\Repository\ImportRepository;
use App\Repository\CatalogResetRepository;
use App\Repository\ProductRepository;
use App\Repository\RateLimitRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\CatalogResetService;
use App\Support\CatalogLock;
use App\Service\ImageDownloader;
use App\Service\ImportProcessor;
use App\Service\ImportQueue;
use App\Service\ImportService;
use App\Service\ProductService;
use App\Support\Database;

final class Runtime
{
    public readonly Database $database;
    public readonly ProductRepository $products;
    public readonly ImportRepository $jobs;
    public readonly UserRepository $users;
    public readonly RateLimitRepository $limits;
    public readonly ProductService $productService;
    public readonly ImportQueue $queue;
    public readonly ImportService $importService;
    public readonly AuthService $auth;
    public readonly CatalogResetService $catalogReset;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? new Database();
        $connection = $this->database->connection();
        $this->products = new ProductRepository($this->database);
        $this->jobs = new ImportRepository($connection);
        $this->users = new UserRepository($connection);
        $this->limits = new RateLimitRepository($connection);
        $this->productService = new ProductService($this->products, new ImageDownloader($this->products));
        $this->queue = new ImportQueue();
        $this->importService = new ImportService($this->jobs, $this->queue);
        $this->catalogReset = new CatalogResetService(new CatalogResetRepository($connection), new CatalogLock($connection));
        $this->auth = new AuthService($this->users);
    }

    public function processor(): ImportProcessor
    {
        return new ImportProcessor($this->database->connection(), $this->jobs, $this->productService, $this->products);
    }
}
