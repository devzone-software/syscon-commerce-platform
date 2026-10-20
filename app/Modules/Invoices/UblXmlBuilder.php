<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\Tax;
use Illuminate\Support\Carbon;

/**
 * UBL 2.1 de boletas, facturas y notas para operaciones de venta interna gravadas con IGV
 * (afectación 10). Los importes salen de las líneas guardadas del comprobante; aquí no se
 * recalculan, así el XML y la representación impresa coinciden. El orden de los elementos sigue
 * la secuencia del esquema UBL.
 */
class UblXmlBuilder
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /**
     * @param  array<int, array<string, mixed>>  $lines  Filas de invoice_lines en orden.
     * @param  object|null  $reference  Comprobante que modifica una nota.
     */
    public function build(object $invoice, array $lines, ?object $reference = null): string
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
        // La fecha de emisión es la de creación del borrador: no cambia al corregirlo ni al reenviarlo.
        $issued = Carbon::parse($invoice->created_at)->setTimezone('America/Lima');
        $this->basic($r, 'UBLVersionID', '2.1');
        $this->basic($r, 'CustomizationID', '2.0');
        $this->basic($r, 'ID', $invoice->serial_number);
        $this->basic($r, 'IssueDate', $issued->toDateString());
        $this->basic($r, 'IssueTime', $issued->format('H:i:s'));
        if ($root === 'Invoice') {
            // 0101: venta interna.
            $this->basic($r, 'InvoiceTypeCode', $invoice->type)->setAttribute('listID', '0101');
        }
        // Leyenda 1000 (catálogo 52): importe total en letras.
        $this->basic($r, 'Note', AmountInWords::soles((string) $invoice->total))->setAttribute('languageLocaleID', '1000');
        $this->basic($r, 'DocumentCurrencyCode', 'PEN');
        if ($root !== 'Invoice') {
            $reason = $this->agg($r, 'DiscrepancyResponse');
            $this->basic($reason, 'ReferenceID', (string) $invoice->referenced_serial_number);
            if ($invoice->note_code) {
                $this->basic($reason, 'ResponseCode', $invoice->note_code);
            }
            $this->basic($reason, 'Description', (string) ($invoice->note_reason ?? ''));
            $document = $this->agg($this->agg($r, 'BillingReference'), 'InvoiceDocumentReference');
            $this->basic($document, 'ID', (string) $invoice->referenced_serial_number);
            $this->basic($document, 'DocumentTypeCode', $reference->type ?? ($invoice->referenced_serial_number[0] === 'F' ? '01' : '03'));
        }
        $ruc = (string) config('commerce.sunat.ruc');
        $name = (string) config('commerce.sunat.legal_name');
        $signature = $this->agg($r, 'Signature');
        $this->basic($signature, 'ID', $ruc);
        $signatory = $this->agg($signature, 'SignatoryParty');
        $this->basic($this->agg($signatory, 'PartyIdentification'), 'ID', $ruc);
        $this->basic($this->agg($signatory, 'PartyName'), 'Name', $name);
        $this->basic($this->agg($this->agg($signature, 'DigitalSignatureAttachment'), 'ExternalReference'), 'URI', '#SignatureSP');
        $supplier = $this->party($r, 'AccountingSupplierParty', $ruc, '6', $name);
        $this->basic($supplier, 'AddressTypeCode', (string) config('commerce.sunat.establishment_code', '0000'));
        $this->party($r, 'AccountingCustomerParty', $invoice->customer_document_number, $invoice->customer_document_type, $invoice->customer_name);
        if ($invoice->type === '01') {
            // La factura declara su forma de pago; las ventas de la tienda se cobran antes de emitir.
            $terms = $this->agg($r, 'PaymentTerms');
            $this->basic($terms, 'ID', 'FormaPago');
            $this->basic($terms, 'PaymentMeansID', 'Contado');
        }
        $rate = (string) $invoice->tax_rate;
        $this->taxTotal($r, (string) $invoice->taxable_amount, (string) $invoice->tax_amount, $rate, false);
        $totals = $this->agg($r, $root === 'DebitNote' ? 'RequestedMonetaryTotal' : 'LegalMonetaryTotal');
        $this->amount($totals, 'LineExtensionAmount', (string) $invoice->taxable_amount);
        $this->amount($totals, 'TaxInclusiveAmount', (string) $invoice->total);
        $this->amount($totals, 'PayableAmount', (string) $invoice->total);
        foreach ($lines as $l) {
            $line = $this->agg($r, $root.'Line');
            $this->basic($line, 'ID', (string) $l['line_number']);
            $this->basic($line, match ($root) {
                'Invoice' => 'InvoicedQuantity','CreditNote' => 'CreditedQuantity',default => 'DebitedQuantity'
            }, (string) $l['quantity'])->setAttribute('unitCode', $l['unit_code']);
            $this->amount($line, 'LineExtensionAmount', (string) $l['taxable_amount']);
            // Precio unitario que pagó el cliente (con IGV), tipo 01.
            $price = $this->agg($this->agg($line, 'PricingReference'), 'AlternativeConditionPrice');
            $this->amount($price, 'PriceAmount', (string) $l['unit_price']);
            $this->basic($price, 'PriceTypeCode', '01');
            $this->taxTotal($line, (string) $l['taxable_amount'], (string) $l['tax_amount'], $rate, true);
            $item = $this->agg($line, 'Item');
            $this->basic($item, 'Description', $l['description']);
            if ($l['sku']) {
                $this->basic($this->agg($item, 'SellersItemIdentification'), 'ID', $l['sku']);
            }
            // Valor unitario sin IGV.
            $this->basic($this->agg($line, 'Price'), 'PriceAmount', Tax::unitValue((string) $l['unit_price'], $rate))->setAttribute('currencyID', 'PEN');
        }

        return $d->saveXML();
    }

    /** IGV (tributo 1000) del documento o de una línea; la línea además lleva tasa y afectación. */
    private function taxTotal(\DOMElement $parent, string $taxable, string $tax, string $rate, bool $line): void
    {
        $total = $this->agg($parent, 'TaxTotal');
        $this->amount($total, 'TaxAmount', $tax);
        $subtotal = $this->agg($total, 'TaxSubtotal');
        $this->amount($subtotal, 'TaxableAmount', $taxable);
        $this->amount($subtotal, 'TaxAmount', $tax);
        $category = $this->agg($subtotal, 'TaxCategory');
        if ($line) {
            $this->basic($category, 'Percent', rtrim(rtrim(bcmul($rate, '100', 2), '0'), '.'));
            // 10: gravado, operación onerosa.
            $this->basic($category, 'TaxExemptionReasonCode', '10');
        }
        $scheme = $this->agg($category, 'TaxScheme');
        $this->basic($scheme, 'ID', '1000');
        $this->basic($scheme, 'Name', 'IGV');
        $this->basic($scheme, 'TaxTypeCode', 'VAT');
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

    /** @return \DOMElement Dirección registrada de la parte, para completar el emisor. */
    private function party(\DOMElement $parent, string $element, string $number, string $type, string $name): \DOMElement
    {
        $party = $this->agg($this->agg($parent, $element), 'Party');
        $this->basic($this->agg($party, 'PartyIdentification'), 'ID', $number)->setAttribute('schemeID', $type);
        $legal = $this->agg($party, 'PartyLegalEntity');
        $this->basic($legal, 'RegistrationName', $name);

        return $element === 'AccountingSupplierParty' ? $this->agg($legal, 'RegistrationAddress') : $legal;
    }
}
