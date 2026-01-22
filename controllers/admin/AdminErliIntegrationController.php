<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/LogRepository.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Sync/ProductSync.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Sync/OrderSync.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliApiClient.php';

class AdminErliIntegrationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'output' => '',
        ]);

        // Linki nawigacyjne + AJAX dla mapping.tpl
        $this->assignNavigationLinks();

        // POST z panelu modułu (zapis mapowania / itp.)
        $this->handlePostActionsAndRedirect();

        $view = (string) Tools::getValue('view', 'configure');

        switch ($view) {
            case 'dashboard':
                $this->renderDashboard();
                $this->setTemplate('dashboard.tpl');
                break;

            case 'mapping':
                $this->renderMapping();
                $this->setTemplate('mapping.tpl');
                break;

            case 'products':
                $this->renderProducts();
                $this->setTemplate('products.tpl');
                break;

            case 'orders':
                $this->renderOrders();
                $this->setTemplate('orders.tpl');
                break;

            case 'logs':
                $this->renderLogs();
                $this->setTemplate('logs.tpl');
                break;

            case 'configure':
            default:
                $this->renderConfigure();
                $this->setTemplate('configure.tpl');
                break;
        }
    }

    /**
     * AJAX:
     * index.php?controller=AdminErliIntegration&token=...&ajax=1&erli_action=...
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $action = (string) Tools::getValue('erli_action');

            // ====== ORDERS ======
            if ($action === 'import_orders') {
                $sync = new OrderSync();
                $sync->processInbox();
                die(json_encode(['success' => true, 'message' => 'Pobrano inbox z ERLI.']));
            }

            // ====== PRODUCTS ======
            if ($action === 'sync_all_products') {
                $sync = new ProductSync();
                $prepared = (int) $sync->prepareAllProducts();
                $synced   = (int) $sync->syncAllPending(20);

                die(json_encode([
                    'success' => true,
                    'message' => 'Przygotowano ' . $prepared . ' i zsynchronizowano ' . $synced . ' produktów.',
                ]));
            }

            if ($action === 'sync_product') {
                $idProduct = (int) Tools::getValue('id_product');
                if ($idProduct <= 0) {
                    die(json_encode(['success' => false, 'message' => 'Brak poprawnego id_product']));
                }

                $sync = new ProductSync();
                $httpCode = (int) $sync->syncSingle($idProduct);

                die(json_encode([
                    'success' => ($httpCode >= 200 && $httpCode < 300),
                    'message' => 'Wysłano produkt ID ' . $idProduct . ' (HTTP ' . $httpCode . ')',
                    'http'    => $httpCode,
                ]));
            }

            // ====== MAPPING: POBIERZ SŁOWNIK KATEGORII ERLI DO BAZY ======
            if ($action === 'fetch_erli_categories') {
                $result = $this->fetchErliCategoriesAndStoreAll(200); // pageSize=200

                $total = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_category_dictionary`'
                );

                die(json_encode([
                    'success' => true,
                    'message' => 'Pobrano i zapisano słownik kategorii ERLI.',
                    'fetched' => (int) $result['fetched'],
                    'pages'   => (int) $result['pages'],
                    'total_in_db' => $total,
                ]));
            }

            // ====== MAPPING: WYSZUKAJ KATEGORIE Z BAZY (autocomplete) ======
            if ($action === 'search_erli_categories') {
                $q = trim((string) Tools::getValue('q'));
                $limit = (int) Tools::getValue('limit', 30);
                $onlyLeaf = (int) Tools::getValue('leaf', 1);

                if ($limit < 5) $limit = 5;
                if ($limit > 100) $limit = 100;

                if (Tools::strlen($q) < 2) {
                    die(json_encode(['success' => true, 'count' => 0, 'items' => []]));
                }

                $where = [];
                if ($onlyLeaf) {
                    $where[] = 'leaf = 1';
                }

                $like = '%' . pSQL($q) . '%';
                $where[] = '(name LIKE "' . $like . '" OR breadcrumb_json LIKE "' . $like . '")';

                $sql = '
                    SELECT 
                        erli_id,
                        name,
                        leaf,
                        breadcrumb_json
                    FROM `' . _DB_PREFIX_ . 'erli_category_dictionary`
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY
                        (name LIKE "' . pSQL($q) . '%") DESC,
                        erli_id ASC
                    LIMIT ' . (int)$limit;

                $rows = Db::getInstance()->executeS($sql);

                $items = [];
                foreach ($rows ?: [] as $r) {
                    $items[] = [
                        'id' => (string) $r['erli_id'],
                        'name' => (string) $r['name'],
                        'leaf' => ((int) $r['leaf'] === 1),
                        'breadcrumb' => (string) ($r['breadcrumb_json'] ?? ''),
                    ];
                }

                die(json_encode([
                    'success' => true,
                    'count' => count($items),
                    'items' => $items,
                ]));
            }

            die(json_encode(['success' => false, 'message' => 'Nieznana akcja: ' . $action]));
        } catch (Throwable $e) {
            die(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    private function assignNavigationLinks()
    {
        $token = Tools::getAdminTokenLite('AdminErliIntegration');
        $base  = 'index.php?controller=AdminErliIntegration&token=' . $token;
        $ajaxBase = $base . '&ajax=1';

        $this->context->smarty->assign([
            'link_dashboard' => $base . '&view=dashboard',
            'link_products'  => $base . '&view=products',
            'link_orders'    => $base . '&view=orders',
            'link_logs'      => $base . '&view=logs',
            'link_configure' => $base . '&view=configure',
            'link_mapping'   => $base . '&view=mapping',

            // mapping.tpl używa tych endpointów:
            'link_ajax_fetch_erli_categories'  => $ajaxBase . '&erli_action=fetch_erli_categories',
            'link_ajax_search_erli_categories' => $ajaxBase . '&erli_action=search_erli_categories',
        ]);
    }

    private function msgOk($text)
    {
        if ($this->module && method_exists($this->module, 'displayConfirmation')) {
            return $this->module->displayConfirmation($text);
        }
        return '<div class="alert alert-success">' . Tools::safeOutput($text) . '</div>';
    }

    private function msgErr($text)
    {
        if ($this->module && method_exists($this->module, 'displayError')) {
            return $this->module->displayError($text);
        }
        return '<div class="alert alert-danger">' . Tools::safeOutput($text) . '</div>';
    }

    private function handlePostActionsAndRedirect()
    {
        // flash
        $flash = (string) $this->context->cookie->__get('erli_flash');
        if ($flash !== '') {
            $this->context->smarty->assign(['output' => $flash]);
            $this->context->cookie->__set('erli_flash', '');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $messageHtml = '';
        $redirectView = (string) Tools::getValue('view', 'configure');

        try {
            if (Tools::isSubmit('submitErliSaveCategoryMapping')) {
                $this->saveCategoryMapping();
                $messageHtml = $this->msgOk('Zapisano mapowanie kategorii.');
                $redirectView = 'mapping';
            }
        } catch (Throwable $e) {
            $messageHtml = $this->msgErr($e->getMessage());
        }

        if ($messageHtml !== '') {
            $this->context->cookie->__set('erli_flash', $messageHtml);
        }

        $token = Tools::getAdminTokenLite('AdminErliIntegration');
        Tools::redirectAdmin(
            'index.php?controller=AdminErliIntegration&token=' . $token . '&view=' . urlencode($redirectView)
        );
    }

    /**
     * ZAPIS MAPOWANIA:
     * - zapis do ps_erli_category_map
     * - działa niezależnie od tego czy masz UNIQUE na id_category (robimy update/insert)
     */
    private function saveCategoryMapping()
    {
        $data = Tools::getValue('category');
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $idCategory => $row) {
            $idCategory = (int) $idCategory;
            if ($idCategory <= 0 || !is_array($row)) {
                continue;
            }

            $erliId   = trim((string) ($row['erli_category_id'] ?? ''));
            $erliName = trim((string) ($row['erli_category_name'] ?? ''));

            // Bez ID ERLI: usuń mapowanie (jeśli istnieje)
            if ($erliId === '') {
                Db::getInstance()->delete('erli_category_map', 'id_category=' . (int)$idCategory);
                continue;
            }

            // Sprawdź czy rekord istnieje
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_category_map` WHERE id_category=' . (int)$idCategory
            );

            if ($exists > 0) {
                // UPDATE
                Db::getInstance()->update(
                    'erli_category_map',
                    [
                        'erli_category_id' => pSQL($erliId),
                        'erli_category_name' => pSQL($erliName),
                    ],
                    'id_category=' . (int)$idCategory
                );
            } else {
                // INSERT
                Db::getInstance()->insert(
                    'erli_category_map',
                    [
                        'id_category' => (int)$idCategory,
                        'erli_category_id' => pSQL($erliId),
                        'erli_category_name' => pSQL($erliName),
                    ]
                );
            }
        }
    }

    private function fetchErliCategoriesAndStoreAll($pageSize = 200)
    {
        $apiKey = (string) Configuration::get('ERLI_API_KEY');
        if (trim($apiKey) === '') {
            throw new Exception('Brak ustawionego ERLI_API_KEY.');
        }

        $client = new ErliApiClient($apiKey);

        $after = null;
        $pages = 0;
        $fetched = 0;

        while (true) {
            $payload = ['limit' => (int) $pageSize];
            if ($after !== null && (int)$after > 0) {
                $payload['after'] = (int) $after;
            }

            $resp = $client->post('/dictionaries/category/_search', $payload);
            $code = (int) ($resp['code'] ?? 0);

            if ($code === 429) {
                sleep(2);
                continue;
            }

            if ($code < 200 || $code >= 300) {
                $raw = (string) ($resp['raw'] ?? '');
                throw new Exception('Błąd pobierania kategorii ERLI. HTTP ' . $code . ' ' . $raw);
            }

            $body = $resp['body'] ?? null;
            if (!is_array($body) || empty($body)) {
                break;
            }

            $pages++;

            foreach ($body as $c) {
                if (!is_array($c) || !isset($c['id'])) {
                    continue;
                }

                $id = (int) $c['id'];
                $name = (string) ($c['name'] ?? '');
                $leaf = !empty($c['leaf']) ? 1 : 0;

                $crumb = '';
                if (!empty($c['breadcrumb']) && is_array($c['breadcrumb'])) {
                    $parts = [];
                    foreach ($c['breadcrumb'] as $b) {
                        if (is_array($b) && isset($b['name'])) {
                            $parts[] = (string)$b['name'];
                        }
                    }
                    $crumb = implode(' > ', $parts);
                }

                $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'erli_category_dictionary`
                    (`erli_id`, `name`, `leaf`, `breadcrumb_json`)
                VALUES
                    (' . (int)$id . ', "' . pSQL($name) . '", ' . (int)$leaf . ', "' . pSQL($crumb) . '")
                ON DUPLICATE KEY UPDATE
                    `name`=VALUES(`name`),
                    `leaf`=VALUES(`leaf`),
                    `breadcrumb_json`=VALUES(`breadcrumb_json`)';

                Db::getInstance()->execute($sql);
                $fetched++;
            }

            $last = end($body);
            $lastId = (is_array($last) && isset($last['id'])) ? (int) $last['id'] : null;
            if (!$lastId) {
                break;
            }

            $after = $lastId;

            if (count($body) < (int) $pageSize) {
                break;
            }
        }

        return ['pages' => $pages, 'fetched' => $fetched];
    }

    // ===================== RENDERERS =====================

    protected function renderConfigure()
    {
        $module = Module::getInstanceByName('erliintegration');

        $formHtml = '';
        if ($module && method_exists($module, 'renderForm')) {
            $formHtml = $module->renderForm();
        }

        $cronToken = (string) Configuration::get('ERLI_CRON_TOKEN');
        $cronUrl   = $this->context->link->getModuleLink('erliintegration', 'cron', ['token' => $cronToken]);

        $this->context->smarty->assign([
            'form_html' => $formHtml,
            'cron_url'  => $cronUrl,
        ]);
    }

    protected function renderDashboard()
    {
        $logRepo = new LogRepository();

        $totalProducts  = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`');
        $syncedProducts = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_product_link`');

        $totalOrders = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`');
        $erliOrders  = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_order_link`');

        $lastSync = Db::getInstance()->getValue(
            'SELECT MAX(`last_synced_at`) FROM `' . _DB_PREFIX_ . 'erli_product_link`'
        );

        $this->context->smarty->assign([
            'total_products'  => $totalProducts,
            'synced_products' => $syncedProducts,
            'total_orders'    => $totalOrders,
            'erli_orders'     => $erliOrders,
            'last_sync'       => $lastSync,
            'last_logs'       => $logRepo->getLastLogs(20),
        ]);
    }

    protected function renderProducts()
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            'SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                sa.quantity,
                i.id_image,
                epl.external_id,
                epl.last_synced_at,
                epl.last_error
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON (sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON (ish.id_product = p.id_product AND ish.cover = 1 AND ish.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON (i.id_image = ish.id_image)
             LEFT JOIN `' . _DB_PREFIX_ . 'erli_product_link` epl
                ON (epl.id_product = p.id_product)
             ORDER BY p.id_product DESC
             LIMIT 200'
        );

        $products = [];
        foreach ($rows ?: [] as $r) {
            $imgUrl = '';
            if (!empty($r['id_image'])) {
                $imgUrl = $this->context->link->getImageLink(
                    (string) $r['link_rewrite'],
                    (int) $r['id_image'],
                    ImageType::getFormattedName('small_default')
                );
            }

            $priceGross = (float) Product::getPriceStatic((int) $r['id_product'], true);

            $products[] = [
                'id_product'     => (int) $r['id_product'],
                'name'           => (string) $r['name'],
                'price'          => $priceGross,
                'quantity'       => (int) ($r['quantity'] ?? 0),
                'image'          => $imgUrl,
                'external_id'    => (string) ($r['external_id'] ?? ''),
                'last_synced_at' => (string) ($r['last_synced_at'] ?? ''),
                'last_error'     => (string) ($r['last_error'] ?? ''),
            ];
        }

        $this->context->smarty->assign([
            'products' => $products,
        ]);
    }

    protected function renderOrders()
    {
        $adminOrdersToken = Tools::getAdminTokenLite('AdminOrders');

        $orders = Db::getInstance()->executeS(
            'SELECT
                eol.id_order,
                eol.erli_order_id,
                eol.last_status,
                eol.created_at
             FROM `' . _DB_PREFIX_ . 'erli_order_link` eol
             ORDER BY eol.id_erli_order_link DESC
             LIMIT 200'
        );

        $this->context->smarty->assign([
            'orders' => $orders ?: [],
            'admin_orders_token' => $adminOrdersToken,
        ]);
    }

    /**
     * RENDER MAPOWANIA:
     * - ładuje wszystkie kategorie Prestashop (id_category > 1)
     * - dołącza zapisane mapowania z ps_erli_category_map
     * - buduje category_rows dla mapping.tpl
     */
    protected function renderMapping()
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $categories = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
               ON (cl.id_category = c.id_category AND cl.id_lang=' . (int)$idLang . ' AND cl.id_shop=' . (int)$idShop . ')
             WHERE c.id_category > 1
             ORDER BY c.id_category ASC'
        );

        $mapped = Db::getInstance()->executeS(
            'SELECT id_category, erli_category_id, erli_category_name
             FROM `' . _DB_PREFIX_ . 'erli_category_map`'
        );

        $map = [];
        foreach ($mapped ?: [] as $m) {
            $map[(int)$m['id_category']] = [
                'erli_category_id' => (string)$m['erli_category_id'],
                'erli_category_name' => (string)($m['erli_category_name'] ?? ''),
            ];
        }

        $rows = [];
        foreach ($categories ?: [] as $c) {
            $idCategory = (int)$c['id_category'];

            $rows[] = [
                'id_category' => $idCategory,
                'category_name' => (string)$c['name'],
                'erli_category_id' => isset($map[$idCategory]) ? $map[$idCategory]['erli_category_id'] : '',
                'erli_category_name' => isset($map[$idCategory]) ? $map[$idCategory]['erli_category_name'] : '',
            ];
        }

        $this->context->smarty->assign([
            'category_rows' => $rows,
        ]);
    }

    protected function renderLogs()
    {
        $logRepo = new LogRepository();
        $this->context->smarty->assign([
            'logs' => $logRepo->getLastLogs(200),
        ]);
    }
}
