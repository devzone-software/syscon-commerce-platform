<?php

namespace App\Modules\Invoices;

class UblXmlBuilder
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public function build(object $invoice, array $order): string
    {
        $root = match ($invoice->type) {
            '01','03' => 'Invoice','07' => 'CreditNote','08' => 'DebitNote',default => throw new \InvalidArgumentException('Tipo inválido')
        };
        $d = new \DOMDocument('1.0', 'UTF-8');
        $r = $d->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:'.$root.'-2', $root);
        $d->appendChild($r);
        $r->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $r->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $ext = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
        $extensions = $d->createElementNS($ext, 'ext:UBLExtensions');
        $r->appendChild($extensions);
        $extension = $d->createElementNS($ext, 'ext:UBLExtension');
        $extensions->appendChild($extension);
        $extension->appendChild($d->createElementNS($ext, 'ext:ExtensionContent'));
        $this->basic($r, 'UBLVersionID', '2.1');
        $this->basic($r, 'CustomizationID', '2.0');
        $this->basic($r, 'ID', $invoice->serial_number);
        $this->basic($r, 'IssueDate', now('America/Lima')->toDateString());
        $this->basic($r, $root === 'Invoice' ? 'InvoiceTypeCode' : $root.'TypeCode', $root === 'Invoice' ? $invoice->type : '01');
        $this->basic($r, 'DocumentCurrencyCode', 'PEN');
        if ($invoice->referenced_serial_number) {
            $this->basic($this->agg($this->agg($r, 'BillingReference'), 'InvoiceDocumentReference'), 'ID', $invoice->referenced_serial_number);
        }
        $this->party($r, 'AccountingSupplierParty', config('commerce.sunat.ruc'), '6', config('commerce.sunat.legal_name'));
        $this->party($r, 'AccountingCustomerParty', $invoice->customer_document_number, $invoice->customer_document_type, $invoice->customer_name);
        foreach ($order['details'] as $i => $detail) {
            $line = $this->agg($r, $root.'Line');
            $this->basic($line, 'ID', (string) ($i + 1));
            $this->basic($line, match ($root) {
                'Invoice' => 'InvoicedQuantity','CreditNote' => 'CreditedQuantity',default => 'DebitedQuantity'
            }, (string) $detail['quantity'])->setAttribute('unitCode', 'NIU');
            $this->amount($line, 'LineExtensionAmount', bcmul($detail['unitPrice'], (string) $detail['quantity'], 2));
            $this->basic($this->agg($line, 'Item'), 'Description', $detail['name']);
            $this->amount($this->agg($line, 'Price'), 'PriceAmount', $detail['unitPrice']);
        }
        $this->amount($this->agg($r, $root === 'DebitNote' ? 'RequestedMonetaryTotal' : 'LegalMonetaryTotal'), 'PayableAmount', $order['total']);

        return $d->saveXML();
    }

    private function basic(\DOMElement $parent, string $name, string $value): \DOMElement
    {
        $node = $parent->ownerDocument->createElementNS(self::CBC, 'cbc:'.$name);
        $node->appendChild($parent->ownerDocument->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }

    private function agg(\DOMElement $parent, string $name): \DOMElement
    {
        $node = $parent->ownerDocument->createElementNS(self::CAC, 'cac:'.$name);
        $parent->appendChild($node);

        return $node;
    }

    private function amount(\DOMElement $parent, string $name, string $value): void
    {
        $this->basic($parent, $name, bcadd($value, '0', 2))->setAttribute('currencyID', 'PEN');
    }

    private function party(\DOMElement $parent, string $element, string $number, string $type, string $name): void
    {
        $party = $this->agg($this->agg($parent,$element),'Party');
        $this->basic($this->agg($party,'PartyIdentification'),'ID',$number)->setAttribute('schemeID',$type);
        $this->basic($this->agg($party,'PartyName'),'Name',$name);
    }
}
