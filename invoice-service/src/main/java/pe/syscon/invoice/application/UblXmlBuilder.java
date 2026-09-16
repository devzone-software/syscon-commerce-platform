package pe.syscon.invoice.application;

import java.io.StringWriter;
import java.math.*;
import javax.xml.stream.*;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;
import pe.syscon.invoice.domain.Invoice;

@Component
public class UblXmlBuilder {
 private final String ruc;private final String legalName;
 public UblXmlBuilder(@Value("${sunat.ruc:}") String ruc,@Value("${sunat.legal-name:SYSCON}") String legalName) {this.ruc=ruc;this.legalName=legalName;}
 public String build(Invoice invoice,OrderClient.Order order) {
  try {
   String root=switch(invoice.type) {case "01","03"->"Invoice";case "07"->"CreditNote";case "08"->"DebitNote";default->throw new IllegalArgumentException("Tipo inválido");};
   String ns="urn:oasis:names:specification:ubl:schema:xsd:"+root+"-2";
   var output=new StringWriter();var w=XMLOutputFactory.newFactory().createXMLStreamWriter(output);
   w.writeStartDocument("UTF-8","1.0");w.writeStartElement(root);w.writeDefaultNamespace(ns);
   w.writeNamespace("cbc","urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2");
   w.writeNamespace("cac","urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2");
   w.writeNamespace("ext","urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2");
   basic(w,"UBLVersionID","2.1");basic(w,"CustomizationID","2.0");basic(w,"ID",invoice.serialNumber);
   basic(w,"IssueDate",java.time.LocalDate.now(java.time.ZoneId.of("America/Lima")).toString());
   if(root.equals("Invoice")) basic(w,"InvoiceTypeCode",invoice.type);
   else basic(w,root.equals("CreditNote")?"CreditNoteTypeCode":"DebitNoteTypeCode","01");
   basic(w,"DocumentCurrencyCode","PEN");
   if(invoice.referencedSerialNumber!=null) {agg(w,"BillingReference");agg(w,"InvoiceDocumentReference");basic(w,"ID",invoice.referencedSerialNumber);w.writeEndElement();w.writeEndElement();}
   party(w,"AccountingSupplierParty",ruc,"6",legalName);
   party(w,"AccountingCustomerParty",invoice.customerDocumentNumber,invoice.customerDocumentType,invoice.customerName);
   int index=0;
   for(var d:order.details()) {
    w.writeStartElement("cac",root.equals("Invoice")?"InvoiceLine":root.equals("CreditNote")?"CreditNoteLine":"DebitNoteLine","urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2");
    basic(w,"ID",Integer.toString(++index));basic(w,root.equals("Invoice")?"InvoicedQuantity":root.equals("CreditNote")?"CreditedQuantity":"DebitedQuantity",Integer.toString(d.quantity()));
    amount(w,"LineExtensionAmount",d.unitPrice().multiply(BigDecimal.valueOf(d.quantity())));
    agg(w,"Item");basic(w,"Description",d.name());w.writeEndElement();
    agg(w,"Price");amount(w,"PriceAmount",d.unitPrice());w.writeEndElement();
    w.writeEndElement();
   }
   agg(w,"LegalMonetaryTotal");amount(w,"PayableAmount",invoice.total);w.writeEndElement();
   w.writeEndElement();w.writeEndDocument();w.close();return output.toString();
  } catch(XMLStreamException e) {throw new IllegalStateException("No se pudo generar UBL",e);}
 }
 private static void basic(XMLStreamWriter w,String name,String value) throws XMLStreamException {w.writeStartElement("cbc",name,"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2");w.writeCharacters(value);w.writeEndElement();}
 private static void amount(XMLStreamWriter w,String name,BigDecimal value) throws XMLStreamException {w.writeStartElement("cbc",name,"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2");w.writeAttribute("currencyID","PEN");w.writeCharacters(value.setScale(2,RoundingMode.HALF_UP).toPlainString());w.writeEndElement();}
 private static void agg(XMLStreamWriter w,String name) throws XMLStreamException {w.writeStartElement("cac",name,"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2");}
 private static void party(XMLStreamWriter w,String element,String document,String type,String name) throws XMLStreamException {
  agg(w,element);agg(w,"Party");agg(w,"PartyIdentification");basic(w,"ID",document);w.writeEndElement();agg(w,"PartyName");basic(w,"Name",name);w.writeEndElement();w.writeEndElement();w.writeEndElement();
 }
}
