<?php

namespace Tests\Feature;

use App\Modules\Invoices\AmountInWords;
use App\Modules\Invoices\SunatFault;
use App\Modules\Invoices\SunatGateway;
use App\Modules\Invoices\UblXmlBuilder;
use Illuminate\Support\Facades\Http;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\TestCase;

class SunatGatewayTest extends TestCase
{
    public function test_pkcs12_signature_can_be_cryptographically_verified(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test SYSCON'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($cert, $bytes, $key, 'certificate-password');
        $path = tempnam(sys_get_temp_dir(), 'cert-');
        file_put_contents($path, $bytes);
        config(['commerce.sunat.certificate' => $path, 'commerce.sunat.certificate_password' => 'certificate-password']);
        try {
            $invoice = (object) ['type' => '01', 'serial_number' => 'F001-1', 'customer_document_number' => '20123456789', 'customer_document_type' => '6', 'customer_name' => 'Test', 'referenced_serial_number' => null, 'created_at' => now(), 'tax_rate' => '0.1800', 'taxable_amount' => '8.47', 'tax_amount' => '1.53', 'total' => '10.00'];
            $xml = app(UblXmlBuilder::class)->build($invoice, [['line_number' => 1, 'sku' => 'SKU-1', 'description' => 'Producto & test', 'unit_code' => 'NIU', 'quantity' => 1, 'unit_price' => '10.00', 'taxable_amount' => '8.47', 'tax_amount' => '1.53']]);
            $signed = app(SunatGateway::class)->sign($xml);
            $doc = new \DOMDocument;
            $doc->loadXML($signed);
            $sig = new XMLSecurityDSig;
            $this->assertSame('SignatureSP', $sig->locateSignature($doc)?->getAttribute('Id'));
            $sig->canonicalizeSignedInfo();
            $this->assertTrue($sig->validateReference());
            $public = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
            openssl_x509_export($cert, $pem);
            $public->loadKey($pem, false, true);
            $this->assertSame(1, $sig->verify($public));
        } finally {
            unlink($path);
        }
    }

    public function test_amount_in_words_follows_spanish_rules(): void
    {
        foreach ([
            '0.50' => 'CERO CON 50/100 SOLES', '1' => 'UNO CON 00/100 SOLES', '16.05' => 'DIECISÉIS CON 05/100 SOLES',
            '100' => 'CIEN CON 00/100 SOLES', '101.10' => 'CIENTO UNO CON 10/100 SOLES', '1001' => 'MIL UNO CON 00/100 SOLES',
            '1858.59' => 'MIL OCHOCIENTOS CINCUENTA Y OCHO CON 59/100 SOLES', '21000' => 'VEINTIÚN MIL CON 00/100 SOLES',
            '31500.99' => 'TREINTA Y UN MIL QUINIENTOS CON 99/100 SOLES', '1000000' => 'UN MILLÓN CON 00/100 SOLES',
            '2100000' => 'DOS MILLONES CIEN MIL CON 00/100 SOLES', '41000001' => 'CUARENTA Y UN MILLONES UNO CON 00/100 SOLES',
        ] as $amount => $words) {
            $this->assertSame($words, AmountInWords::soles((string) $amount));
        }
    }

