<?php

namespace Chromozone\ReverseProxy\Policies;

use App\Policies\DefaultAdminPolicies;

class ProxyTargetPolicy
{
    use DefaultAdminPolicies;

    protected string $modelName = 'proxy_target';
}
