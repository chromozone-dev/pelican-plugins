<?php

namespace Chromozone\ReverseProxy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Laravel's alpha_dash is not a hostname check. Its pattern is
 * /\A[\pL\pM\pN_-]+\z/u, which accepts underscores, leading and trailing
 * hyphens, bare combining marks and any Unicode script - none of which are
 * legal in a DNS label, and its maxLength counts characters rather than the 63
 * octets DNS actually allows.
 */
class DnsLabel implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value)) {
            $fail('reverse-proxy::strings.validation.invalid_label')->translate();

            return;
        }

        // RFC 5891 reserves labels with '--' in the third and fourth position for
        // encoded forms. The one that matters here is punycode: 'xn--pnel-53d' is
        // pure ASCII, so it passes every character check, but a browser renders it
        // as 'panel' with a Cyrillic a - a homograph of a blacklisted label, on the
        // operator's own domain and wildcard certificate.
        if (preg_match('/^..--/', $value)) {
            $fail('reverse-proxy::strings.validation.reserved_label')->translate();
        }
    }
}