    private function cdr(string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-cdr-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('R-test.xml', $xml);
        $zip->close();
        try {
            return base64_encode(file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_send_bill_soap_zip_and_cdr_parsing(): void
    {
        config(['commerce.sunat.ruc' => '20123456789', 'commerce.sunat.user' => 'USER', 'commerce.sunat.password' => 'a&b']);
        $cdr = $this->cdr('<ApplicationResponse xmlns:cbc="urn:test"><cbc:ResponseCode>0</cbc:ResponseCode><cbc:Description>Aceptado</cbc:Description></ApplicationResponse>');
        Http::fake(['*' => Http::response('<Envelope><applicationResponse>'.$cdr.'</applicationResponse></Envelope>', 200)]);
        $gateway = app(SunatGateway::class);
        $result = $gateway->sendBill('20123456789-01-F001-1', '<Invoice/>');
        $this->assertSame(['0', 'Aceptado'], $gateway->parseCdr($result));
        Http::assertSent(function ($r) {
            $doc = new \DOMDocument;
            $doc->loadXML($r->body());
            $this->assertSame('20123456789-01-F001-1.zip', $doc->getElementsByTagName('fileName')->item(0)->textContent);
            $this->assertStringContainsString('a&amp;b', $r->body());
            $bytes = base64_decode($doc->getElementsByTagName('contentFile')->item(0)->textContent);
            $path = tempnam(sys_get_temp_dir(), 'soap-');
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            $zip->open($path);
            try {
                $this->assertSame('<Invoice/>', $zip->getFromName('20123456789-01-F001-1.xml'));
            } finally {
                $zip->close();
                unlink($path);
            }

            return true;
        });
    }

    public function test_soap_faults_keep_sunat_code_and_cdr_observations(): void
    {
        config(['commerce.sunat.ruc' => '20123456789', 'commerce.sunat.user' => 'USER', 'commerce.sunat.password' => 'x']);
        Http::fake(['*' => Http::response('<soap-env:Envelope xmlns:soap-env="http://schemas.xmlsoap.org/soap/envelope/"><soap-env:Body><soap-env:Fault><faultcode>soap-env:Client.2335</faultcode><faultstring>El documento electr&#243;nico ingresado ha sido alterado</faultstring></soap-env:Fault></soap-env:Body></soap-env:Envelope>', 500)]);
        try {
            app(SunatGateway::class)->sendBill('20123456789-03-B001-1', '<Invoice/>');
            $this->fail('Se esperaba un SOAP fault');
        } catch (SunatFault $e) {
            $this->assertSame('2335', $e->sunatCode);
            $this->assertTrue($e->rejects());
            $this->assertSame('SUNAT 2335: El documento electrónico ingresado ha sido alterado', $e->getMessage());
        }
        $this->assertFalse((new SunatFault('0111', 'No tiene el perfil'))->rejects());
        $this->assertNull(SunatFault::fromSoap('<html>Bad gateway</html>'));

        $cdr = $this->cdr('<ApplicationResponse xmlns:cbc="urn:test"><cbc:ResponseCode>0</cbc:ResponseCode><cbc:Description>Aceptada</cbc:Description><cbc:Note>4252 - El dato ingresado como atributo no corresponde</cbc:Note></ApplicationResponse>');
        $this->assertSame(['0', 'Aceptada Observaciones: 4252 - El dato ingresado como atributo no corresponde'], app(SunatGateway::class)->parseCdr($cdr));
    }

    public function test_status_cdr_query_reads_code_and_content(): void
    {
        config(['commerce.sunat.ruc' => '20123456789', 'commerce.sunat.user' => 'USER', 'commerce.sunat.password' => 'x', 'commerce.sunat.consult_endpoint' => 'https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService']);
        Http::fake(['*' => Http::response('<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><ns2:getStatusCdrResponse xmlns:ns2="http://service.sunat.gob.pe"><statusCdr><content>UEsDBA==</content><statusCode>0004</statusCode><statusMessage>La constancia existe</statusMessage></statusCdr></ns2:getStatusCdrResponse></S:Body></S:Envelope>', 200)]);
        $gateway = app(SunatGateway::class);
        $this->assertTrue($gateway->canConsult());
        $this->assertSame(['code' => '0004', 'message' => 'La constancia existe', 'cdr' => 'UEsDBA=='], $gateway->statusCdr('01', 'F001-25'));
        Http::assertSent(fn ($r) => str_contains($r->body(), '<serieComprobante>F001</serieComprobante><numeroComprobante>25</numeroComprobante>') && $r->header('SOAPAction')[0] === 'urn:getStatusCdr');
    }

    public function test_cdr_with_external_entities_is_rejected(): void
    {
        $cdr = $this->cdr('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Response><ResponseCode>&xxe;</ResponseCode></Response>');
        $this->expectException(\RuntimeException::class);
        app(SunatGateway::class)->parseCdr($cdr);
    }
}
