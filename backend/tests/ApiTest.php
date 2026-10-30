<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Runtime;
use App\Service\ProductData;
use App\Repository\RateLimitRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ApiTest extends DatabaseTestCase
{
    private \Slim\App $app;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $runtime = new Runtime($this->database);
        $this->app = Application::create($runtime);
        $this->token = $runtime->auth->login('test@example.com', 'CatalogTest2026!')['token'];
    }

    private function request(string $method, string $url, ?array $body = null, bool $auth = true): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $url, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($auth) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        return $this->app->handle($request);
    }

    public function testHealthIsPublicButCatalogRequiresAuth(): void
    {
        self::assertSame(200, $this->request('GET', '/api/health', auth: false)->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/products', auth: false)->getStatusCode());
    }

    public function testLoginAndInvalidPassword(): void
    {
        $valid = $this->request('POST', '/api/auth/login', ['email' => 'test@example.com', 'password' => 'CatalogTest2026!'], false);
        self::assertSame(200, $valid->getStatusCode());
        self::assertArrayHasKey('token', json_decode((string) $valid->getBody(), true));
        self::assertSame(401, $this->request('POST', '/api/auth/login', ['email' => 'test@example.com', 'password' => 'wrong'], false)->getStatusCode());
    }

    public function testCrudEndpointsAnd404(): void
    {
        $body = ['external_code' => 'api-test', 'name' => 'API товар', 'price' => '100', 'purchase_price' => '50', 'attributes' => ['Цвет' => 'Белый']];
        $created = $this->request('POST', '/api/products', $body);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = json_decode((string) $created->getBody(), true)['product']['id'];
        self::assertSame(200, $this->request('GET', '/api/products/' . $id)->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/api/products/' . $id, ['name' => 'Изменён'] + $body)->getStatusCode());
        self::assertSame(204, $this->request('DELETE', '/api/products/' . $id)->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/products/' . $id)->getStatusCode());
    }

    public function testInvalidProductAndMissingUploadRejected(): void
    {
        self::assertSame(422, $this->request('POST', '/api/products', ['name' => 'Incomplete'])->getStatusCode());
        self::assertSame(422, $this->request('POST', '/api/imports')->getStatusCode());
    }

    public function testImportOwnershipIsEnforced(): void
    {
        $db = $this->database->connection();
        $db->insert('users', ['email' => 'other@example.com', 'password_hash' => 'not-used']);
        $id = $this->jobs->create(2, 'private.xlsx', '/private/file.xlsx');
        self::assertSame(404, $this->request('GET', '/api/imports/' . $id)->getStatusCode());
    }

    public function testRateLimitIsPersistentAndReturns429(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(422, $this->request('POST', '/api/imports')->getStatusCode());
        }
        self::assertSame(429, $this->request('POST', '/api/imports')->getStatusCode());
        $limits = new RateLimitRepository($this->database->connection());
        self::assertTrue($limits->consume('independent', 1, 60));
        self::assertFalse($limits->consume('independent', 1, 60));
    }

    private function resetCatalog(): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('DELETE', '/api/imports', ['confirm' => 'CLEAR_CATALOG']);
    }

    public function testImportReportEndpointsArePagedAndOwnerProtected(): void
    {
        $id = $this->jobs->create(1, 'errors.xlsx', 'unused');
        $this->jobs->record($id, 2, 'invalid', [['severity' => 'error', 'message' => 'Invalid row']]);
        self::assertSame(200, $this->request('GET', '/api/imports/' . $id . '/errors?page=1&limit=100')->getStatusCode());
        self::assertSame(422, $this->request('GET', '/api/imports/' . $id . '/errors?limit=101')->getStatusCode());
        $report = $this->request('GET', '/api/imports/' . $id . '/report');
        self::assertSame(200, $report->getStatusCode());
        self::assertCount(1, json_decode((string) $report->getBody(), true)['errors']);
        self::assertSame(401, $this->request('GET', '/api/imports/' . $id . '/report', auth: false)->getStatusCode());
        $other = $this->database->connection();
        $other->insert('users', ['email' => 'report-owner@example.com', 'password_hash' => 'unused']);
        $foreign = $this->jobs->create(2, 'private.xlsx', 'unused');
        self::assertSame(404, $this->request('GET', '/api/imports/' . $foreign . '/report')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/imports/' . $foreign . '/errors')->getStatusCode());
    }

    public function testResetRequiresAuthAndExplicitConfirmation(): void
    {
        $this->products->save(ProductData::fromArray(['external_code' => 'keep', 'name' => 'Keep', 'price' => '100', 'purchase_price' => '50']), []);
        self::assertSame(401, $this->request('DELETE', '/api/imports', auth: false)->getStatusCode());
        self::assertSame(422, $this->request('DELETE', '/api/imports')->getStatusCode());
        self::assertSame(422, $this->request('DELETE', '/api/imports', ['confirm' => 'wrong'])->getStatusCode());
        self::assertSame(1, $this->products->paginate([])['total']);
    }

    public function testResetDeletesCatalogAndOwnHistoryButKeepsOtherHistory(): void
    {
        $db = $this->database->connection();
        $db->insert('users', ['email' => 'other@example.com', 'password_hash' => 'unused']);
        $own = $this->jobs->create(1, 'own.xlsx', '/outside/own.xlsx');
        $other = $this->jobs->create(2, 'other.xlsx', '/outside/other.xlsx');
        $this->jobs->record($own, 2, 'created', []);
        $this->jobs->record($other, 2, 'created', []);
        $this->jobs->finish($own);
        $this->jobs->finish($other);
        $this->products->save(ProductData::fromArray(['external_code' => 'reset', 'name' => 'Reset', 'price' => '100', 'purchase_price' => '50', 'attributes' => ['Цвет' => 'Белый']]), [['url' => 'https://example.com/a.jpg', 'path' => '/media/keep.jpg']]);
        $response = $this->resetCatalog();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['deleted' => 1, 'deleted_products' => 1, 'deleted_images' => 1, 'cleanup_failed' => 0], json_decode((string) $response->getBody(), true));
        self::assertSame(404, $this->request('GET', '/api/imports/' . $own)->getStatusCode());
        self::assertSame($other, $this->jobs->get($other, 2)['id']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM import_rows'));
        foreach (['products', 'product_attributes', 'product_images'] as $table) {
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
        self::assertSame(['deleted' => 0, 'deleted_products' => 0, 'deleted_images' => 0, 'cleanup_failed' => 0], json_decode((string) $this->resetCatalog()->getBody(), true));
    }

    public function testResetRejectsOwnAndOtherActiveJobsAtomically(): void
    {
        $db = $this->database->connection();
        $db->insert('users', ['email' => 'other@example.com', 'password_hash' => 'unused']);
        $done = $this->jobs->create(1, 'done.xlsx', '/outside/done.xlsx');
        $this->jobs->record($done, 2, 'created', []);
        $this->jobs->finish($done);
        $this->products->save(ProductData::fromArray(['external_code' => 'protected', 'name' => 'Protected', 'price' => '100', 'purchase_price' => '50']), []);
        foreach ([1, 2] as $owner) {
            $active = $this->jobs->create($owner, 'active.xlsx', '/outside/active.xlsx');
            foreach (['queued', 'processing'] as $status) {
                $db->update('import_jobs', ['status' => $status], ['id' => $active]);
                self::assertSame(409, $this->resetCatalog()->getStatusCode());
                self::assertSame(1, $this->products->paginate([])['total']);
                self::assertSame($done, $this->jobs->get($done, 1)['id']);
                self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM import_rows'));
            }
            $this->jobs->finish($active);
        }
    }

    public function testResetWorksWithEmptyHistoryAndAllowsReimport(): void
    {
        $data = ProductData::fromArray(['external_code' => 'orphan', 'name' => 'Old history already cleared', 'price' => '100', 'purchase_price' => '50']);
        $this->products->save($data, []);
        self::assertSame([], $this->jobs->recent(1));
        $response = $this->resetCatalog();
        self::assertSame(1, json_decode((string) $response->getBody(), true)['deleted_products']);
        self::assertSame(0, $this->products->paginate([])['total']);
        self::assertTrue($this->products->save($data, [])['created']);
    }

    public function testResetRemovesOnlyManagedUploadsAndReferencedMedia(): void
    {
        mkdir($this->temp . '/uploads');
        $upload = $this->temp . '/uploads/' . str_repeat('a', 32) . '.xlsx';
        $outside = $this->temp . '/keep.xlsx';
        $mediaPath = '/media/' . str_repeat('b', 64) . '.jpg';
        foreach ([$upload, $outside, $this->temp . $mediaPath] as $file) {
            file_put_contents($file, 'test');
        }
        foreach ([$upload, $outside] as $file) {
            $this->jobs->finish($this->jobs->create(1, 'file.xlsx', $file));
        }
        $this->products->save(ProductData::fromArray(['external_code' => 'photo', 'name' => 'Photo', 'price' => '100', 'purchase_price' => '50']), [['url' => 'https://example.com/a.jpg', 'path' => $mediaPath]]);
        self::assertSame(200, $this->resetCatalog()->getStatusCode());
        self::assertFileDoesNotExist($upload);
        self::assertFileDoesNotExist($this->temp . $mediaPath);
        self::assertFileExists($outside);
    }

    public function testResetDatabaseFailureRollsBackHistoryAndRelations(): void
    {
        $id = $this->jobs->create(1, 'old.xlsx', '/outside/file.xlsx');
        $this->jobs->record($id, 2, 'created', []);
        $this->jobs->finish($id);
        $this->products->save(ProductData::fromArray(['external_code' => 'rollback', 'name' => 'Rollback', 'price' => '100', 'purchase_price' => '50', 'attributes' => ['Цвет' => 'Синий']]), []);
        $db = $this->database->connection();
        $db->executeStatement("CREATE TRIGGER refuse_reset BEFORE DELETE ON products BEGIN SELECT RAISE(ABORT, 'reset failure'); END");
        $service = (new Runtime($this->database))->catalogReset;
        try {
            $service->clear(1);
            self::fail('Expected reset failure');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertCount(1, $this->jobs->recent(1));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM import_rows'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM products'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM product_attributes'));
        }
    }
}
