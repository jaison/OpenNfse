<?php

namespace Nfse\Signer;

interface SignerInterface
{
    /**
     * Sign XML content
     *
     * @param  string  $content  XML content to sign
     * @param  string  $tagname  Tag name to sign (e.g., 'infDPS')
     * @param  string  $mark  Attribute name for ID (default: 'Id')
     * @param  int  $algorithm  OpenSSL algorithm (default: OPENSSL_ALGO_SHA1)
     * @param  array  $canonical  Canonicalization options [exclusive, withComments, xpath, nsPrefixes]
     * @param  string  $rootname  Root element name for validation (optional)
     * @param  array  $options  Additional options (reserved for future use)
     * @return string Signed XML content
     */
    public function sign(string $content, string $tagname, string $mark = 'Id', int $algorithm = \OPENSSL_ALGO_SHA1, array $canonical = [\true, \false, null, null], string $rootname = '', array $options = []): string;
}
