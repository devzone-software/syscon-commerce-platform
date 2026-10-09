<?php

namespace App\Modules\Invoices;

use Illuminate\Support\Facades\Http;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class SunatGateway
{
    public function configured(): bool
    {
        foreach (['ruc', 'user', 'password', 'certificate', 'certificate_password'] as $key) {
            if (! config('commerce.sunat.'.$key)) {
                return false;
            }
        }

        return is_readable(config('commerce.sunat.certificate'));
    }

    public function sign(string $xml): string
    {
        $certs = [];
        $bytes = file_get_contents(config('commerce.sunat.certificate'));
        if (! openssl_pkcs12_read($bytes, $certs, config('commerce.sunat.certificate_password'))) {
            throw new \RuntimeException('Certificado PKCS12 inválido');
        }
        $doc = $this->parseXml($xml);
        $content = $doc->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2', 'ExtensionContent')->item(0);
        if (! $content) {
            throw new \RuntimeException('UBL sin ExtensionContent');
        }
        $sig = new XMLSecurityDSig;
        $sig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $sig->addReference($doc, XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'], ['force_uri' => true]);
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($certs['pkey'], false);
        $sig->sign($key, $content);
        $sig->add509Cert($certs['cert']);

        return $doc->saveXML();
    }

    public function sendBill(string $filename, string $xml): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'sunat-');
        try {
            $zip = new \ZipArchive;
            if ($zip->open($temp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear ZIP');
            }
            $zip->addFromString($filename.'.xml', $xml);
            $zip->close();
            $content = base64_encode(file_get_contents($temp));
        } finally {
            unlink($temp);
        }
        $escape = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $user = $escape(config('commerce.sunat.ruc').config('commerce.sunat.user'));
        $pass = $escape(config('commerce.sunat.password'));
        $name = $escape($filename.'.zip');
        $payload = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe"><soapenv:Header><wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"><wsse:UsernameToken><wsse:Username>'.$user.'</wsse:Username><wsse:Password>'.$pass.'</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header><soapenv:Body><ser:sendBill><fileName>'.$name.'</fileName><contentFile>'.$content.'</contentFile></ser:sendBill></soapenv:Body></soapenv:Envelope>';
        $endpoint = config('commerce.sunat.endpoint');
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('SUNAT requiere HTTPS');
        }
        $response = Http::connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])->withHeaders(['SOAPAction' => 'urn:sendBill'])->withBody($payload, 'text/xml; charset=utf-8')->post($endpoint);
        if ($response->status() !== 200) {
            throw new \RuntimeException('SUNAT HTTP '.$response->status());
        }
        $doc = $this->parseXml($response->body());
        $cdr = $doc->getElementsByTagNameNS('*', 'applicationResponse')->item(0);
        if (! $cdr) {
            throw new \RuntimeException('Respuesta SUNAT sin CDR');
        }

        return trim($cdr->textContent);
    }

    public function parseCdr(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) > 5000000) {
            throw new \RuntimeException('CDR inválido o demasiado grande');
        }
        $temp = tempnam(sys_get_temp_dir(), 'cdr-');
        file_put_contents($temp, $bytes);
        $zip = new \ZipArchive;
        try {
            if ($zip->open($temp) !== true) {
                throw new \RuntimeException('ZIP CDR inválido');
            }
            $xml = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if (str_ends_with(strtolower($info['name']), '.xml')) {
                    if ($info['size'] > 5000000) {
                        throw new \RuntimeException('XML CDR demasiado grande');
                    }
                    $xml = $zip->getFromIndex($i);
                    break;
                }
            }
            if (! is_string($xml)) {
                throw new \RuntimeException('CDR sin XML');
            }
            $doc = $this->parseXml($xml);
            $code = $doc->getElementsByTagNameNS('*', 'ResponseCode')->item(0);
            if (! $code) {
                throw new \RuntimeException('CDR sin código');
            }

            return [$code->textContent, $doc->getElementsByTagNameNS('*', 'Description')->item(0)?->textContent ?? ''];
        } finally {
            if ($zip->status === \ZipArchive::ER_OK) {
                @$zip->close();
            }unlink($temp);
        }
    }

    private function parseXml(string $xml): \DOMDocument
    {
        if (strlen($xml) > 7000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \RuntimeException('XML no permitido');
        }
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $doc->loadXML($xml, LIBXML_NONET)) {
                throw new \RuntimeException('XML inválido');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc;
    }
}
