<?php

namespace Chromozone\ReverseProxy\Contracts;

use Chromozone\ReverseProxy\Enums\RouteType;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Support\DriverStatus;

interface ProxyDriver
{
    /**
     * Verify credentials and detect what we are actually talking to.
     *
     * @throws ProxyDriverException
     */
    public function testConnection(): DriverStatus;

    /**
     * Certificates available for the admin-facing picker.
     *
     * @return array<int, array{id: int, nice_name: string, domain_names: string[], expires_on: string|null}>
     *
     * @throws ProxyDriverException
     */
    public function listCertificates(): array;

    /**
     * Create or update the remote entry, returning its external id.
     *
     * @throws ProxyDriverException
     */
    public function upsertRoute(ProxyRoute $route): string;

    /** @throws ProxyDriverException */
    public function deleteRoute(ProxyRoute $route): void;

    /**
     * Delete by external id, for pruning entries whose local row is already gone.
     * The type is required because proxy hosts and streams are separate resources
     * with separate id spaces.
     *
     * @throws ProxyDriverException
     */
    public function deleteExternal(string $externalId, RouteType $type = RouteType::Http): void;

    /**
     * External id of the entry stamped for this route, if one exists. Used to
     * clean up after a create whose response never arrived.
     *
     * @throws ProxyDriverException
     */
    public function findExternalIdForRoute(int $routeId): ?string;

    /**
     * Remote entries this plugin owns, identified by the meta stamp. Entries
     * without the stamp were made by hand and must never be reported here.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ProxyDriverException
     */
    public function listManagedRoutes(): array;

    /** @return array<string, mixed> */
    public function capabilities(): array;
}
