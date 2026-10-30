<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

final class ImageStorage
{
    public function save(string $body): string
    {
        if (strlen($body) > 5 * 1024 * 1024) {
            throw new \RuntimeException('Изображение превышает размер 5 МБ.');
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$type] ?? null;
        if ($extension === null || @getimagesizefromstring($body) === false) {
            throw new \RuntimeException('Ссылка не содержит поддерживаемое изображение.');
        }
        $directory = Config::storage() . '/media';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Каталог изображений недоступен для записи.');
        }
        $path = '/media/' . hash('sha256', $body) . '.' . $extension;
        if (!is_file(Config::storage() . $path)) {
            $temp = tempnam($directory, 'download-');
            if ($temp === false) {
                throw new \RuntimeException('Не удалось создать временный файл изображения.');
            }
            try {
                if (file_put_contents($temp, $body) === false || !rename($temp, Config::storage() . $path)) {
                    throw new \RuntimeException('Не удалось сохранить изображение.');
                }
                chmod(Config::storage() . $path, 0644);
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
        return $path;
    }
}
