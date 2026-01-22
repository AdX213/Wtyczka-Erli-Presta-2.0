<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliOrderApi.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Mapper/OrderMapper.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/OrderLinkRepository.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/LogRepository.php';

class OrderSync
{
    /**
     * Pobiera zdarzenia z ERLI /inbox i tworzy TYLKO nowe zamówienia.
     * Pobiera WSZYSTKIE paczki (batch'e), nie tylko 100.
     *
     * @param int $limit      ile eventów na batch (max 100)
     * @param int $maxBatches ile batchy maksymalnie (ochrona przed timeoutem)
     */
    public function processInbox($limit = 100, $maxBatches = 30)
    {
        $orderApi = new ErliOrderApi();
        $logRepo  = new LogRepository();
        $linkRepo = new OrderLinkRepository();

        $limit = (int) $limit;
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;

        $maxBatches = (int) $maxBatches;
        if ($maxBatches < 1) $maxBatches = 1;

        $stats = [
            'batches' => 0,
            'events' => 0,
            'created' => 0,
            'ignored' => 0,
            'exceptions'=> 0,
            'acked' => 0,
        ];

        $lastBatchWasFull = false;

        for ($batch = 1; $batch <= $maxBatches; $batch++) {
            $stats['batches']++;
            // GET INBOX z retry (429)
            $response = null;
            $tries = 0;

            while (true) {
                $tries++;
                $response = $orderApi->getInbox($limit);

                if (!is_array($response)) {
                    $logRepo->addLog(
                        'order_inbox_error',
                        '',
                        'Błędna odpowiedź getInbox (nie jest tablicą).',
                        print_r($response, true)
                    );
                    return $stats;
                }

                $code = (int) ($response['code'] ?? 0);

                // rate limit
                if ($code === 429) {
                    if ($tries >= 5) {
                        $logRepo->addLog(
                            'order_inbox_error',
                            '',
                            'HTTP 429 z getInbox - przekroczono liczbę retry.',
                            $response['raw'] ?? ''
                        );
                        return $stats;
                    }

                    sleep(min(2 * $tries, 8));
                    continue;
                }

                if ($code < 200 || $code >= 300) {
                    $logRepo->addLog(
                        'order_inbox_error',
                        '',
                        'Błąd pobierania inbox: HTTP ' . $code,
                        $response['raw'] ?? ''
                    );
                    return $stats;
                }

                break; // OK
            }

            $body = $response['body'] ?? null;

            // pusto -> koniec
            if (!is_array($body) || empty($body)) {
                if ($batch === 1) {
                    $logRepo->addLog(
                        'order_inbox_empty',
                        '',
                        'Inbox pusty (brak nowych wiadomości).',
                        $response['raw'] ?? ''
                    );
                }
                return $stats;
            }

            $stats['events'] += count($body);
            $lastBatchWasFull = (count($body) >= $limit);

            // ID do ACK = MAX z batcha
            $ackId = null;

            foreach ($body as $event) {
                if (!is_array($event)) {
                    continue;
                }

                // wybieramy największe ID (bezpiecznie)
                if (isset($event['id'])) {
                    $id = $event['id'];

                    if ($ackId === null) {
                        $ackId = $id;
                    } else {
                        if (ctype_digit((string)$id) && ctype_digit((string)$ackId)) {
                            $ackId = ((int)$id > (int)$ackId) ? $id : $ackId;
                        } else {
                            // jeśli ID jest stringiem, bierzemy "ostatnie widziane"
                            $ackId = $id;
                        }
                    }
                }

                $type    = isset($event['type']) ? (string)$event['type'] : '';
                $payload = (isset($event['payload']) && is_array($event['payload'])) ? $event['payload'] : [];

                try {
                    // NOWE ZAMÓWIENIE
                    if (in_array($type, ['orderCreated', 'ORDER_CREATED', 'newOrder'], true)) {
                        $before = $stats['created'];

                        $this->handleOrderCreated($orderApi, $linkRepo, $logRepo, $payload, $event);
                        $stats['created'] = $before + 1;
                        continue;
                    }

                    // ZMIANA STATUSU (jak nie ma zamówienia, to tworzysz)
                    if (in_array($type, ['orderStatusChanged', 'orderSellerStatusChanged'], true)) {
                        $this->handleStatusChanged($orderApi, $linkRepo, $logRepo, $payload, $event, $type);
                        continue;
                    }

                    // INNE EVENTY
                    $stats['ignored']++;

                    $logRepo->addLog(
                        'order_event_ignored',
                        '',
                        'Pominięto event typu: ' . $type,
                        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                } catch (Throwable $e) {
                    $stats['exceptions']++;

                    $logRepo->addLog(
                        'order_event_exception',
                        '',
                        'Wyjątek podczas przetwarzania eventu: ' . $e->getMessage(),
                        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }
            }
            // ACK po batchu (z retry 429)
            if ($ackId !== null) {
                $triesAck = 0;

                while (true) {
                    $triesAck++;
                    $ackResp = $orderApi->ackInbox($ackId);

                    if (!is_array($ackResp)) {
                        $logRepo->addLog(
                            'order_ack_error',
                            (string)$ackId,
                            'Błędna odpowiedź ackInbox (nie jest tablicą).',
                            print_r($ackResp, true)
                        );
                        return $stats;
                    }

                    $ackCode = (int) ($ackResp['code'] ?? 0);

                    if ($ackCode === 429) {
                        if ($triesAck >= 5) {
                            $logRepo->addLog(
                                'order_ack_error',
                                (string)$ackId,
                                'HTTP 429 z ackInbox - przekroczono liczbę retry.',
                                $ackResp['raw'] ?? ''
                            );
                            return $stats;
                        }

                        sleep(min(2 * $triesAck, 8));
                        continue;
                    }

                    if ($ackCode < 200 || $ackCode >= 300) {
                        $logRepo->addLog(
                            'order_ack_error',
                            (string)$ackId,
                            'Błąd ACK inbox: HTTP ' . $ackCode,
                            $ackResp['raw'] ?? ''
                        );
                        return $stats;
                    }

                    $stats['acked']++;
                    break;
                }
            }

            // jeśli przyszło mniej niż limit -> raczej koniec
            if (count($body) < $limit) {
                return $stats;
            }

            // małe opóźnienie, żeby nie walić requestami non-stop
            usleep(120000);
        }

        // Jeśli dotarliśmy do maxBatches i cały czas było full -> informacja
        if ($lastBatchWasFull) {
            $logRepo->addLog(
                'order_inbox_limit_reached',
                '',
                'Osiągnięto maxBatches=' . (int)$maxBatches . ' przy limit=' . (int)$limit . ' (możliwe że są jeszcze eventy).',
                ''
            );
        }

        return $stats;
    }

    /* ======================== HANDLERY EVENTÓW ======================== */

    protected function handleOrderCreated(
        ErliOrderApi $orderApi,
        OrderLinkRepository $linkRepo,
        LogRepository $logRepo,
        array $payload,
        array $event
    ) {
        $erliOrderId = $payload['id'] ?? null;
        if (!$erliOrderId) {
            $logRepo->addLog(
                'order_event_no_id',
                '',
                'Brak payload.id dla eventu orderCreated',
                json_encode($event)
            );
            return;
        }

        $existing = $linkRepo->findByErliOrderId($erliOrderId);
        if ($existing) {
            $logRepo->addLog(
                'order_skipped_existing',
                $erliOrderId,
                'Zamówienie już istnieje w PrestaShop – pomijam event orderCreated.',
                ''
            );
            return;
        }

        $orderResp = $orderApi->getOrder($erliOrderId);
        if (!is_array($orderResp)) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błędna odpowiedź getOrder (nie jest tablicą).',
                print_r($orderResp, true)
            );
            return;
        }

        $orderCode = (int) ($orderResp['code'] ?? 0);
        if ($orderCode < 200 || $orderCode >= 300) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błąd pobierania zamówienia: HTTP ' . $orderCode,
                $orderResp['raw'] ?? ''
            );
            return;
        }

        $orderData = isset($orderResp['body']) && is_array($orderResp['body'])
            ? $orderResp['body']
            : [];

        $idOrder = $this->createOrderFromErliData($orderData);
        if ($idOrder) {
            $status = isset($orderData['status']) ? (string) $orderData['status'] : '';
            $linkRepo->save($idOrder, $erliOrderId, $status);

            $logRepo->addLog(
                'order_created',
                (string) $idOrder,
                'Zamówienie utworzone z Erli (orderCreated).',
                json_encode($orderData)
            );
        } else {
            $logRepo->addLog(
                'order_create_error',
                $erliOrderId,
                'Nie udało się utworzyć zamówienia w PrestaShop.',
                json_encode($orderData)
            );
        }
    }

    protected function handleStatusChanged(
        ErliOrderApi $orderApi,
        OrderLinkRepository $linkRepo,
        LogRepository $logRepo,
        array $payload,
        array $event,
        $eventType
    ) {
        $erliOrderId = $payload['id'] ?? null;
        if (!$erliOrderId) {
            $logRepo->addLog(
                'order_event_no_id',
                '',
                'Brak payload.id dla eventu ' . $eventType,
                json_encode($event)
            );
            return;
        }

        $existing = $linkRepo->findByErliOrderId($erliOrderId);
        if ($existing) {
            $logRepo->addLog(
                'order_status_ignored_existing',
                $erliOrderId,
                'Otrzymano event ' . $eventType . ' dla istniejącego zamówienia – pomijam.',
                json_encode($payload)
            );
            return;
        }

        $orderResp = $orderApi->getOrder($erliOrderId);
        if (!is_array($orderResp)) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błędna odpowiedź getOrder przy ' . $eventType . '.',
                print_r($orderResp, true)
            );
            return;
        }

        $orderCode = (int) ($orderResp['code'] ?? 0);
        if ($orderCode < 200 || $orderCode >= 300) {
            $logRepo->addLog(
                'order_fetch_error',
                $erliOrderId,
                'Błąd pobierania zamówienia przy ' . $eventType . ': HTTP ' . $orderCode,
                $orderResp['raw'] ?? ''
            );
            return;
        }

        $orderData = isset($orderResp['body']) && is_array($orderResp['body'])
            ? $orderResp['body']
            : [];

        $idOrder = $this->createOrderFromErliData($orderData);
        if (!$idOrder) {
            $logRepo->addLog(
                'order_create_error',
                $erliOrderId,
                'Nie udało się utworzyć zamówienia przy ' . $eventType . '.',
                json_encode($orderData)
            );
            return;
        }

        $status = isset($orderData['status']) ? (string) $orderData['status'] : '';
        $linkRepo->save($idOrder, $erliOrderId, $status);

        $logRepo->addLog(
            'order_created_from_status_event',
            (string) $idOrder,
            'Zamówienie utworzone z eventu ' . $eventType . ' (bo wcześniej nie istniało).',
            json_encode($orderData)
        );
    }

  /* ======================== TWORZENIE ZAMÓWIENIA ======================== */

    protected function createOrderFromErliData(array $orderData)
    {
        $logRepo = new LogRepository();
        $context = Context::getContext();
        // --- Klient ---
        $customer = OrderMapper::getOrCreateCustomer($orderData);
        // --- Adresy ---
        $shippingAddrData =
            $orderData['shippingAddress'] ??
            $orderData['deliveryAddress'] ??
            ($orderData['user']['deliveryAddress'] ?? []);

        $billingAddrData  =
            $orderData['billingAddress'] ??
            $orderData['invoiceAddress'] ??
            ($orderData['user']['invoiceAddress'] ?? $shippingAddrData);

        $deliveryAddress = OrderMapper::createAddress($customer, $shippingAddrData, 'ERLI Delivery');
        $invoiceAddress  = OrderMapper::createAddress($customer, $billingAddrData, 'ERLI Invoice');
        // --- Koszyk ---
        $cart = new Cart();
        $cart->id_lang             = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency         = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_customer         = (int) $customer->id;
        $cart->id_address_delivery = (int) $deliveryAddress->id;
        $cart->id_address_invoice  = (int) $invoiceAddress->id;
        $cart->id_carrier          = (int) Configuration::get('ERLI_DEFAULT_CARRIER');
        $cart->secure_key          = (string) $customer->secure_key;
        $cart->add();

        OrderMapper::fillCartWithProducts($cart, $orderData);

        /* ---------------------- MAPOWANIE STATUSU Z ERLI ----------------- */
        $erliStatus = isset($orderData['status']) ? (string) $orderData['status'] : '';
        $statusNorm = Tools::strtolower($erliStatus);

        $pendingStateConf   = (int) Configuration::get('ERLI_STATE_PENDING');
        $paidStateConf      = (int) Configuration::get('ERLI_STATE_PAID');
        $cancelledStateConf = (int) Configuration::get('ERLI_STATE_CANCELLED');
        $defaultStateConf   = (int) Configuration::get('ERLI_DEFAULT_ORDER_STATE');

        switch ($statusNorm) {
            case 'purchased':
            case 'paid':
            case 'completed':
                if ($paidStateConf > 0) {
                    $orderStatus = $paidStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_PAYMENT');
                }
                break;

            case 'pending':
            case 'new':
            case 'awaiting_payment':
                if ($pendingStateConf > 0) {
                    $orderStatus = $pendingStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_AWAITING_PAYMENT');
                }
                break;

            case 'cancelled':
            case 'canceled':
                if ($cancelledStateConf > 0) {
                    $orderStatus = $cancelledStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_CANCELED');
                }
                break;

            default:
                if ($defaultStateConf > 0) {
                    $orderStatus = $defaultStateConf;
                } else {
                    $orderStatus = (int) Configuration::get('PS_OS_PAYMENT');
                }
        }

        /* =====================================================================
         *  KWOTY Z ERLI (grosze)
         * ================================================================== */

        $erliTotalCents = null;

        if (isset($orderData['summary']['total'])) {
            $erliTotalCents = (int) $orderData['summary']['total'];
        } elseif (isset($orderData['summary']['totalToPay'])) {
            $erliTotalCents = (int) $orderData['summary']['totalToPay'];
        } elseif (isset($orderData['totalPrice'])) {
            $erliTotalCents = (int) $orderData['totalPrice'];
        }

        // suma produktów z Erli (grosze)
        $itemsTotalCents = null;
        $itemsTotalFound = false;

        if (!empty($orderData['items']) && is_array($orderData['items'])) {
            $sum = 0;
            foreach ($orderData['items'] as $item) {
                $qty = (int) ($item['quantity'] ?? 1);

                if (isset($item['totalPrice'])) {
                    $sum += (int) $item['totalPrice'];
                    $itemsTotalFound = true;
                } elseif (isset($item['price'])) {
                    $sum += (int) $item['price'] * $qty;
                    $itemsTotalFound = true;
                }
            }

            if ($itemsTotalFound) {
                $itemsTotalCents = $sum;
            }
        }

        // NADPŁATA = kwota zapłacona w ERLI - suma produktów => traktujemy jako koszt dostawy
        $shippingCents = null;
        if ($erliTotalCents !== null && $itemsTotalCents !== null) {
            $shippingCents = max(0, $erliTotalCents - $itemsTotalCents);
        }

        // --- wartości w zł ---
        $erliTotal     = $erliTotalCents   !== null ? $erliTotalCents   / 100.0 : null;
        $itemsTotal    = $itemsTotalCents  !== null ? $itemsTotalCents  / 100.0 : null;
        $shippingTotal = $shippingCents    !== null ? $shippingCents    / 100.0 : null;

        // suma z koszyka – tego użyjemy w validateOrder, żeby NIE było błędu płatności
        $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        if ($erliTotal !== null && abs($erliTotal - $cartTotal) > 0.01) {
            $logRepo->addLog(
                'order_total_mismatch',
                (string) ($orderData['id'] ?? ($orderData['orderId'] ?? '')),
                'Kwota z ERLI (' . $erliTotal . ') różni się od sumy koszyka (' . $cartTotal . '). ' .
                'Do validateOrder użyto kwoty z koszyka, a po utworzeniu zamówienia nadpiszemy total na ERLI.',
                json_encode($orderData)
            );
        }

        // to idzie do validateOrder – suma z koszyka, aby Prestashop nie oznaczył płatności jako błędnej
        $validateAmount = $cartTotal;

        $paymentMethodName = 'Erli Payment';

        $extraVars = [
            'transaction_id' => $orderData['id'] ?? ($orderData['orderId'] ?? ''),
        ];

        /** @var Module|false $module */
        $module = Module::getInstanceByName('erliintegration');

        if (!($module instanceof PaymentModule)) {
            $logRepo->addLog(
                'order_create_error',
                '',
                'Moduł erliintegration nie jest PaymentModule – nie można wywołać validateOrder().',
                ''
            );
            return null;
        }

        // --- tworzenie zamówienia ---
        $result = $module->validateOrder(
            (int) $cart->id,
            $orderStatus,
            $validateAmount,
            $paymentMethodName,
            null,
            $extraVars,
            (int) $cart->id_currency,
            false,
            $customer->secure_key,
            $context->shop
        );

        if (!$result || !$module->currentOrder) {
            $logRepo->addLog(
                'order_create_error',
                '',
                'validateOrder() zwróciło false.',
                json_encode($orderData)
            );
            return null;
        }

        $idOrder = (int) $module->currentOrder;

        /* =====================================================================
         *  NADPISANIE TOTALI I PŁATNOŚCI NA KWOTĘ Z ERLI
         * ================================================================== */

        // finalna kwota, którą chcemy widzieć w zamówieniu
        $finalPaid = $erliTotal !== null ? $erliTotal : $cartTotal;

        $order = new Order($idOrder);

        // suma zamówienia (to, co faktycznie zapłacił klient)
        $order->total_paid          = $finalPaid;
        $order->total_paid_tax_incl = $finalPaid;
        $order->total_paid_tax_excl = $finalPaid;
        $order->total_paid_real     = $finalPaid;

        // suma produktów
        if ($itemsTotal !== null) {
            $order->total_products    = $itemsTotal;
            $order->total_products_wt = $itemsTotal;
        }

        // NADPŁATA -> koszt dostawy
        if ($shippingTotal !== null) {
            $order->total_shipping          = $shippingTotal;
            $order->total_shipping_tax_incl = $shippingTotal;
            $order->total_shipping_tax_excl = $shippingTotal;
        }

        $order->update();

        // poprawiamy kwotę w płatności (ps_order_payment), żeby nie było ostrzeżenia
        try {
            $payments = $order->getOrderPaymentCollection();
            if ($payments && $payments->count()) {
                foreach ($payments as $payment) {
                    if (!Validate::isLoadedObject($payment)) {
                        continue;
                    }
                    $payment->amount = $finalPaid;
                    $payment->update();
                    break;
                }
            }
        } catch (Throwable $e) {
            $logRepo->addLog(
                'order_payment_update_error',
                (string) $idOrder,
                'Błąd przy aktualizacji ps_order_payment: ' . $e->getMessage(),
                ''
            );
        }

        // na wszelki wypadek wymuszamy stan zamówienia na ten zmapowany z ERLI
        if ($order->current_state != $orderStatus) {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($orderStatus, (int) $order->id);
            $history->add();
        }

        return $idOrder;
    }

    // na przyszłość – nieużywane w aktualnej logice
    protected function updateOrderStatus($idOrder, $erliStatus)
    {
        $erliStatus = (string) $erliStatus;
        if ($erliStatus === '') {
            return;
        }

        $map = [
            'pending'   => (int) Configuration::get('ERLI_STATE_PENDING'),
            'purchased' => (int) Configuration::get('ERLI_STATE_PAID'),
            'cancelled' => (int) Configuration::get('ERLI_STATE_CANCELLED'),
        ];

        if (!isset($map[$erliStatus])) {
            return;
        }

        $newState = (int) $map[$erliStatus];
        if ($newState <= 0) {
            return;
        }

        $history           = new OrderHistory();
        $history->id_order = (int) $idOrder;
        $history->changeIdOrderState($newState, $idOrder);
        $history->add();
    }
}
