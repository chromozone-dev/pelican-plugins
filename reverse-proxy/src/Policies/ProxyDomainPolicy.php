<?php

namespace Chromozone\ReverseProxy\Policies;

use App\Policies\DefaultAdminPolicies;

class ProxyDomainPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'proxy_domain';
}
