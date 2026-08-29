<?php

namespace justinholtweb\erpypriority\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\BasicAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Priority Software, through its OData REST interface.
 *
 * Priority exposes its *forms* rather than an abstracted API, so the resource names here are the
 * screen names a Priority consultant would recognise — LOGPART, CUSTOMERS, ORDERS — and sub-forms
 * are reached with `$expand`, exactly as they are in the application.
 *
 * The awkward part is that a Priority installation is heavily customised by definition: forms are
 * added, fields are renamed, and two implementations rarely look alike. So every form name is a
 * setting here, and any field this connector reads can be corrected on Erpy's mapping screen with
 * a rule targeting the canonical field — no fork required.
 */
class PriorityConnector extends Connector
{
    public static function handle(): string
    {
        return 'priority';
    }

    public static function displayName(): string
    {
        return 'Priority';
    }

    public static function vendor(): string
    {
        return 'Priority Software';
    }

    public static function description(): string
    {
        return 'Priority ERP through its OData interface, with every form name configurable because no two Priority installations are alike.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://prioritysoftware.github.io/restapi/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // Priority exposes no dependable "last modified" column across its forms, so a sync
            // here is a full read every time. Erpy's content hashing is what keeps that cheap:
            // unchanged rows cost a comparison rather than an element save.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: false, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: false, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: 200)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100)
            ->withMultiCompany();
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('serverUrl', Craft::t('erpy', 'Priority server URL'), [
                'required' => true,
                'placeholder' => 'https://priority.example.com',
                'instructions' => Craft::t('erpy', 'The host only — Erpy adds the OData path.'),
            ]),
            Field::text('company', Craft::t('erpy', 'Company'), [
                'required' => true,
                'placeholder' => 'demo',
                'instructions' => Craft::t('erpy', 'The Priority company (database) name, as it appears in the OData URL.'),
            ]),
            Field::text('tabulaIni', Craft::t('erpy', 'Configuration file'), [
                'default' => 'tabula.ini',
                'instructions' => Craft::t('erpy', 'Almost always tabula.ini. Change it only if your installation says otherwise.'),
            ]),
            Field::text('username', Craft::t('erpy', 'Username'), ['required' => true]),
            Field::secret('password', Craft::t('erpy', 'Password'), ['required' => true]),

            Field::heading(
                Craft::t('erpy', 'Forms'),
                Craft::t('erpy', 'Priority exposes its screens rather than an abstracted API, and installations differ. These are the standard names; change any that your implementation renamed.'),
            ),
            Field::text('itemsForm', Craft::t('erpy', 'Items form'), ['default' => 'LOGPART']),
            Field::text('stockForm', Craft::t('erpy', 'Stock balance form'), ['default' => 'WARHSBAL']),
            Field::text('customersForm', Craft::t('erpy', 'Customers form'), ['default' => 'CUSTOMERS']),
            Field::text('ordersForm', Craft::t('erpy', 'Sales orders form'), ['default' => 'ORDERS']),
            Field::text('orderLinesForm', Craft::t('erpy', 'Order lines sub-form'), ['default' => 'ORDERITEMS_SUBFORM']),
            Field::text('invoicesForm', Craft::t('erpy', 'Invoices form'), ['default' => 'AINVOICES']),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('warehouse', Craft::t('erpy', 'Warehouse'), [
                'instructions' => Craft::t('erpy', 'Stock is read from this warehouse. Leave blank to read every warehouse.'),
            ]),
            Field::text('priceList', Craft::t('erpy', 'Price list'), [
                'instructions' => Craft::t('erpy', 'The price list new orders are raised against.'),
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new BasicAuth();
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf(
                '%s/odata/Priority/%s/%s',
                rtrim((string)$this->setting('serverUrl'), '/'),
                (string)$this->setting('tabulaIni', 'tabula.ini'),
                (string)$this->setting('company'),
            ))
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->setRateLimit(4)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $form = (string)$this->setting('itemsForm', 'LOGPART');
        $response = $this->transport()->get($form, ['$top' => 1, '$select' => 'PARTNAME']);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match ($response->status) {
                401 => [Craft::t('erpy', 'Check the username and password, and that the user is permitted to use the REST API at all — Priority controls that per user.')],
                404 => [Craft::t('erpy', 'Check the company name and the form name. A form the user cannot see is a 404 here, not a 403.')],
                default => [],
            });
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to Priority.'), [
            Craft::t('erpy', 'Company') => (string)$this->setting('company'),
            Craft::t('erpy', 'Items form') => $form,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('itemsForm', 'LOGPART'), $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['PARTNAME'] ?? ''),
                'name' => (string)($row['PARTDES'] ?? ''),
                // Priority archives a part rather than deleting it, and an archived part must not
                // reach a storefront.
                'enabled' => strtoupper((string)($row['PARTARC'] ?? 'N')) !== 'Y',
                'blocked' => strtoupper((string)($row['PARTARC'] ?? 'N')) === 'Y',
                'category' => $row['FAMILYNAME'] ?? null,
                'unitOfMeasure' => $row['UNITNAME'] ?? null,
                'price' => isset($row['PRICE']) ? (float)$row['PRICE'] : null,
                'barcode' => $row['BARCODE'] ?: null,
                'tracksInventory' => (string)($row['TYPE'] ?? 'P') !== 'S',
                'remoteId' => (string)($row['PARTNAME'] ?? ''),
                'remoteKey' => (string)($row['PARTNAME'] ?? ''),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $warehouse = (string)$this->setting('warehouse', '');
        $filters = $warehouse !== '' ? ["WARHSNAME eq '" . $this->escape($warehouse) . "'"] : [];

