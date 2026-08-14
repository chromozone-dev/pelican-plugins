<?php

/**
 * Regression tests for the guards that stop one server's hostname reaching
 * another server's port. See NpmFamilyDriverTest for how to run these.
 */

namespace App\Tests\Unit\ReverseProxy;

use App\Models\Allocation;
use App\Tests\TestCase;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Rules\DnsLabel;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteGuardsTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_dns_label_rule(string $label, bool $shouldPass, string $why): void
    {
        // 'required' is included because that is the real ruleset on the form, and
        // because a custom rule is not "implicit" - Laravel skips it for an empty
        // value, so emptiness is required()'s job rather than DnsLabel's.
        $passes = Validator::make(['label' => $label], ['label' => ['required', new DnsLabel()]])->passes();

        $this->assertSame($shouldPass, $passes, $why);
    }

    /** @return array<string, array{0: string, 1: bool, 2: string}> */
    public static function labels(): array
    {
        return [
            'plain' => ['map', true, 'a normal label must be accepted'],
            'with digits' => ['bluemap2', true, 'digits are legal'],
            'inner hyphen' => ['my-map', true, 'inner hyphens are legal'],
            'max length' => [str_repeat('a', 63), true, '63 characters is the DNS limit'],

            'too long' => [str_repeat('a', 64), false, '64 characters exceeds a DNS label'],
            'underscore' => ['my_map', false, 'alpha_dash allowed underscores, DNS does not'],
            'leading hyphen' => ['-map', false, 'a label may not start with a hyphen'],
            'trailing hyphen' => ['map-', false, 'a label may not end with a hyphen'],
            'uppercase' => ['Map', false, 'labels are normalised to lowercase before validation'],
            'unicode' => ['карта', false, 'non-ASCII never matches the Host header nginx sees'],
            'dot' => ['a.b', false, 'a label cannot contain a dot'],
            'empty' => ['', false, 'empty is not a label'],

            // The homograph case: pure ASCII, so every character check passes,
            // but browsers render it as "panel" with a Cyrillic a.
            'punycode' => ['xn--pnel-53d', false, 'punycode labels can impersonate a blacklisted label'],
            'reserved prefix' => ['ab--cd', false, 'RFC 5891 reserves the ??-- prefix form'],
        ];
    }

    public function test_label_is_lowercased_on_write(): void
    {
        $route = new ProxyRoute();
        $route->label = 'ShOp';

        // Otherwise "Shop" and "shop" both pass the unique index under SQLite and
        // PostgreSQL, and collide once nginx lowercases server_name.
        $this->assertSame('shop', $route->label);
    }

    public function test_route_is_detached_when_its_allocation_changes_server(): void
    {
        $route = new ProxyRoute();
        $route->server_id = 1;
        $route->allocation_id = 50;

        $route->setRelation('allocation', $this->allocationOwnedBy(1));
        $this->assertFalse($route->isDetached(), 'an allocation still owned by the server is not detached');

        // Core detaches with a query-builder update, so no event and no cascade
        // fires - the freed port can then be assigned to a different server.
        $route->setRelation('allocation', $this->allocationOwnedBy(2));
        $this->assertTrue($route->isDetached(), 'an allocation reassigned to another server must be detached');

        $route->setRelation('allocation', $this->allocationOwnedBy(null));
        $this->assertTrue($route->isDetached(), 'an unassigned allocation must be detached');
    }

    /**
     * Set directly rather than mass-assigned: Allocation uses $guarded, and
     * resolving that consults the database schema, which these tests avoid.
     */
    private function allocationOwnedBy(?int $serverId): Allocation
    {
        $allocation = new Allocation();
        $allocation->server_id = $serverId;

        return $allocation;
    }
}
