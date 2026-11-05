<?php
/**
 * This file is part of phloxcz/api-query.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\ApiQuery;

/**
 * One condition of a filter: {field, operator, value}.
 */
final readonly class Filter
{
    public const Equal = '=';
    public const NotEqual = '<>';
    public const Less = '<';
    public const Greater = '>';
    public const LessOrEqual = '<=';
    public const GreaterOrEqual = '>=';
    public const StartsWith = 'startswith';
    public const NotStartsWith = 'notstartswith';
    public const EndsWith = 'endswith';
    public const NotEndsWith = 'notendswith';
    public const Contains = 'contains';
    public const NotContains = 'notcontains';
    public const In = 'in';
    public const NotIn = 'not in';
    public const IsWeek = 'isweek';
    public const ThisWeek = 'thisweek';
    public const Today = 'today';
    public const IsNull = 'is null';
    public const IsNotNull = 'is not null';
    public const IsEmpty = 'is empty';
    public const IsNotEmpty = 'is not empty';

    /** Everything clients can send, including what Kendo UI sends */
    private const Aliases = [
        '=' => self::Equal, '==' => self::Equal, 'eq' => self::Equal, 'isequalto' => self::Equal,
        'equals' => self::Equal, 'equalto' => self::Equal, 'equal' => self::Equal,

        '<>' => self::NotEqual, '!=' => self::NotEqual, 'neq' => self::NotEqual, 'isnotequalto' => self::NotEqual,
        'notequals' => self::NotEqual, 'notequalto' => self::NotEqual, 'notequal' => self::NotEqual, 'ne' => self::NotEqual,

        '<' => self::Less, 'lt' => self::Less, 'islessthan' => self::Less, 'lessthan' => self::Less, 'less' => self::Less,

        '>' => self::Greater, 'gt' => self::Greater, 'isgreaterthan' => self::Greater,
        'greaterthan' => self::Greater, 'greater' => self::Greater,

        '<=' => self::LessOrEqual, 'lte' => self::LessOrEqual, 'islessthanorequalto' => self::LessOrEqual,
        'lessthanequal' => self::LessOrEqual, 'le' => self::LessOrEqual,

        '>=' => self::GreaterOrEqual, 'gte' => self::GreaterOrEqual, 'isgreaterthanorequalto' => self::GreaterOrEqual,
        'greaterthanequal' => self::GreaterOrEqual, 'ge' => self::GreaterOrEqual,

        'starts' => self::StartsWith, 'startswith' => self::StartsWith,
        'doesnotstartwith' => self::NotStartsWith,
        'ends' => self::EndsWith, 'endswith' => self::EndsWith,
        'doesnotendwith' => self::NotEndsWith,
        'containing' => self::Contains, 'contains' => self::Contains, 'substring' => self::Contains, 'substringof' => self::Contains,
        'doesnotcontain' => self::NotContains, 'notcontains' => self::NotContains,

        'in' => self::In, 'not in' => self::NotIn, 'notin' => self::NotIn,

        'isweek' => self::IsWeek, 'thisweek' => self::ThisWeek, 'today' => self::Today,

        'is null' => self::IsNull, 'isnull' => self::IsNull, 'null' => self::IsNull,
        'is not null' => self::IsNotNull, 'isnotnull' => self::IsNotNull, 'notnull' => self::IsNotNull,
        'isempty' => self::IsEmpty, 'isnullorempty' => self::IsEmpty,
        'isnotempty' => self::IsNotEmpty, 'isnotnullorempty' => self::IsNotEmpty,
    ];

    public function __construct(
        public string $field,
        public string $operator = self::Equal,
        public mixed $value = null,
    ) {
    }

    /**
     * The canonical operator; null when there's no such operator.
     */
    public static function normalizeOperator(string $operator): ?string
    {
        return self::Aliases[strtolower(trim($operator))] ?? null;
    }
}
