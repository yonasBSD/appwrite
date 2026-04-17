<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    private const ALLOWED_ATTRIBUTES = [
        'type' => Database::VAR_STRING,
        'locale' => Database::VAR_STRING,
        'subject' => Database::VAR_STRING,
        'message' => Database::VAR_STRING,
        'senderName' => Database::VAR_STRING,
        'senderEmail' => Database::VAR_STRING,
        'replyTo' => Database::VAR_STRING,
        'custom' => Database::VAR_BOOLEAN,
    ];

    public static function getName()
    {
        return 'listProjectEmailTemplates';
    }

    public function __construct()
    {
        $attributes = [];
        foreach (self::ALLOWED_ATTRIBUTES as $key => $type) {
            $attributes[] = new Document([
                'key' => $key,
                'type' => $type,
                'array' => false,
            ]);
        }

        $queriesValidator = new Queries([
            new Limit(),
            new Offset(),
            new Filter($attributes, Database::VAR_STRING, APP_DATABASE_QUERY_MAX_VALUES),
            new Order($attributes),
        ]);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/templates/email')
            ->desc('List project email templates')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'listEmailTemplates',
                description: <<<EOT
                Get a list of all email templates available for the project. Each entry represents a type and locale combination, with the `custom` flag indicating whether the template has been customized.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE_LIST,
                    )
                ]
            ))
            ->param('queries', [], $queriesValidator, 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter and order on the following attributes: ' . implode(', ', array_keys(self::ALLOWED_ATTRIBUTES)), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('project')
            ->inject('response')
            ->inject('localeCodes')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     * @param array<string> $localeCodes
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Document $project,
        Response $response,
        array $localeCodes,
    ) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        /** @var array<Query> $filters */
        $filters = $grouped['filters'] ?? [];
        /** @var array<string> $orderAttributes */
        $orderAttributes = $grouped['orderAttributes'] ?? [];
        /** @var array<string> $orderTypes */
        $orderTypes = $grouped['orderTypes'] ?? [];

        $types = Config::getParam('locale-templates')['email'] ?? [];
        $projectTemplates = $project->getAttribute('templates', []);

        $templates = [];
        foreach ($types as $type) {
            foreach ($localeCodes as $locale) {
                $key = 'email.' . $type . '-' . $locale;
                $stored = $projectTemplates[$key] ?? null;

                $templates[] = new Document([
                    'type' => $type,
                    'locale' => $locale,
                    'message' => $stored['message'] ?? '',
                    'subject' => $stored['subject'] ?? '',
                    'senderName' => $stored['senderName'] ?? '',
                    'senderEmail' => $stored['senderEmail'] ?? '',
                    'replyTo' => $stored['replyTo'] ?? '',
                    'custom' => !\is_null($stored),
                ]);
            }
        }

        $templates = $this->applyFilters($templates, $filters);
        $templates = $this->applyOrder($templates, $orderAttributes, $orderTypes);

        $total = $includeTotal ? \count($templates) : 0;
        $templates = \array_slice($templates, $offset, $limit);

        $response->dynamic(new Document([
            'templates' => $templates,
            'total' => $total,
        ]), Response::MODEL_EMAIL_TEMPLATE_LIST);
    }

    /**
     * @param array<Document> $templates
     * @param array<Query> $filters
     * @return array<Document>
     */
    private function applyFilters(array $templates, array $filters): array
    {
        if (empty($filters)) {
            return $templates;
        }

        return \array_values(\array_filter($templates, function (Document $template) use ($filters) {
            foreach ($filters as $filter) {
                if (!$this->matches($template, $filter)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function matches(Document $template, Query $filter): bool
    {
        $attribute = $filter->getAttribute();
        $values = $filter->getValues();
        $actual = $template->getAttribute($attribute);
        $needle = (string) ($values[0] ?? '');

        return match ($filter->getMethod()) {
            Query::TYPE_EQUAL => \in_array($actual, $values, false),
            Query::TYPE_NOT_EQUAL => !\in_array($actual, $values, false),
            Query::TYPE_STARTS_WITH => \is_string($actual) && \str_starts_with($actual, $needle),
            Query::TYPE_NOT_STARTS_WITH => \is_string($actual) && !\str_starts_with($actual, $needle),
            Query::TYPE_ENDS_WITH => \is_string($actual) && \str_ends_with($actual, $needle),
            Query::TYPE_NOT_ENDS_WITH => \is_string($actual) && !\str_ends_with($actual, $needle),
            Query::TYPE_CONTAINS => \is_string($actual) && \str_contains($actual, $needle),
            Query::TYPE_NOT_CONTAINS => \is_string($actual) && !\str_contains($actual, $needle),
            Query::TYPE_SEARCH => \is_string($actual) && \stripos($actual, $needle) !== false,
            Query::TYPE_NOT_SEARCH => \is_string($actual) && \stripos($actual, $needle) === false,
            Query::TYPE_IS_NULL => $actual === null || $actual === '',
            Query::TYPE_IS_NOT_NULL => $actual !== null && $actual !== '',
            default => throw new Exception(Exception::GENERAL_QUERY_INVALID, 'Query method not supported for email templates: ' . $filter->getMethod()),
        };
    }

    /**
     * @param array<Document> $templates
     * @param array<string> $orderAttributes
     * @param array<string> $orderTypes
     * @return array<Document>
     */
    private function applyOrder(array $templates, array $orderAttributes, array $orderTypes): array
    {
        if (empty($orderAttributes)) {
            return $templates;
        }

        \usort($templates, function (Document $a, Document $b) use ($orderAttributes, $orderTypes) {
            foreach ($orderAttributes as $index => $attribute) {
                $direction = \strtoupper($orderTypes[$index] ?? Database::ORDER_ASC);
                $valueA = $a->getAttribute($attribute);
                $valueB = $b->getAttribute($attribute);

                $cmp = $valueA <=> $valueB;
                if ($cmp === 0) {
                    continue;
                }

                return $direction === Database::ORDER_DESC ? -$cmp : $cmp;
            }
            return 0;
        });

        return $templates;
    }
}