        return $this->page((string)$this->setting('stockForm', 'WARHSBAL'), $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)($row['PARTNAME'] ?? ''),
                'warehouse' => $row['WARHSNAME'] ?? null,
                'onHand' => (float)($row['BALANCE'] ?? 0),
                'allocated' => isset($row['RESERVED']) ? (float)$row['RESERVED'] : null,
                'remoteId' => (string)($row['PARTNAME'] ?? '') . '|' . (string)($row['WARHSNAME'] ?? ''),
                'raw' => $row,
            ]);
        }, extraFilters: $filters);
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('customersForm', 'CUSTOMERS'), $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            return new ErpCustomer([
                'code' => (string)($row['CUSTNAME'] ?? ''),
                'name' => (string)($row['CUSTDES'] ?? ''),
                'email' => $row['EMAIL'] ?: null,
                'phone' => $row['PHONE'] ?: null,
                'enabled' => strtoupper((string)($row['INACTIVE'] ?? 'N')) !== 'Y',
                'onHold' => strtoupper((string)($row['BLOCKED'] ?? 'N')) === 'Y',
                'currency' => $row['CURRENCY'] ?? null,
                'priceListCode' => $row['PRICELIST'] ?? null,
                'paymentTermsCode' => $row['PAYCODE'] ?? null,
                'taxId' => $row['VATNUM'] ?: null,
                // OBLIGO is Priority's credit limit; the word means "liability" and catches
                // everybody reading the schema for the first time.
                'creditLimit' => isset($row['OBLIGO']) ? (float)$row['OBLIGO'] : null,
                'balance' => isset($row['BALANCE']) ? (float)$row['BALANCE'] : null,
                'remoteId' => (string)($row['CUSTNAME'] ?? ''),
                'remoteKey' => (string)($row['CUSTNAME'] ?? ''),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('customersForm', 'CUSTOMERS'), $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row['CUSTNAME'] ?? ''),
                'currency' => (string)($row['CURRENCY'] ?: 'USD'),
                'creditLimit' => isset($row['OBLIGO']) ? (float)$row['OBLIGO'] : null,
                'balance' => (float)($row['BALANCE'] ?? 0),
                'onHold' => strtoupper((string)($row['BLOCKED'] ?? 'N')) === 'Y',
                'raw' => $row,
            ]);
        }, select: 'CUSTNAME,CURRENCY,OBLIGO,BALANCE,BLOCKED');
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('ordersForm', 'ORDERS'), $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)($row['ORDSTATUSDES'] ?? '');

            return new ErpOrderStatus([
                // REFERENCE is Priority's customer reference field, which is where the Commerce
                // order number went out.
                'orderNumber' => (string)($row['REFERENCE'] ?? ''),
                'status' => $status,
                'statusCode' => (string)($row['ORDSTATUS'] ?? ''),
                'isCancelled' => stripos($status, 'cancel') !== false,
                'isClosed' => stripos($status, 'closed') !== false || stripos($status, 'finish') !== false,
                'isShipped' => stripos($status, 'deliver') !== false || stripos($status, 'shipped') !== false,
                'remoteId' => (string)($row['ORDNAME'] ?? ''),
                'remoteKey' => (string)($row['ORDNAME'] ?? ''),
                'modifiedAt' => $this->date($row['UDATE'] ?? $row['CURDATE'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UDATE');
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('invoicesForm', 'AINVOICES'), $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['TOTPRICE'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['IVNUM'] ?? ''),
                'orderNumber' => (string)($row['REFERENCE'] ?? ''),
                'customerCode' => (string)($row['CUSTNAME'] ?? ''),
                'issuedAt' => $this->date($row['IVDATE'] ?? null),
                'dueAt' => $this->date($row['PAYDATE'] ?? null),
                'currency' => (string)($row['CURRENCY'] ?: 'USD'),
                'subtotal' => (float)($row['QPRICE'] ?? 0),
                'taxTotal' => (float)($row['VAT'] ?? 0),
                'total' => $total,
                'balance' => $total,
                'isCreditNote' => $total < 0,
                'remoteId' => (string)($row['IVNUM'] ?? ''),
                'modifiedAt' => $this->date($row['UDATE'] ?? $row['IVDATE'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UDATE');
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Priority needs a customer number. Set a guest customer code on the order mapping, or link this customer to a Priority account.'));
        }

        $ordersForm = (string)$this->setting('ordersForm', 'ORDERS');
        $linesForm = (string)$this->setting('orderLinesForm', 'ORDERITEMS_SUBFORM');

        $existing = $this->query($ordersForm, [
            '$filter' => "REFERENCE eq '" . $this->escape($document->orderNumber) . "'",
            '$select' => 'ORDNAME,REFERENCE',
            '$top' => 1,
        ]);

        if ($existing !== [] && $remoteId === null) {
            return PushResult::alreadyExists((string)$existing[0]['ORDNAME'], (string)$existing[0]['ORDNAME']);
        }

        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = array_filter([
                'PARTNAME' => $line->sku,
                'TQUANT' => $line->quantity,
                'PRICE' => $line->unitPrice,
                'PERCENT' => $line->discountPercent,
                'REMARK1' => $line->notes ? mb_substr(implode('; ', $line->notes), 0, 48) : null,
            ], static fn($value) => $value !== null && $value !== '');
        }

        // Priority takes the header and its sub-form in one request, which is the one place it is
        // kinder than the ERPs that make you post lines separately and leave orphaned headers
        // behind when a line is refused.
        $payload = array_filter([
            'CUSTNAME' => $document->customerCode,
            'REFERENCE' => mb_substr($document->orderNumber, 0, 32),
            'CURDATE' => ($document->orderedAt ?? new DateTime())->format('Y-m-d\TH:i:s\Z'),
            'CODE' => $document->currency,
            'PRICELIST' => $this->setting('priceList') ?: null,
            'DETAILS' => $document->customerNote ? mb_substr($document->customerNote, 0, 48) : null,
            $linesForm => $lines,
        ], static fn($value) => $value !== null && $value !== '');

        foreach ($document->customFields as $fieldName => $value) {
            $payload[$fieldName] = $value;
        }

        $response = $this->transport()->post($ordersForm, $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        $number = (string)$response->at('ORDNAME', '');

        return $number !== ''
            ? PushResult::ok($number, $number, $response->json_())
            : PushResult::failed(Craft::t('erpy', 'Priority accepted the order but returned no order number.'));
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(
        string $form,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        ?string $deltaField = null,
        ?string $select = null,
        array $extraFilters = [],
    ): Page {
        if ($criteria->cursor !== null && str_starts_with($criteria->cursor, 'http')) {
            $response = $this->transport()->get($criteria->cursor);
            $skip = null;
        } else {
            $limit = $this->pageSize($entity, $criteria);
            $skip = (int)($criteria->cursor ?? 0);
            $query = ['$top' => $limit, '$skip' => $skip];

            if ($select !== null) {
                $query['$select'] = $select;
            }

            $filters = $extraFilters;

            if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
                $filters[] = sprintf('%s ge %s', $deltaField, $criteria->since->format('Y-m-d\TH:i:s\Z'));
            }

            foreach ($criteria->filters as $field => $value) {
                $filters[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
            }

            if ($filters !== []) {
                $query['$filter'] = implode(' and ', $filters);
            }

            $response = $this->transport()->get($form, $query);
        }

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Priority refused to read %s: %s',
                $form,
                $response->errorMessage(),
            ));
        }

        $rows = (array)$response->at('value', []);
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        $next = $response->at('@odata.nextLink');

        if ($next !== null) {
            return new Page($items, (string)$next);
        }

        $limit = $this->pageSize($entity, $criteria);

        return new Page($items, ($skip !== null && count($rows) >= $limit) ? (string)($skip + $limit) : null);
    }

    private function query(string $form, array $query): array
    {
        $response = $this->transport()->get($form, $query);

        return $response->ok() ? (array)$response->at('value', []) : [];
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
