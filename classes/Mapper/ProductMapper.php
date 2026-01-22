<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Mapper/CategoryMapper.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Mapper/ShippingMapper.php';

class ProductMapper
{
    /**
     * Mapuje produkt (lub konkretną kombinację) PrestaShop na payload ERLI.
     *
     * @param Product $product
     * @param int     $idLang
     * @param int|null $idProductAttribute  ID kombinacji (wariantu). Jeśli null => produkt prosty.
     *
     * Uwaga (ERLI):
     * - price: int (grosze)
     * - images: wymagane
     * - dispatchTime: wymagane
     * - vat: NIE wysyłamy
     */
    public static function map(Product $product, $idLang, $idProductAttribute = null)
    {
        if (!Validate::isLoadedObject($product)) {
            throw new Exception('Nie znaleziono produktu o ID: ' . (int) $product->id);
        }

        $idLang  = (int) $idLang;
        $context = Context::getContext();
        $link    = $context->link;

        $idProduct = (int) $product->id;
        $idProductAttribute = ($idProductAttribute !== null) ? (int) $idProductAttribute : null;
        $isVariant = ($idProductAttribute !== null && $idProductAttribute > 0);

        /* ===================== NAME ===================== */
        $nameBase = '';
        if (isset($product->name) && is_array($product->name) && isset($product->name[$idLang])) {
            $nameBase = trim((string) $product->name[$idLang]);
        } elseif (!empty($product->name) && is_string($product->name)) {
            $nameBase = trim((string) $product->name);
        }

        if (mb_strlen($nameBase) < 3) {
            $ref  = trim((string) $product->reference);
            $nameBase = (mb_strlen($ref) >= 3) ? $ref : ('Produkt #' . (int) $product->id);
        }

        /* ===================== DESCRIPTION ===================== */
        $description = '';
        if (isset($product->description) && is_array($product->description) && isset($product->description[$idLang])) {
            $description = (string) $product->description[$idLang];
        } elseif (!empty($product->description) && is_string($product->description)) {
            $description = (string) $product->description;
        }

        /* ===================== VARIANT ATTRIBUTES + VARIANT GROUP ===================== */
        $externalAttributes = [];
        $variantGroup = null;

        if ($isVariant) {
            $groupIndexMap = self::getAttributeGroupIndexMap($idProduct, $idLang);
            $externalAttributes = self::getCombinationExternalAttributes($idProductAttribute, $idLang, $groupIndexMap);
            $variantGroup = self::buildExternalVariantGroup($idProduct, $groupIndexMap);
        }

        /* ===================== NAME (variant suffix) ===================== */
        $name = $nameBase;
        if ($isVariant && !empty($externalAttributes)) {
            $suffix = [];
            foreach ($externalAttributes as $a) {
                if (!empty($a['values']) && is_array($a['values'])) {
                    $suffix[] = (string) $a['values'][0];
                }
            }
            if (!empty($suffix)) {
                $name .= ' - ' . implode(' - ', $suffix);
            }
        }

        if (mb_strlen($name) < 3) {
            $name = $nameBase;
        }

        /* ===================== EAN & SKU (variant aware) ===================== */
        $ean = (string) $product->ean13;
        $sku = (string) $product->reference;

        if ($isVariant) {
            try {
                $comb = new Combination($idProductAttribute);
                if (Validate::isLoadedObject($comb)) {
                    if (!empty($comb->ean13)) {
                        $ean = (string) $comb->ean13;
                    }
                    if (!empty($comb->reference)) {
                        $sku = (string) $comb->reference;
                    }
                }
            } catch (Throwable $e) {
                // fallback: produkt
            }
        }

        /* ===================== IMAGES (required by ERLI) ===================== */
        $images = self::buildImages($product, $idLang, $idProductAttribute);
        if (empty($images)) {
            // ERLI waliduje images jako wymagane, więc lepiej jasno przerwać
            throw new Exception('Produkt nie ma żadnych obrazków – ERLI wymaga pola images. ID=' . (int)$idProduct);
        }

        /* ===================== STOCK / STATUS (variant aware) ===================== */
        $stock = (int) StockAvailable::getQuantityAvailableByProduct(
            $idProduct,
            $isVariant ? $idProductAttribute : 0
        );
        if ($stock < 0) {
            $stock = 0;
        }

        $status = ($product->active && $stock > 0) ? 'active' : 'inactive';

        /* ===================== PRICE (grosze, int) ===================== */
        $priceGross = (float) Product::getPriceStatic(
            $idProduct,
            true,
            $isVariant ? $idProductAttribute : null,
            6
        );
        $priceCents = (int) round($priceGross * 100);
        if ($priceCents < 0) {
            $priceCents = 0;
        }

        /* ===================== PACKAGING ===================== */
        $weightGrams = (int) round(((float) $product->weight) * 1000);
        if ($weightGrams <= 0) {
            $weightGrams = 1;
        }

        /* ===================== CATEGORIES (mapping) ===================== */
        $categories = CategoryMapper::mapProductCategories($product, $idLang);
        if (!is_array($categories)) {
            $categories = [];
        }

        /* ===================== SHIPPING TAGS ===================== */
        $shippingTags = ShippingMapper::mapTagsForProduct($product, $idLang);
        if (!is_array($shippingTags)) {
            $shippingTags = [];
        }

        $shippingTags = array_values(array_filter(array_map('trim', $shippingTags), function ($v) {
            return $v !== '';
        }));

        /* ===================== DISPATCH TIME ===================== */
        $dispatchPeriodDays = (int) Configuration::get('ERLI_DISPATCH_TIME_DAYS');
        if ($dispatchPeriodDays <= 0) {
            $dispatchPeriodDays = 1;
        }

        /* ===================== externalId placeholder ===================== */
        // finalny externalId i tak jest nadpisywany w ProductSync
        $externalId = (string) $idProduct;
        if ($isVariant) {
            $externalId = $idProduct . '-' . $idProductAttribute;
        }

        /* ===================== BUILD PAYLOAD ===================== */
        $payload = [
            'externalId'   => (string) $externalId,
            'status'       => $status,
            'name'         => $name,
            'description'  => $description,
            'price'        => $priceCents,
            'stock'        => $stock,
            'ean'          => $ean,
            'sku'          => $sku,
            'dispatchTime' => [
                'period' => $dispatchPeriodDays,
            ],
            'packaging' => [
                'weight' => $weightGrams,
            ],
            'images' => $images,
        ];

        if (!empty($shippingTags)) {
            $payload['packaging']['tags'] = $shippingTags;
        }

        if (!empty($categories)) {
            // w dokumentacji ERLI występuje externalCategories (plural)
            $payload['externalCategories'] = $categories;
        }

        if ($isVariant) {
            if (!empty($externalAttributes)) {
                $payload['externalAttributes'] = $externalAttributes;
            }
            if (!empty($variantGroup)) {
                $payload['externalVariantGroup'] = $variantGroup;
            }
        }

        return $payload;
    }

