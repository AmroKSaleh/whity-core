<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * A recipient must be able to SEE what they are being asked to act on.
 *
 * Acting on a circulation is deliberately unpermissioned: a step names a RULE,
 * the rule resolves to a person, and requiring a permission on top would let a
 * route reach somebody who then cannot answer it. But the three READ routes of
 * the same circulation were gated on `documents:read`, so the recipient that
 * unpermissioned POST exists to serve could not list the routes, see the
 * recipient rows saying it awaited them, or read the trail to confirm their
 * action landed. They could act blindly given an id from somewhere else, which
 * is not a workflow (#1001).
 *
 * WHY THIS TEST READS THE SOURCE RATHER THAN CALLING THE HANDLER. The handler
 * was never the problem. `DocumentRoutingApiHandler::resolveVisibleDocument()`
 * has always applied `DocumentVisibilityPolicy::canView()`, whose recipient
 * clause is exactly right, and two tests in
 * {@see DocumentRoutingApiHandlerRealEngineTest} already pin that behaviour from
 * both sides. Calling the handler here would pass before the fix and after it —
 * a check that cannot fail, asserting something that was never broken.
 *
 * The fact that WAS broken is the route registration, so that is what is read.
 * The same approach {@see \Tests\OpenAPI\RouteCatalogueCompletenessTest} takes,
 * and for the same reason: some invariants live in `public/index.php` and
 * nowhere else.
 */
final class RoutingReadRoutesAreReachableByRecipientsTest extends TestCase
{
    /** The three reads of a circulation, which a recipient must be able to make. */
    private const READ_ROUTES = [
        "/api/documents/{id:\\d+}/routes",
        "/api/documents/{id:\\d+}/trail",
        "/api/documents/{id:\\d+}/recipients",
    ];

    private function indexPhp(): string
    {
        $source = file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertIsString($source, 'Could not read public/index.php');

        return $source;
    }

    /**
     * The full `$router->register(...)` call for one GET route, as written.
     *
     * @return string The registration line, so an assertion can read its arguments.
     */
    private function registrationOf(string $path): string
    {
        $needle = "\$router->register('GET',  '" . $path . "'";
        $source = $this->indexPhp();

        $at = strpos($source, $needle);
        self::assertNotFalse($at, "No GET registration found for {$path} — has the spacing or the path changed?");

        $end = strpos($source, "\n", $at);
        self::assertNotFalse($end);

        return substr($source, $at, $end - $at);
    }

    /**
     * THE FIX. None of the three may carry a permission gate: the handler's own
     * visibility check is the gate, and it is strictly stronger.
     */
    public function testTheThreeReadRoutesCarryNoPermissionGate(): void
    {
        foreach (self::READ_ROUTES as $path) {
            $line = $this->registrationOf($path);

            self::assertStringNotContainsString(
                'CorePermissions::',
                $line,
                "{$path} is gated on a permission again. A recipient who does not hold it can act on "
                . 'this document but cannot see that anything awaits them. The handler already applies '
                . 'DocumentVisibilityPolicy::canView(), which is strictly stronger than any gate here.'
            );
        }
    }

    /**
     * The act route stays unpermissioned. If this ever changes, the read routes
     * above should be reconsidered in the same breath rather than left as the
     * looser half of a pair.
     */
    public function testActingRemainsUnpermissioned(): void
    {
        $source = $this->indexPhp();
        $at = strpos($source, "\$router->register('POST', '/api/documents/{id:\\d+}/routes/{routeId:\\d+}/actions'");
        self::assertNotFalse($at, 'The action route registration moved');

        $end = strpos($source, "\n", $at);
        self::assertNotFalse($end);

        self::assertStringNotContainsString('CorePermissions::', substr($source, $at, $end - $at));
    }

    /**
     * ISSUING a circulation keeps `documents:route`. Removing the read gates is
     * not an argument that the write side is over-gated — putting a document
     * into circulation is an act of authority, and this asserts the fix did not
     * drift into loosening it.
     */
    public function testIssuingACirculationStillRequiresDocumentsRoute(): void
    {
        $source = $this->indexPhp();
        $at = strpos($source, "\$router->register('POST', '/api/documents/{id:\\d+}/routes'");
        self::assertNotFalse($at, 'The route-creation registration moved');

        $end = strpos($source, "\n", $at);
        self::assertNotFalse($end);

        self::assertStringContainsString(
            'CorePermissions::DOCUMENTS_ROUTE',
            substr($source, $at, $end - $at),
            'Issuing a circulation must remain an act of authority'
        );
    }
}
