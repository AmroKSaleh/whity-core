<?php

declare(strict_types=1);

namespace Whity\Core\Money;

use RuntimeException;

/**
 * An arithmetic that money does not permit.
 *
 * Thrown rather than returned, and loudly, because every case it covers is a
 * programming error rather than a user one: adding two currencies, a percentage
 * outside 0–100, a currency code that is not a code. A caller cannot sensibly
 * recover from any of them, and a total that quietly came back wrong is worse
 * than a request that failed.
 */
final class MoneyException extends RuntimeException
{
}
