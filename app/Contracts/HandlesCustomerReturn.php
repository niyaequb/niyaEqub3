<?php

namespace App\Contracts;

/**
 * A gateway whose customer leaves our app for a hosted checkout and is sent
 * back to /payment/{provider}/return afterwards.
 *
 * Optional on purpose: super-app banks such as Dashen never send a browser
 * back, so they do not implement it and their code is untouched.
 */
interface HandlesCustomerReturn
{
    /**
     * Our reference for the order a returning browser or callback is about, or
     * null when the parameters name nothing we issued.
     *
     * @param  array<string, mixed>  $params  query string and body of the request
     */
    public function referenceFromReturn(array $params): ?string;
}
