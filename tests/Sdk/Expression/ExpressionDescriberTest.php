<?php

declare(strict_types=1);

namespace Tests\Sdk\Expression;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Expression\ExpressionDescriber;

/**
 * WHIT-759: the words a person reads on a proposal before approving a rule.
 */
final class ExpressionDescriberTest extends TestCase
{
    private const LABELS = [
        'deposit' => ['en' => 'Deposit', 'ar' => 'العربون'],
        'price' => ['en' => 'Price', 'ar' => 'السعر'],
    ];

    private const DEPOSIT_RULE = ['>=' => [['field' => 'deposit'], ['*' => [['field' => 'price'], 0.3]]]];

    public function testTheDepositRuleInEnglish(): void
    {
        self::assertSame(
            'Deposit is at least (Price × 0.3)',
            ExpressionDescriber::describe(self::DEPOSIT_RULE, 'en', self::LABELS),
        );
    }

    public function testTheDepositRuleInArabicKeepsSymbolsAndWesternDigits(): void
    {
        self::assertSame(
            'العربون لا يقل عن (السعر × 0.3)',
            ExpressionDescriber::describe(self::DEPOSIT_RULE, 'ar', self::LABELS),
        );
    }

    public function testAFieldWithoutALabelIsWrittenFromItsName(): void
    {
        self::assertSame('pickup on is today', ExpressionDescriber::describe(['==' => [['field' => 'pickup_on'], ['today' => []]]]));
    }

    public function testConditionsAndLists(): void
    {
        $expr = [
            'if' => [
                ['and' => [['has_role' => 'shop_manager'], ['>' => [['count' => [['field' => 'lines']]], 2]]]],
                'large',
                'small',
            ],
        ];

        self::assertSame(
            'if (you have the role shop_manager and the number of lines is more than 2) then "large", otherwise "small"',
            ExpressionDescriber::describe($expr, 'en'),
        );
        self::assertSame(
            'إذا (لديك دور shop_manager و عدد lines أكثر من 2) فإن "large"، وإلا "small"',
            ExpressionDescriber::describe($expr, 'ar'),
        );
    }

    public function testSomethingUnrecognisedIsAnEllipsisNotAFailure(): void
    {
        self::assertSame('… is 1', ExpressionDescriber::describe(['==' => [['nope' => []], 1]]));
    }
}
