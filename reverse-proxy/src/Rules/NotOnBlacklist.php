<?php

namespace Chromozone\ReverseProxy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Stops users claiming hostnames that belong to infrastructure, e.g. a user
 * taking panel.example.com out from under the panel itself.
 */
class NotOnBlacklist implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }

        $patterns = array_filter(array_map(
            'trim',
            explode(',', (string) config('reverse-proxy.hostname_blacklist', '')),
        ));

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $value, ignoreCase: true)) {
                $fail('reverse-proxy::strings.validation.on_blacklist')->translate();

                return;
            }
        }
    }
}
