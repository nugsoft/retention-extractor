<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Licensing;

/**
 * A product's own way of switching a client on and off.
 *
 * For the products whose licence state no single column can express. The two
 * built-in strategies write one table — a word in a column, or a date that caps
 * access — and most products need nothing more. PosCream needs more: access is
 * a status on every branch AND on the business, an expired branch must stay off
 * whatever it is told, and an open till stays signed in until its token is
 * deleted. That is knowledge about PosCream, so it lives in PosCream:
 *
 *     'licence' => [
 *         'driver' => \App\Retention\PosCreamLicence::class,
 *     ],
 *
 * The class is resolved from the container, so it may take dependencies.
 *
 * The package still does everything around the write — verifying the
 * signature, dropping stale deliveries, recording what was applied, reporting
 * the state back to NugsoftOS. A driver only answers the two questions below.
 */
interface LicenceDriver
{
    /**
     * Put this client into the state NugsoftOS describes.
     *
     * @return int how many rows hold this client's licence state — 0 meaning
     *             there is no such client here, which is reported as such and
     *             not recorded as applied
     */
    public function apply(LicenceState $licence): int;

    /**
     * Whether this product currently lets that client work.
     *
     * Null when it cannot tell, including when there is no such client, so
     * "no" and "no idea" stay apart.
     */
    public function grantsAccess(string $externalId): ?bool;
}