    /**
     * Zwraca listę zdjęć - dla wariantu: zdjęcia przypisane do kombinacji, jeśli istnieją.
     * Zawsze zwraca URL-e absolutne.
     * Cache-buster stabilny (date_upd), żeby payload się nie zmieniał przy każdym syncu.
     */
    protected static function buildImages(Product $product, int $idLang, ?int $idProductAttribute): array
    {
        $context = Context::getContext();
        $link    = $context->link;

        $idProduct = (int) $product->id;

        $linkRewrite = '';
        if (isset($product->link_rewrite) && is_array($product->link_rewrite) && isset($product->link_rewrite[$idLang])) {
            $linkRewrite = (string) $product->link_rewrite[$idLang];
        } elseif (!empty($product->link_rewrite) && is_string($product->link_rewrite)) {
            $linkRewrite = (string) $product->link_rewrite;
        }

        $cacheVer = 0;
        if (!empty($product->date_upd)) {
            $cacheVer = (int) strtotime((string) $product->date_upd);
        }
        if ($cacheVer <= 0) {
            $cacheVer = (int) time();
        }

        $imageIds = [];

        // 1) wariant - bierzemy zdjęcia przypisane do kombinacji
        //    UWAGA: w zależności od wersji PS, getCombinationImages() potrafi zwrócić tablicę
        //    gdzie klucze NIE są id_image (np. 0,1,2...). Dlatego najpierw lecimy po SQL.
        if ($idProductAttribute !== null && $idProductAttribute > 0) {
            try {
                $rows = Db::getInstance()->executeS(
                    'SELECT pai.id_image
                     FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
                     INNER JOIN `' . _DB_PREFIX_ . 'image` i
                        ON i.id_image = pai.id_image
                     WHERE pai.id_product_attribute = ' . (int) $idProductAttribute . '
                     ORDER BY i.position ASC, pai.id_image ASC'
                );

                foreach ($rows ?: [] as $r) {
                    $iid = (int) ($r['id_image'] ?? 0);
                    if ($iid > 0) {
                        $imageIds[] = $iid;
                    }
                }
            } catch (Throwable $e) {
                // ignorujemy i próbujemy fallback niżej
            }

            // fallback jeśli SQL nic nie zwróciło, próbujemy Product::getCombinationImages()
            if (empty($imageIds)) {
                try {
                    $combImages = $product->getCombinationImages($idLang);
                    if (!empty($combImages[$idProductAttribute]) && is_array($combImages[$idProductAttribute])) {
                        foreach ($combImages[$idProductAttribute] as $k => $row) {
                            $iid = 0;
                            if (is_array($row) && isset($row['id_image'])) {
                                $iid = (int) $row['id_image'];
                            } else {
                                // czasem klucz bywa id_image, ale często jest to zwykły indeks 0..n
                                $iid = (int) $k;
                            }

                            if ($iid > 0) {
                                $imageIds[] = $iid;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // fallback niżej
                }
            }
        }

        // wyczyść duplikaty (zachowaj kolejność)
        if (!empty($imageIds)) {
            $imageIds = array_values(array_unique(array_filter($imageIds, function ($v) {
                return (int) $v > 0;
            })));
        }

        // 2) fallback: cover + reszta
        if (empty($imageIds)) {
            $cover = Image::getCover($idProduct);
            if ($cover && !empty($cover['id_image'])) {
                $imageIds[] = (int) $cover['id_image'];
            }

            $allImages = Image::getImages($idLang, $idProduct);
            foreach ($allImages as $img) {
                $idImg = (int) $img['id_image'];
                if (!in_array($idImg, $imageIds, true)) {
                    $imageIds[] = $idImg;
                }
            }
        }

        $images = [];
        foreach ($imageIds as $idImage) {
            $url = $link->getImageLink($linkRewrite, (int) $idImage, 'product_main');
            if (!$url) {
                continue;
            }

            if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
                $domain = Tools::getShopDomainSsl(true);
                $url    = rtrim($domain, '/') . '/' . ltrim($url, '/');
            }

            if (strpos($url, 'http://') === 0) {
                $url = 'https://' . substr($url, 7);
            }

            $url .= '?v=' . $cacheVer;

            $images[] = ['url' => $url];
        }

        return $images;
    }

    /**
     * Zwraca mapę id_attribute_group => meta(index, name, is_color_group)
     * Index musi być stabilny dla danego produktu.
     */
    protected static function getAttributeGroupIndexMap(int $idProduct, int $idLang): array
    {
        static $cache = [];

        $key = $idProduct . '|' . $idLang;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $sql = '
            SELECT DISTINCT
                ag.id_attribute_group,
                ag.is_color_group,
                ag.position,
                agl.name
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a
                ON a.id_attribute = pac.id_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag
                ON ag.id_attribute_group = a.id_attribute_group
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                ON agl.id_attribute_group = ag.id_attribute_group
               AND agl.id_lang = ' . (int) $idLang . '
            WHERE pa.id_product = ' . (int) $idProduct . '
            ORDER BY ag.position ASC, ag.id_attribute_group ASC
        ';

        $rows = Db::getInstance()->executeS($sql);

        $map = [];
        $idx = 0;

        foreach ($rows ?: [] as $r) {
            $gid   = (int) $r['id_attribute_group'];
            $gname = (string) $r['name'];

            $isColor = ((int) $r['is_color_group'] === 1);

            // fallback: nazwa grupy zawiera "kolor"
            $gnameLower = Tools::strtolower($gname);
            if (!$isColor && $gnameLower !== '' && strpos($gnameLower, 'kolor') !== false) {
                $isColor = true;
            }

            $map[$gid] = [
                'index' => $idx,
                'name'  => $gname,
                'is_color_group' => $isColor,
            ];

            $idx++;
        }

        $cache[$key] = $map;

        return $map;
    }

    /**
     * Buduje externalAttributes dla konkretnej kombinacji.
     */
    protected static function getCombinationExternalAttributes(int $idProductAttribute, int $idLang, array $groupIndexMap): array
    {
        if (empty($groupIndexMap)) {
            return [];
        }

        $sql = '
            SELECT
                a.id_attribute_group,
                al.name AS attribute_name
            FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a
                ON a.id_attribute = pac.id_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON al.id_attribute = a.id_attribute
               AND al.id_lang = ' . (int) $idLang . '
            WHERE pac.id_product_attribute = ' . (int) $idProductAttribute . '
        ';

        $rows = Db::getInstance()->executeS($sql);

        $tmp = [];

        foreach ($rows ?: [] as $r) {
            $gid = (int) $r['id_attribute_group'];
            $val = trim((string) $r['attribute_name']);

            if (!isset($groupIndexMap[$gid])) {
                continue;
            }

            $tmp[] = [
                'gid' => $gid,
                'value' => $val,
            ];
        }

        // sort po indeksie grupy
        usort($tmp, function ($a, $b) use ($groupIndexMap) {
            $ia = (int) $groupIndexMap[$a['gid']]['index'];
            $ib = (int) $groupIndexMap[$b['gid']]['index'];
            return $ia <=> $ib;
        });

        $out = [];
        foreach ($tmp as $row) {
            $gid = (int) $row['gid'];
            $out[] = [
                // domyślnie source=shop
                'source' => 'shop',
                'id'     => (string) $gid,
                'name'   => (string) $groupIndexMap[$gid]['name'],
                'type'   => 'string',
                'values' => [(string) $row['value']],
                'index'  => (int) $groupIndexMap[$gid]['index'],
            ];
        }

        return $out;
    }

    /**
     * Buduje externalVariantGroup:
     * - jeśli jest grupa koloru -> używamy "thumbnail" jako 1. element
     * - nie dodajemy indeksu koloru, bo kolor jest widoczny na zdjęciu
     */
    protected static function buildExternalVariantGroup(int $idProduct, array $groupIndexMap): ?array
    {
        if (empty($groupIndexMap)) {
            return null;
        }

        $colorIndexes = [];
        $allIndexes = [];

        foreach ($groupIndexMap as $meta) {
            $idx = (int) $meta['index'];
            $allIndexes[] = $idx;
            if (!empty($meta['is_color_group'])) {
                $colorIndexes[] = $idx;
            }
        }

        $attrs = [];

        if (!empty($colorIndexes)) {
            $attrs[] = 'thumbnail';
            foreach ($allIndexes as $idx) {
                if (!in_array($idx, $colorIndexes, true)) {
                    $attrs[] = $idx;
                }
            }
        } else {
            $attrs = $allIndexes;
        }

        return [
            'id' => (string) $idProduct,
            'source' => 'integration',
            'attributes' => array_values($attrs),
        ];
    }
}
