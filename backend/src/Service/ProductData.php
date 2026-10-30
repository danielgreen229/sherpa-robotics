<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\ApiException;

final class ProductData
{
    /** @param numeric-string $price
     *  @param numeric-string $purchasePrice
     *  @param numeric-string $discount
     */
    private function __construct(
        public readonly string $externalCode,
        public readonly string $name,
        public readonly string $description,
        public readonly string $price,
        public readonly string $purchasePrice,
        public readonly string $discount,
        /** @var array<string, string> */
        public readonly array $attributes,
        /** @var list<string> */
        public readonly array $imageUrls,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $code = self::text($input['external_code'] ?? '', 'external_code', 255);
        $name = self::text($input['name'] ?? '', 'name', 500);
        if ($code === '' || $name === '') {
            throw new ApiException('Внешний код и наименование обязательны.', 422);
        }
        $price = self::money($input['price'] ?? null, 'Цена продажи');
        $purchase = self::money($input['purchase_price'] ?? null, 'Закупочная цена');
        if (bccomp($price, '0', 2) <= 0) {
            throw new ApiException('Цена продажи должна быть больше нуля.', 422);
        }
        $discount = bcdiv(bcmul(bcsub($price, $purchase, 8), '100', 8), $price, 8);
        $discount = self::round($discount);
        if (bccomp($discount, '-999999999999.99', 2) < 0) {
            throw new ApiException('Рассчитанная скидка выходит за допустимый диапазон.', 422);
        }
        $attributes = [];
        if (!is_array($input['attributes'] ?? []) || count($input['attributes'] ?? []) > 195) {
            throw new ApiException('attributes должен быть объектом не более 195 характеристик.', 422);
        }
        foreach ($input['attributes'] ?? [] as $key => $value) {
            $key = self::text($key, 'Название характеристики', 255);
            $value = self::text($value, 'Значение характеристики', 20000);
            if ($key !== '' && $value !== '') {
                $attributes[$key] = $value;
            }
        }
        $urls = $input['image_urls'] ?? [];
        if (!is_array($urls) || count($urls) > 30) {
            throw new ApiException('Допускается массив не более 30 ссылок на изображения.', 422);
        }
        $urls = array_values(array_unique(array_filter(array_map(fn (mixed $v): string => self::text($v, 'Ссылка на изображение', 2048), $urls))));
        return new self($code, $name, self::text($input['description'] ?? '', 'Описание', 100000), $price, $purchase, $discount, $attributes, $urls);
    }

    /** @return numeric-string */
    public static function money(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ApiException($field . ': требуется число.', 422);
        }
        $normalized = str_replace(["\u{00A0}", "\u{202F}", ' ', ','], ['', '', '', '.'], trim((string) $value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $normalized) || !is_numeric($normalized)) {
            throw new ApiException($field . ': ожидается неотрицательная цена с точностью до копеек.', 422);
        }
        return bcadd($normalized, '0', 2);
    }

    /** @param numeric-string $number
     *  @return numeric-string
     */
    private static function round(string $number): string
    {
        return bcadd($number, str_starts_with($number, '-') ? '-0.005' : '0.005', 2);
    }

    private static function text(mixed $value, string $field, int $max): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new ApiException($field . ': ожидается строка.', 422);
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new ApiException($field . ': некорректная длина или символы.', 422);
        }
        return $value;
    }
}
