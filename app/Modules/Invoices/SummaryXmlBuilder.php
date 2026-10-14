<?php

namespace App\Modules\Invoices;

/**
 * XML de anulación (esquemas SUNAT 1.0/1.1 sobre UBL 2.0): VoidedDocuments para la comunicación
 * de baja de facturas y SummaryDocuments con ConditionCode 3 para anular boletas.
 */
class SummaryXmlBuilder
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const SAC = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1';

    private const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function voided(object $void, object $invoice): string
    {
        [$d, $r] = $this->root('VoidedDocuments', '1.0', $void);
        [$series, $number] = explode('-', $invoice->serial_number, 2);
        $line = $this->el($r, self::SAC, 'sac:VoidedDocumentsLine');
        $this->basic($line, 'LineID', '1');
        $this->basic($line, 'DocumentTypeCode', $invoice->type);
        $this->el($line, self::SAC, 'sac:DocumentSerialID', $series);
        $this->el($line, self::SAC, 'sac:DocumentNumberID', $number);
        $this->el($line, self::SAC, 'sac:VoidReasonDescription', $void->reason);

        return $d->saveXML();
    }

    public function summary(object $void, object $invoice, ?object $reference): string
    {
        [$d, $r] = $this->root('SummaryDocuments', '1.1', $void);
        $line = $this->el($r, self::SAC, 'sac:SummaryDocumentsLine');
        $this->basic($line, 'LineID', '1');
        $this->basic($line, 'DocumentTypeCode', $invoice->type);
        $this->basic($line, 'ID', $invoice->serial_number);
        $customer = $this->el($line, self::CAC, 'cac:AccountingCustomerParty');
        $this->basic($customer, 'CustomerAssignedAccountID', $invoice->customer_document_number);
        $this->basic($customer, 'AdditionalAccountID', $invoice->customer_document_type);
        if ($invoice->referenced_serial_number) {
            $doc = $this->el($this->el($line, self::CAC, 'cac:BillingReference'), self::CAC, 'cac:InvoiceDocumentReference');
            $this->basic($doc, 'ID', $invoice->referenced_serial_number);
            $this->basic($doc, 'DocumentTypeCode', $reference->type ?? '03');
        }
        // 3: anulado.
        $this->basic($this->el($line, self::CAC, 'cac:Status'), 'ConditionCode', '3');
        $this->amount($this->el($line, self::SAC, 'sac:TotalAmount'), $invoice->total);
        // 01: operaciones gravadas.
        $paid = $this->el($line, self::SAC, 'sac:BillingPayment');
        $this->amount($this->basic($paid, 'PaidAmount', ''), $invoice->taxable_amount);
        $this->basic($paid, 'InstructionID', '01');
        $tax = $this->el($line, self::CAC, 'cac:TaxTotal');
        $this->amount($this->basic($tax, 'TaxAmount', ''), $invoice->tax_amount);
        $subtotal = $this->el($tax, self::CAC, 'cac:TaxSubtotal');
        $this->amount($this->basic($subtotal, 'TaxAmount', ''), $invoice->tax_amount);
        $scheme = $this->el($this->el($subtotal, self::CAC, 'cac:TaxCategory'), self::CAC, 'cac:TaxScheme');
        $this->basic($scheme, 'ID', '1000');
        $this->basic($scheme, 'Name', 'IGV');
        $this->basic($scheme, 'TaxTypeCode', 'VAT');

        return $d->saveXML();
    }

    /** @return array{0: \DOMDocument, 1: \DOMElement} */
    private function root(string $name, string $customization, object $void): array
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $r = $d->createElementNS('urn:sunat:names:specification:ubl:peru:schema:xsd:'.$name.'-1', $name);
        $d->appendChild($r);
        foreach (['cbc' => self::CBC, 'cac' => self::CAC, 'sac' => self::SAC, 'ext' => self::EXT] as $prefix => $ns) {
            $r->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $ns);
        }
        $this->el($this->el($this->el($r, self::EXT, 'ext:UBLExtensions'), self::EXT, 'ext:UBLExtension'), self::EXT, 'ext:ExtensionContent');
        $this->basic($r, 'UBLVersionID', '2.0');
        $this->basic($r, 'CustomizationID', $customization);
        $this->basic($r, 'ID', $void->identifier);
        $this->basic($r, 'ReferenceDate', (string) $void->reference_date);
        $this->basic($r, 'IssueDate', (string) $void->issue_date);
        $ruc = (string) config('commerce.sunat.ruc');
        $name = (string) config('commerce.sunat.legal_name');
        $signature = $this->el($r, self::CAC, 'cac:Signature');
        $this->basic($signature, 'ID', $ruc);
        $signatory = $this->el($signature, self::CAC, 'cac:SignatoryParty');
        $this->basic($this->el($signatory, self::CAC, 'cac:PartyIdentification'), 'ID', $ruc);
        $this->basic($this->el($signatory, self::CAC, 'cac:PartyName'), 'Name', $name);
        $this->basic($this->el($this->el($signature, self::CAC, 'cac:DigitalSignatureAttachment'), self::CAC, 'cac:ExternalReference'), 'URI', '#SignatureSP');
        $supplier = $this->el($r, self::CAC, 'cac:AccountingSupplierParty');
        $this->basic($supplier, 'CustomerAssignedAccountID', $ruc);
        $this->basic($supplier, 'AdditionalAccountID', '6');
        $this->basic($this->el($this->el($supplier, self::CAC, 'cac:Party'), self::CAC, 'cac:PartyLegalEntity'), 'RegistrationName', $name);

        return [$d, $r];
    }

    private function el(\DOMElement $parent, string $ns, string $name, ?string $value = null): \DOMElement
    {
        $node = $parent->ownerDocument->createElementNS($ns, $name);
        if ($value !== null) {
            $node->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($node);

        return $node;
    }

    private function basic(\DOMElement $parent, string $name, string $value): \DOMElement
    {
        return $this->el($parent, self::CBC, 'cbc:'.$name, $value === '' ? null : $value);
    }

    private function amount(\DOMElement $node, mixed $value): void
    {
        $node->appendChild($node->ownerDocument->createTextNode(bcadd((string) $value, '0', 2)));
        $node->setAttribute('currencyID', 'PEN');
    }
}
